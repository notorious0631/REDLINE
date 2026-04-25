<?php
/**
 * REDLINE — Alerts & Thresholds API
 * Anomaly detection engine + alert management endpoints.
 * 
 * Actions:
 *   run_checks     — Execute all enabled alert rules against live data
 *   get_history     — Fetch alert history (paginated, filterable)
 *   dismiss         — Dismiss an alert (POST)
 *   mark_read       — Mark alert as read (POST)
 *   update_config   — Update an alert rule's settings (POST)
 *   toggle_config   — Enable/disable an alert rule (POST)
 *   get_configs     — List all alert configurations
 *   get_kpi_snapshot — Current KPI values for dashboard gauges
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// Admin guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// HELPER: Safely fetch a single numeric value
// ═══════════════════════════════════════════════════════════════════════
function safeFloat($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return floatval($stmt->fetchColumn());
    } catch (PDOException $e) {
        return 0;
    }
}

function safeInt($conn, $sql, $params = []) {
    return intval(safeFloat($conn, $sql, $params));
}

// ═══════════════════════════════════════════════════════════════════════
// HELPER: Compute current value of a KPI metric
// ═══════════════════════════════════════════════════════════════════════
function getMetricCurrentValue($conn, $metricKey) {
    $today = date('Y-m-d');
    switch ($metricKey) {
        case 'daily_orders':
            return safeFloat($conn,
                "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?", [$today]);

        case 'daily_gmv':
            return safeFloat($conn,
                "SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = ? AND status != 'cancelled'", [$today]);

        case 'cancel_rate':
            $total = safeInt($conn, "SELECT COUNT(*) FROM orders");
            $cancelled = safeInt($conn, "SELECT COUNT(*) FROM orders WHERE status = 'cancelled'");
            return $total > 0 ? round(($cancelled / $total) * 100, 2) : 0;

        case 'dispute_rate':
            $total = safeInt($conn, "SELECT COUNT(*) FROM orders");
            $disputes = safeInt($conn, "SELECT COUNT(*) FROM order_disputes");
            return $total > 0 ? round(($disputes / $total) * 100, 2) : 0;

        case 'unverified_signups':
            return safeFloat($conn,
                "SELECT COUNT(*) FROM users WHERE role = 'seller' AND is_verified = 0 AND DATE(created_at) = ?", [$today]);

        case 'conversion_rate':
            $listings = safeInt($conn, "SELECT COUNT(*) FROM listings WHERE status = 'active'");
            $orders = safeInt($conn, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?", [$today]);
            return $listings > 0 ? round(($orders / $listings) * 100, 2) : 0;

        case 'avg_rating_drop':
            return safeFloat($conn,
                "SELECT COALESCE(AVG(rating), 5) FROM seller_reviews");

        case 'return_rate':
            $total = safeInt($conn, "SELECT COUNT(*) FROM orders");
            $returned = safeInt($conn, "SELECT COUNT(*) FROM orders WHERE status = 'cancelled'");
            return $total > 0 ? round(($returned / $total) * 100, 2) : 0;

        case 'kyc_pending_backlog':
            return safeFloat($conn,
                "SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'");

        case 'negative_review_ratio':
            $total = safeInt($conn, "SELECT COUNT(*) FROM seller_reviews");
            $negative = safeInt($conn, "SELECT COUNT(*) FROM seller_reviews WHERE rating <= 2");
            return $total > 0 ? round(($negative / $total) * 100, 2) : 0;

        default:
            return 0;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// HELPER: Compute historical baseline (moving average over lookback_days)
// ═══════════════════════════════════════════════════════════════════════
function getMetricBaseline($conn, $metricKey, $lookbackDays) {
    $values = [];
    for ($i = 1; $i <= $lookbackDays; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        switch ($metricKey) {
            case 'daily_orders':
                $values[] = safeFloat($conn,
                    "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?", [$date]);
                break;
            case 'daily_gmv':
                $values[] = safeFloat($conn,
                    "SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = ? AND status != 'cancelled'", [$date]);
                break;
            case 'unverified_signups':
                $values[] = safeFloat($conn,
                    "SELECT COUNT(*) FROM users WHERE role = 'seller' AND is_verified = 0 AND DATE(created_at) = ?", [$date]);
                break;
            case 'conversion_rate':
                $listings = safeInt($conn, "SELECT COUNT(*) FROM listings WHERE status = 'active'");
                $orders = safeFloat($conn,
                    "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?", [$date]);
                $values[] = $listings > 0 ? round(($orders / $listings) * 100, 2) : 0;
                break;
            default:
                // For rate-based metrics (cancel_rate, dispute_rate, etc.)
                // baseline is the current global rate, no daily rollup needed
                return getMetricCurrentValue($conn, $metricKey);
        }
    }
    if (empty($values)) return 0;
    return round(array_sum($values) / count($values), 2);
}

// ═══════════════════════════════════════════════════════════════════════
// HELPER: Check if cooldown period has passed for this metric
// ═══════════════════════════════════════════════════════════════════════
function isCooldownActive($conn, $metricKey, $cooldownHours) {
    try {
        $stmt = $conn->prepare(
            "SELECT fired_at FROM alert_history WHERE metric_key = ? ORDER BY fired_at DESC LIMIT 1"
        );
        $stmt->execute([$metricKey]);
        $lastFired = $stmt->fetchColumn();
        if ($lastFired) {
            $hoursSince = (time() - strtotime($lastFired)) / 3600;
            return $hoursSince < $cooldownHours;
        }
    } catch (PDOException $e) {}
    return false;
}

// ═══════════════════════════════════════════════════════════════════════
// HELPER: Fire an alert (insert into history)
// ═══════════════════════════════════════════════════════════════════════
function fireAlert($conn, $config, $currentVal, $baselineVal, $deviationPct, $message) {
    try {
        $title = generateAlertTitle($config['metric_key'], $config['severity'], $currentVal, $baselineVal);
        $stmt = $conn->prepare(
            "INSERT INTO alert_history (config_id, metric_key, severity, title, message, current_value, threshold_value, baseline_value, deviation_pct)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $config['id'],
            $config['metric_key'],
            $config['severity'],
            $title,
            $message,
            $currentVal,
            $config['threshold_value'],
            $baselineVal,
            $deviationPct
        ]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function generateAlertTitle($metricKey, $severity, $currentVal, $baselineVal) {
    $icons = ['critical' => '🚨', 'warning' => '⚠️', 'info' => 'ℹ️'];
    $icon = $icons[$severity] ?? '⚠️';
    $labels = [
        'daily_orders' => 'Order Volume',
        'daily_gmv' => 'Daily GMV',
        'cancel_rate' => 'Cancellation Rate',
        'dispute_rate' => 'Dispute Rate',
        'unverified_signups' => 'Unverified Seller Signups',
        'conversion_rate' => 'Conversion Rate',
        'avg_rating_drop' => 'Avg Seller Rating',
        'return_rate' => 'Return Rate',
        'kyc_pending_backlog' => 'KYC Backlog',
        'negative_review_ratio' => 'Negative Review Ratio',
    ];
    $label = $labels[$metricKey] ?? ucfirst(str_replace('_', ' ', $metricKey));
    return "$icon $label Alert";
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Run all alert checks (anomaly detection)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'run_checks') {
    $fired = [];
    $checked = 0;
    $skipped = 0;

    try {
        $configs = $conn->query("SELECT * FROM alert_configs WHERE is_enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Could not load configs', 'detail' => $e->getMessage()]);
        exit;
    }

    foreach ($configs as $cfg) {
        $checked++;
        $metricKey = $cfg['metric_key'];
        $threshold = floatval($cfg['threshold_value']);
        $lookback = intval($cfg['lookback_days']);
        $cooldown = intval($cfg['cooldown_hours']);

        // Cooldown check — avoid alert noise
        if (isCooldownActive($conn, $metricKey, $cooldown)) {
            $skipped++;
            continue;
        }

        $currentVal = getMetricCurrentValue($conn, $metricKey);
        $baselineVal = getMetricBaseline($conn, $metricKey, $lookback);
        $deviationPct = 0;
        $shouldFire = false;
        $message = '';

        switch ($cfg['check_type']) {
            case 'moving_avg_deviation':
                // Alert if current value is below baseline by more than threshold%
                if ($baselineVal > 0) {
                    $deviationPct = round((($baselineVal - $currentVal) / $baselineVal) * 100, 2);
                    if ($deviationPct >= $threshold) {
                        $shouldFire = true;
                        $message = "{$cfg['label']} is {$deviationPct}% below its {$lookback}-day average. "
                            . "Current: " . number_format($currentVal, 2) . " | "
                            . "{$lookback}-day avg: " . number_format($baselineVal, 2) . " | "
                            . "Threshold: {$threshold}% deviation.";
                    }
                }
                break;

            case 'drop_pct':
                // Alert if current drops by more than threshold% vs baseline
                if ($baselineVal > 0) {
                    $deviationPct = round((($baselineVal - $currentVal) / $baselineVal) * 100, 2);
                    if ($deviationPct >= $threshold) {
                        $shouldFire = true;
                        $message = "{$cfg['label']} dropped by {$deviationPct}%. "
                            . "Current: " . number_format($currentVal, 2) . " | "
                            . "Baseline: " . number_format($baselineVal, 2) . ".";
                    }
                }
                break;

            case 'spike_pct':
                // Alert if current spikes above baseline by more than threshold%
                if ($baselineVal > 0) {
                    $deviationPct = round((($currentVal - $baselineVal) / $baselineVal) * 100, 2);
                } else if ($currentVal > 0) {
                    $deviationPct = 100;
                }
                if ($deviationPct >= $threshold) {
                    $shouldFire = true;
                    $message = "{$cfg['label']} spiked by {$deviationPct}% above normal. "
                        . "Current: " . number_format($currentVal, 2) . " | "
                        . "Baseline avg: " . number_format($baselineVal, 2) . ". Possible anomaly.";
                }
                break;

            case 'threshold_above':
                // Alert if current value is above threshold
                if ($currentVal >= $threshold) {
                    $shouldFire = true;
                    $deviationPct = $threshold > 0 ? round((($currentVal - $threshold) / $threshold) * 100, 2) : 0;
                    $message = "{$cfg['label']} is at " . number_format($currentVal, 2)
                        . " — exceeds threshold of {$threshold}. Immediate attention required.";
                }
                break;

            case 'threshold_below':
                // Alert if current value drops below threshold
                if ($currentVal < $threshold && $currentVal > 0) {
                    $shouldFire = true;
                    $deviationPct = round((($threshold - $currentVal) / $threshold) * 100, 2);
                    $message = "{$cfg['label']} dropped to " . number_format($currentVal, 2)
                        . " — below threshold of {$threshold}. Review required.";
                }
                break;
        }

        if ($shouldFire) {
            $success = fireAlert($conn, $cfg, $currentVal, $baselineVal, $deviationPct, $message);
            if ($success) {
                $fired[] = [
                    'metric' => $metricKey,
                    'severity' => $cfg['severity'],
                    'current' => $currentVal,
                    'baseline' => $baselineVal,
                    'deviation' => $deviationPct,
                    'message' => $message
                ];
            }
        }
    }

    echo json_encode([
        'status' => 'ok',
        'checked' => $checked,
        'skipped_cooldown' => $skipped,
        'alerts_fired' => count($fired),
        'fired' => $fired,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Get alert history
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'get_history') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(5, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $severity = $_GET['severity'] ?? '';
    $showDismissed = intval($_GET['show_dismissed'] ?? 0);

    $where = [];
    $params = [];
    if ($severity && in_array($severity, ['info', 'warning', 'critical'])) {
        $where[] = "severity = ?";
        $params[] = $severity;
    }
    if (!$showDismissed) {
        $where[] = "is_dismissed = 0";
    }
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $totalStmt = $conn->prepare("SELECT COUNT(*) FROM alert_history $whereClause");
        $totalStmt->execute($params);
        $total = intval($totalStmt->fetchColumn());

        $params2 = $params;
        $stmt = $conn->prepare("SELECT * FROM alert_history $whereClause ORDER BY fired_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params2);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Unread count
        $unread = safeInt($conn, "SELECT COUNT(*) FROM alert_history WHERE is_read = 0 AND is_dismissed = 0");

        echo json_encode([
            'alerts' => $alerts,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'unread' => $unread
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Dismiss alert
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'dismiss' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $conn->prepare("UPDATE alert_history SET is_dismissed = 1 WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'ok']);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Mark alert as read
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $conn->prepare("UPDATE alert_history SET is_read = 1 WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'ok']);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        // Mark all as read
        try {
            $conn->exec("UPDATE alert_history SET is_read = 1 WHERE is_read = 0");
            echo json_encode(['status' => 'ok']);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Update alert config
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'update_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $threshold = floatval($_POST['threshold_value'] ?? 0);
    $lookback = max(1, intval($_POST['lookback_days'] ?? 7));
    $severity = $_POST['severity'] ?? 'warning';
    $cooldown = max(1, intval($_POST['cooldown_hours'] ?? 24));
    $enabled = intval($_POST['is_enabled'] ?? 1);

    if ($id > 0 && in_array($severity, ['info', 'warning', 'critical'])) {
        try {
            $stmt = $conn->prepare(
                "UPDATE alert_configs SET threshold_value = ?, lookback_days = ?, severity = ?, cooldown_hours = ?, is_enabled = ? WHERE id = ?"
            );
            $stmt->execute([$threshold, $lookback, $severity, $cooldown, $enabled, $id]);
            echo json_encode(['status' => 'ok']);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Invalid parameters']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Toggle alert config enabled/disabled
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'toggle_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $conn->prepare("UPDATE alert_configs SET is_enabled = NOT is_enabled WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'ok']);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Get all alert configs
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'get_configs') {
    try {
        $configs = $conn->query("SELECT * FROM alert_configs ORDER BY severity DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($configs);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: KPI Snapshot (current values for all tracked metrics)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'get_kpi_snapshot') {
    $metrics = [
        'daily_orders', 'daily_gmv', 'cancel_rate', 'dispute_rate',
        'unverified_signups', 'conversion_rate', 'avg_rating_drop',
        'return_rate', 'kyc_pending_backlog', 'negative_review_ratio'
    ];
    $snapshot = [];
    foreach ($metrics as $key) {
        $current = getMetricCurrentValue($conn, $key);
        $baseline = getMetricBaseline($conn, $key, 7);
        $deviation = $baseline > 0 ? round((($current - $baseline) / $baseline) * 100, 2) : 0;
        $snapshot[$key] = [
            'current' => $current,
            'baseline_7d' => $baseline,
            'deviation_pct' => $deviation
        ];
    }
    echo json_encode($snapshot);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTION: Get unread alert count (for sidebar badge)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'unread_count') {
    $count = safeInt($conn, "SELECT COUNT(*) FROM alert_history WHERE is_read = 0 AND is_dismissed = 0");
    echo json_encode(['count' => $count]);
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . $action]);
?>
