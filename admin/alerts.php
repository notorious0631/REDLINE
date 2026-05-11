<?php
include 'header.php';

// ═══════════════════════════════════════════════════════════════════════
// Fetch alert configs for the threshold editor
// ═══════════════════════════════════════════════════════════════════════
$alertConfigs = [];
try {
    $alertConfigs = $conn->query("SELECT * FROM alert_configs ORDER BY severity DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ═══════════════════════════════════════════════════════════════════════
// Fetch recent alert history
// ═══════════════════════════════════════════════════════════════════════
$alertHistory = [];
$unreadCount = 0;
try {
    $alertHistory = $conn->query(
        "SELECT * FROM alert_history WHERE is_dismissed = 0 ORDER BY fired_at DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC);
    $unreadCount = intval($conn->query(
        "SELECT COUNT(*) FROM alert_history WHERE is_read = 0 AND is_dismissed = 0"
    )->fetchColumn());
} catch (PDOException $e) {}

// ═══════════════════════════════════════════════════════════════════════
// Summary stats
// ═══════════════════════════════════════════════════════════════════════
$alertStats = ['total_24h' => 0, 'critical_24h' => 0, 'warning_24h' => 0, 'info_24h' => 0, 'total_all' => 0, 'active_rules' => 0];
try {
    $alertStats['total_24h'] = intval($conn->query("SELECT COUNT(*) FROM alert_history WHERE fired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn());
    $alertStats['critical_24h'] = intval($conn->query("SELECT COUNT(*) FROM alert_history WHERE fired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity = 'critical'")->fetchColumn());
    $alertStats['warning_24h'] = intval($conn->query("SELECT COUNT(*) FROM alert_history WHERE fired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity = 'warning'")->fetchColumn());
    $alertStats['info_24h'] = intval($conn->query("SELECT COUNT(*) FROM alert_history WHERE fired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND severity = 'info'")->fetchColumn());
    $alertStats['total_all'] = intval($conn->query("SELECT COUNT(*) FROM alert_history")->fetchColumn());
    $alertStats['active_rules'] = intval($conn->query("SELECT COUNT(*) FROM alert_configs WHERE is_enabled = 1")->fetchColumn());
} catch (PDOException $e) {}

// ═══════════════════════════════════════════════════════════════════════
// Handle inline config updates (POST)
// ═══════════════════════════════════════════════════════════════════════
$updateSuccess = '';
$updateError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_rule'])) {
        $ruleId = intval($_POST['rule_id'] ?? 0);
        $threshold = floatval($_POST['threshold_value'] ?? 0);
        $lookback = max(1, intval($_POST['lookback_days'] ?? 7));
        $severity = $_POST['severity'] ?? 'warning';
        $cooldown = max(1, intval($_POST['cooldown_hours'] ?? 24));
        $enabled = isset($_POST['is_enabled']) ? 1 : 0;

        if ($ruleId > 0 && in_array($severity, ['info', 'warning', 'critical'])) {
            try {
                $stmt = $conn->prepare("UPDATE alert_configs SET threshold_value = ?, lookback_days = ?, severity = ?, cooldown_hours = ?, is_enabled = ? WHERE id = ?");
                $stmt->execute([$threshold, $lookback, $severity, $cooldown, $enabled, $ruleId]);
                $updateSuccess = "Alert rule updated successfully.";
                // Refresh configs
                $alertConfigs = $conn->query("SELECT * FROM alert_configs ORDER BY severity DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                logError('admin_alerts', 'Failed to update rule', $e);
                $updateError = "Failed to update rule. Please try again.";
            }
        }
    }
    if (isset($_POST['run_scan'])) {
        // Trigger scan via redirect to avoid form resubmit
        header('Location: alerts.php?scan=1');
        exit;
    }
}

// Handle scan trigger
$scanResult = null;
if (isset($_GET['scan'])) {
    // Run alert checks inline
    $scanResult = ['fired' => [], 'checked' => 0, 'skipped' => 0];
    try {
        $configs = $conn->query("SELECT * FROM alert_configs WHERE is_enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $configs = []; }

    foreach ($configs as $cfg) {
        $scanResult['checked']++;
        $mk = $cfg['metric_key'];
        $th = floatval($cfg['threshold_value']);
        $lb = intval($cfg['lookback_days']);
        $cd = intval($cfg['cooldown_hours']);

        // Cooldown check
        try {
            $lastFired = $conn->prepare("SELECT fired_at FROM alert_history WHERE metric_key = ? ORDER BY fired_at DESC LIMIT 1");
            $lastFired->execute([$mk]);
            $lf = $lastFired->fetchColumn();
            if ($lf && ((time() - strtotime($lf)) / 3600) < $cd) { $scanResult['skipped']++; continue; }
        } catch (PDOException $e) {}

        // Get current value (simplified for inline)
        $cv = 0;
        $today = date('Y-m-d');
        switch ($mk) {
            case 'daily_orders':
                $cv = floatval($conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = '$today'")->fetchColumn()); break;
            case 'daily_gmv':
                $cv = floatval($conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = '$today' AND status != 'cancelled'")->fetchColumn()); break;
            case 'cancel_rate':
                $tot = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                $can = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn());
                $cv = $tot > 0 ? round(($can / $tot) * 100, 2) : 0; break;
            case 'dispute_rate':
                $tot = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                $dis = intval($conn->query("SELECT COUNT(*) FROM order_disputes")->fetchColumn());
                $cv = $tot > 0 ? round(($dis / $tot) * 100, 2) : 0; break;
            case 'unverified_signups':
                $cv = floatval($conn->query("SELECT COUNT(*) FROM users WHERE role='seller' AND is_verified=0 AND DATE(created_at)='$today'")->fetchColumn()); break;
            case 'conversion_rate':
                $al = intval($conn->query("SELECT COUNT(*) FROM listings WHERE status='active'")->fetchColumn());
                $ao = floatval($conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)='$today'")->fetchColumn());
                $cv = $al > 0 ? round(($ao / $al) * 100, 2) : 0; break;
            case 'avg_rating_drop':
                $cv = floatval($conn->query("SELECT COALESCE(AVG(rating),5) FROM seller_reviews")->fetchColumn()); break;
            case 'return_rate':
                $tot = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                $ret = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn());
                $cv = $tot > 0 ? round(($ret / $tot) * 100, 2) : 0; break;
            case 'kyc_pending_backlog':
                $cv = floatval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status='pending'")->fetchColumn()); break;
            case 'negative_review_ratio':
                $tot = intval($conn->query("SELECT COUNT(*) FROM seller_reviews")->fetchColumn());
                $neg = intval($conn->query("SELECT COUNT(*) FROM seller_reviews WHERE rating<=2")->fetchColumn());
                $cv = $tot > 0 ? round(($neg / $tot) * 100, 2) : 0; break;
        }

        // Get baseline
        $bv = 0;
        $dvs = [];
        for ($i = 1; $i <= $lb; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            switch ($mk) {
                case 'daily_orders':
                    $dvs[] = floatval($conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)='$d'")->fetchColumn()); break;
                case 'daily_gmv':
                    $dvs[] = floatval($conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)='$d' AND status!='cancelled'")->fetchColumn()); break;
                case 'unverified_signups':
                    $dvs[] = floatval($conn->query("SELECT COUNT(*) FROM users WHERE role='seller' AND is_verified=0 AND DATE(created_at)='$d'")->fetchColumn()); break;
                default: $dvs[] = $cv; break;
            }
        }
        $bv = !empty($dvs) ? round(array_sum($dvs) / count($dvs), 2) : $cv;

        $dp = 0; $shouldFire = false; $msg = '';
        switch ($cfg['check_type']) {
            case 'moving_avg_deviation':
                if ($bv > 0) { $dp = round((($bv - $cv) / $bv) * 100, 2); if ($dp >= $th) { $shouldFire = true; $msg = "{$cfg['label']}: {$dp}% below {$lb}-day avg. Current: {$cv}, Avg: {$bv}"; } } break;
            case 'drop_pct':
                if ($bv > 0) { $dp = round((($bv - $cv) / $bv) * 100, 2); if ($dp >= $th) { $shouldFire = true; $msg = "{$cfg['label']}: dropped {$dp}%. Current: {$cv}, Baseline: {$bv}"; } } break;
            case 'spike_pct':
                if ($bv > 0) { $dp = round((($cv - $bv) / $bv) * 100, 2); } elseif ($cv > 0) { $dp = 100; }
                if ($dp >= $th) { $shouldFire = true; $msg = "{$cfg['label']}: spiked {$dp}% above normal. Current: {$cv}, Avg: {$bv}"; } break;
            case 'threshold_above':
                if ($cv >= $th) { $shouldFire = true; $dp = $th > 0 ? round((($cv - $th) / $th) * 100, 2) : 0; $msg = "{$cfg['label']}: at {$cv}, exceeds threshold ({$th})."; } break;
            case 'threshold_below':
                if ($cv < $th && $cv > 0) { $shouldFire = true; $dp = round((($th - $cv) / $th) * 100, 2); $msg = "{$cfg['label']}: at {$cv}, below threshold ({$th})."; } break;
        }

        if ($shouldFire) {
            $sevEmojis = ['critical' => '🚨', 'warning' => '⚠️', 'info' => 'ℹ️'];
            $title = ($sevEmojis[$cfg['severity']] ?? '⚠️') . ' ' . $cfg['label'] . ' Alert';
            try {
                $ins = $conn->prepare("INSERT INTO alert_history (config_id, metric_key, severity, title, message, current_value, threshold_value, baseline_value, deviation_pct) VALUES (?,?,?,?,?,?,?,?,?)");
                $ins->execute([$cfg['id'], $mk, $cfg['severity'], $title, $msg, $cv, $th, $bv, $dp]);
                $scanResult['fired'][] = ['metric' => $mk, 'severity' => $cfg['severity'], 'message' => $msg];
            } catch (PDOException $e) {}
        }
    }

    // Refresh history after scan
    try {
        $alertHistory = $conn->query("SELECT * FROM alert_history WHERE is_dismissed = 0 ORDER BY fired_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $unreadCount = intval($conn->query("SELECT COUNT(*) FROM alert_history WHERE is_read = 0 AND is_dismissed = 0")->fetchColumn());
    } catch (PDOException $e) {}
}
?>

<!-- Alerts Page Styles -->
<style>
/* ═══ Page Layout ═══ */
.alerts-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-bottom: 28px; flex-wrap: wrap; gap: 14px;
}
.alerts-header h1 { font-family: 'Cinzel', serif; font-size: 1.5rem; font-weight: 800; }
.alerts-header .page-subtitle { font-size: 0.82rem; color: var(--admin-muted); margin-top: 2px; }
.alerts-header-actions { display: flex; gap: 10px; align-items: center; }

/* ═══ Stat Capsules (top summary) ═══ */
.alert-capsules {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 28px;
}
.alert-capsule {
    background: var(--admin-card-bg); border: 1px solid var(--admin-border);
    border-radius: 12px; padding: 18px 16px; display: flex; align-items: center; gap: 14px;
    transition: border-color 0.2s, transform 0.2s;
}
.alert-capsule:hover { border-color: rgba(255,255,255,0.1); transform: translateY(-1px); }
.ac-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0;
}
.ac-icon.red { background: rgba(229,57,53,0.15); color: #e57373; }
.ac-icon.orange { background: rgba(255,183,77,0.15); color: #ffb74d; }
.ac-icon.blue { background: rgba(66,165,245,0.15); color: #64b5f6; }
.ac-icon.green { background: rgba(76,175,80,0.15); color: #81c784; }
.ac-icon.purple { background: rgba(171,71,188,0.15); color: #ce93d8; }
.ac-val { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.ac-label { font-size: 0.68rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

/* ═══ Two-Column Layout ═══ */
.alerts-layout {
    display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px;
}
.alerts-layout.full { grid-template-columns: 1fr; }

/* ═══ Panel ═══ */
.alerts-panel {
    background: var(--admin-card-bg); border: 1px solid var(--admin-border);
    border-radius: 14px; overflow: hidden;
}
.alerts-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--admin-border);
    font-weight: 700; font-size: 0.88rem;
}
.alerts-panel-body { padding: 0; }
.alerts-panel-body.padded { padding: 20px; }

/* ═══ Alert Feed ═══ */
.alert-feed { max-height: 520px; overflow-y: auto; }
.alert-feed-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.03);
    transition: background 0.15s; position: relative;
}
.alert-feed-item:hover { background: rgba(255,255,255,0.02); }
.alert-feed-item.unread { border-left: 3px solid; }
.alert-feed-item.unread.critical { border-left-color: #e53935; }
.alert-feed-item.unread.warning { border-left-color: #ffb74d; }
.alert-feed-item.unread.info { border-left-color: #64b5f6; }

.afi-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 0.9rem;
}
.afi-icon.critical { background: rgba(229,57,53,0.12); color: #e57373; }
.afi-icon.warning { background: rgba(255,183,77,0.12); color: #ffb74d; }
.afi-icon.info { background: rgba(66,165,245,0.12); color: #64b5f6; }

.afi-body { flex: 1; min-width: 0; }
.afi-title { font-weight: 700; font-size: 0.84rem; margin-bottom: 3px; display: flex; align-items: center; gap: 8px; }
.afi-title .sev-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.sev-dot.critical { background: #e53935; box-shadow: 0 0 6px rgba(229,57,53,0.5); animation: sevPulse 2s ease-in-out infinite; }
.sev-dot.warning { background: #ffb74d; }
.sev-dot.info { background: #64b5f6; }
@keyframes sevPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

.afi-message { font-size: 0.78rem; color: var(--admin-muted); line-height: 1.45; }
.afi-meta { display: flex; align-items: center; gap: 12px; margin-top: 6px; font-size: 0.68rem; color: rgba(255,255,255,0.25); }
.afi-meta .sev-badge {
    font-size: 0.58rem; font-weight: 800; padding: 2px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: 0.06em;
}
.sev-badge.critical { background: rgba(229,57,53,0.12); color: #e57373; }
.sev-badge.warning { background: rgba(255,183,77,0.12); color: #ffb74d; }
.sev-badge.info { background: rgba(66,165,245,0.12); color: #64b5f6; }

.afi-values { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.afi-val-chip {
    font-size: 0.65rem; padding: 3px 10px; border-radius: 6px;
    background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06);
    display: inline-flex; align-items: center; gap: 4px;
}
.afi-val-chip .chip-label { color: var(--admin-muted); }
.afi-val-chip .chip-val { font-weight: 700; }

.afi-actions { flex-shrink: 0; display: flex; flex-direction: column; gap: 4px; align-items: flex-end; }
.afi-dismiss-btn {
    background: none; border: none; color: var(--admin-muted); cursor: pointer;
    font-size: 0.7rem; padding: 4px 8px; border-radius: 6px; transition: all 0.15s;
}
.afi-dismiss-btn:hover { color: #e57373; background: rgba(229,57,53,0.08); }

/* ═══ KPI Live Gauges ═══ */
.kpi-gauges { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 16px 20px; }
.kpi-gauge {
    padding: 14px; border-radius: 10px;
    background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04);
    transition: border-color 0.2s;
}
.kpi-gauge:hover { border-color: rgba(255,255,255,0.08); }
.kpi-gauge-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.kpi-gauge-label { font-size: 0.68rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; }
.kpi-gauge-value { font-size: 1.3rem; font-weight: 800; margin-bottom: 6px; }
.kpi-gauge-bar { height: 4px; background: rgba(255,255,255,0.06); border-radius: 2px; overflow: hidden; }
.kpi-gauge-bar-fill { height: 100%; border-radius: 2px; transition: width 0.8s ease; }
.kpi-gauge-footer { display: flex; justify-content: space-between; margin-top: 6px; font-size: 0.62rem; }
.kpi-gauge-footer .kgf-baseline { color: var(--admin-muted); }
.kpi-gauge-footer .kgf-delta { font-weight: 700; }
.kgf-delta.up { color: #81c784; }
.kgf-delta.down { color: #e57373; }
.kgf-delta.flat { color: #ffb74d; }

/* ═══ Threshold Config Table ═══ */
.config-table { width: 100%; border-collapse: collapse; }
.config-table th {
    text-align: left; padding: 10px 14px; font-size: 0.62rem;
    text-transform: uppercase; letter-spacing: 0.1em; color: var(--admin-muted);
    border-bottom: 1px solid var(--admin-border); white-space: nowrap;
}
.config-table td {
    padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.03);
    font-size: 0.82rem; vertical-align: middle;
}
.config-table tbody tr:hover { background: rgba(255,255,255,0.02); }
.config-table .ct-label { font-weight: 600; }
.config-table .ct-desc { font-size: 0.7rem; color: var(--admin-muted); margin-top: 2px; max-width: 250px; }
.config-table .ct-type {
    font-size: 0.6rem; font-weight: 700; padding: 2px 8px; border-radius: 12px;
    text-transform: uppercase; letter-spacing: 0.04em; display: inline-block;
    background: rgba(255,255,255,0.04); color: var(--admin-muted);
}

/* Config inline inputs */
.ct-input {
    background: var(--admin-bg); border: 1px solid var(--admin-border);
    color: var(--admin-text); border-radius: 6px; padding: 6px 10px;
    font-family: 'Poppins', sans-serif; font-size: 0.78rem; width: 80px;
    transition: border-color 0.2s;
}
.ct-input:focus { border-color: var(--admin-accent); outline: none; }
.ct-select {
    background: var(--admin-bg); border: 1px solid var(--admin-border);
    color: var(--admin-text); border-radius: 6px; padding: 6px 8px;
    font-family: 'Poppins', sans-serif; font-size: 0.72rem;
}
.ct-select:focus { border-color: var(--admin-accent); outline: none; }

/* Toggle switch */
.toggle-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.08); border-radius: 20px; transition: 0.3s;
}
.toggle-slider:before {
    position: absolute; content: ""; height: 14px; width: 14px;
    left: 3px; bottom: 3px; background: var(--admin-muted);
    border-radius: 50%; transition: 0.3s;
}
.toggle-switch input:checked + .toggle-slider { background: rgba(76,175,80,0.3); }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(16px); background: #81c784; }

/* Save button */
.ct-save-btn {
    background: var(--admin-accent); color: #fff; border: none; border-radius: 6px;
    padding: 5px 12px; font-size: 0.7rem; font-weight: 600; cursor: pointer;
    font-family: 'Poppins', sans-serif; transition: all 0.15s;
}
.ct-save-btn:hover { background: var(--admin-accent-hover); }

/* ═══ Scan Banner ═══ */
.scan-banner {
    padding: 14px 20px; border-radius: 10px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 12px; font-size: 0.85rem;
}
.scan-banner.success { background: rgba(76,175,80,0.08); border: 1px solid rgba(76,175,80,0.2); color: #81c784; }
.scan-banner.neutral { background: rgba(66,165,245,0.08); border: 1px solid rgba(66,165,245,0.2); color: #64b5f6; }
.scan-banner i { font-size: 1.1rem; }

/* ═══ Empty State ═══ */
.alerts-empty { text-align: center; padding: 50px 20px; color: var(--admin-muted); }
.alerts-empty i { font-size: 2.5rem; opacity: 0.15; margin-bottom: 12px; display: block; }
.alerts-empty p { font-size: 0.82rem; }

/* ═══ Responsive ═══ */
@media (max-width: 1200px) {
    .alert-capsules { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
    .alert-capsules { grid-template-columns: repeat(2, 1fr); }
    .alerts-layout { grid-template-columns: 1fr; }
    .kpi-gauges { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .alert-capsules { grid-template-columns: 1fr; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE HEADER -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="alerts-header">
    <div>
        <h1><i class="fas fa-bell" style="color: var(--admin-orange); margin-right: 8px;"></i>Alerts & Thresholds</h1>
        <p class="page-subtitle">Anomaly detection, configurable thresholds & real-time KPI monitoring</p>
    </div>
    <div class="alerts-header-actions">
        <span style="font-size: 0.75rem; color: var(--admin-muted);"><i class="fas fa-clock"></i> <?php echo date('M d, Y — h:i A'); ?></span>
        <form method="POST" style="display:inline;">
            <button type="submit" name="run_scan" class="btn-admin red" id="btnRunScan">
                <i class="fas fa-radar" style="margin-right: 4px;"></i> Run Scan Now
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- SCAN RESULT BANNER -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<?php if ($scanResult !== null): ?>
<div class="scan-banner <?php echo !empty($scanResult['fired']) ? 'success' : 'neutral'; ?>">
    <i class="fas fa-<?php echo !empty($scanResult['fired']) ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
    <span>
        Scan complete — <strong><?php echo $scanResult['checked']; ?></strong> rules checked,
        <strong><?php echo count($scanResult['fired']); ?></strong> alert<?php echo count($scanResult['fired']) !== 1 ? 's' : ''; ?> fired,
        <strong><?php echo $scanResult['skipped']; ?></strong> skipped (cooldown).
    </span>
</div>
<?php endif; ?>

<?php if ($updateSuccess): ?>
<div class="scan-banner success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($updateSuccess); ?></div>
<?php endif; ?>
<?php if ($updateError): ?>
<div class="scan-banner" style="background: rgba(229,57,53,0.08); border: 1px solid rgba(229,57,53,0.2); color: #e57373;"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($updateError); ?></div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- SUMMARY CAPSULES -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="alert-capsules">
    <div class="alert-capsule">
        <div class="ac-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="ac-val"><?php echo $alertStats['critical_24h']; ?></div><div class="ac-label">Critical (24h)</div></div>
    </div>
    <div class="alert-capsule">
        <div class="ac-icon orange"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="ac-val"><?php echo $alertStats['warning_24h']; ?></div><div class="ac-label">Warnings (24h)</div></div>
    </div>
    <div class="alert-capsule">
        <div class="ac-icon blue"><i class="fas fa-info-circle"></i></div>
        <div><div class="ac-val"><?php echo $alertStats['info_24h']; ?></div><div class="ac-label">Info (24h)</div></div>
    </div>
    <div class="alert-capsule">
        <div class="ac-icon green"><i class="fas fa-cogs"></i></div>
        <div><div class="ac-val"><?php echo $alertStats['active_rules']; ?></div><div class="ac-label">Active Rules</div></div>
    </div>
    <div class="alert-capsule">
        <div class="ac-icon purple"><i class="fas fa-bell"></i></div>
        <div><div class="ac-val"><?php echo $unreadCount; ?></div><div class="ac-label">Unread Alerts</div></div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW: Alert Feed + KPI Live Gauges -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="alerts-layout">

    <!-- Alert Feed -->
    <div class="alerts-panel">
        <div class="alerts-panel-header">
            <span><i class="fas fa-stream" style="margin-right: 6px; color: #ffb74d;"></i> Live Alert Feed</span>
            <?php if ($unreadCount > 0): ?>
            <form method="POST" action="../api/alerts.php?action=mark_read" style="display:inline;">
                <button type="submit" style="background:none; border:none; color:var(--admin-muted); font-size:0.72rem; cursor:pointer; font-family:'Poppins',sans-serif;">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="alerts-panel-body">
            <div class="alert-feed" id="alertFeed">
                <?php if (!empty($alertHistory)): ?>
                    <?php foreach ($alertHistory as $ah): ?>
                    <div class="alert-feed-item <?php echo !$ah['is_read'] ? 'unread ' . $ah['severity'] : ''; ?>" id="alert-<?php echo $ah['id']; ?>">
                        <div class="afi-icon <?php echo $ah['severity']; ?>">
                            <i class="fas fa-<?php echo $ah['severity'] === 'critical' ? 'radiation' : ($ah['severity'] === 'warning' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                        </div>
                        <div class="afi-body">
                            <div class="afi-title">
                                <span class="sev-dot <?php echo $ah['severity']; ?>"></span>
                                <?php echo htmlspecialchars($ah['title']); ?>
                            </div>
                            <div class="afi-message"><?php echo htmlspecialchars($ah['message']); ?></div>
                            <div class="afi-values">
                                <?php if ($ah['current_value'] !== null): ?>
                                <span class="afi-val-chip">
                                    <span class="chip-label">Current:</span>
                                    <span class="chip-val"><?php echo number_format($ah['current_value'], 2); ?></span>
                                </span>
                                <?php endif; ?>
                                <?php if ($ah['baseline_value'] !== null && $ah['baseline_value'] > 0): ?>
                                <span class="afi-val-chip">
                                    <span class="chip-label">Baseline:</span>
                                    <span class="chip-val"><?php echo number_format($ah['baseline_value'], 2); ?></span>
                                </span>
                                <?php endif; ?>
                                <?php if ($ah['deviation_pct'] !== null): ?>
                                <span class="afi-val-chip">
                                    <span class="chip-label">Deviation:</span>
                                    <span class="chip-val" style="color: <?php echo abs($ah['deviation_pct']) > 20 ? '#e57373' : '#ffb74d'; ?>;"><?php echo $ah['deviation_pct']; ?>%</span>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="afi-meta">
                                <span class="sev-badge <?php echo $ah['severity']; ?>"><?php echo strtoupper($ah['severity']); ?></span>
                                <span><i class="far fa-clock"></i> <?php echo date('M d, h:i A', strtotime($ah['fired_at'])); ?></span>
                                <span><?php echo htmlspecialchars($ah['metric_key']); ?></span>
                            </div>
                        </div>
                        <div class="afi-actions">
                            <button class="afi-dismiss-btn" onclick="dismissAlert(<?php echo $ah['id']; ?>)" title="Dismiss">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alerts-empty">
                        <i class="fas fa-bell-slash"></i>
                        <p>No active alerts. All systems normal.</p>
                        <p style="margin-top: 8px; font-size: 0.75rem;">Click "Run Scan Now" to check all metrics against configured thresholds.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KPI Live Gauges -->
    <div class="alerts-panel">
        <div class="alerts-panel-header">
            <span><i class="fas fa-tachometer-alt" style="margin-right: 6px; color: #64b5f6;"></i> KPI Live Monitor</span>
            <button onclick="refreshKPIs()" style="background:none; border:none; color:var(--admin-muted); font-size:0.72rem; cursor:pointer; font-family:'Poppins',sans-serif;">
                <i class="fas fa-sync-alt" id="kpiRefreshIcon"></i> Refresh
            </button>
        </div>
        <div class="alerts-panel-body">
            <div class="kpi-gauges" id="kpiGauges">
                <?php
                // Pre-compute KPI data for initial render
                $kpiDefs = [
                    ['key' => 'daily_orders', 'label' => 'Daily Orders', 'unit' => '', 'max' => 50, 'invert' => false],
                    ['key' => 'daily_gmv', 'label' => 'Daily GMV', 'unit' => 'Rs.', 'max' => 50000, 'invert' => false],
                    ['key' => 'cancel_rate', 'label' => 'Cancel Rate', 'unit' => '%', 'max' => 100, 'invert' => true],
                    ['key' => 'dispute_rate', 'label' => 'Dispute Rate', 'unit' => '%', 'max' => 100, 'invert' => true],
                    ['key' => 'conversion_rate', 'label' => 'Conversion Rate', 'unit' => '%', 'max' => 100, 'invert' => false],
                    ['key' => 'avg_rating_drop', 'label' => 'Avg Rating', 'unit' => '★', 'max' => 5, 'invert' => false],
                    ['key' => 'negative_review_ratio', 'label' => 'Negative Reviews', 'unit' => '%', 'max' => 100, 'invert' => true],
                    ['key' => 'kyc_pending_backlog', 'label' => 'KYC Backlog', 'unit' => '', 'max' => 50, 'invert' => true],
                    ['key' => 'unverified_signups', 'label' => 'Unverified Signups', 'unit' => '', 'max' => 20, 'invert' => true],
                    ['key' => 'return_rate', 'label' => 'Return Rate', 'unit' => '%', 'max' => 100, 'invert' => true],
                ];

                foreach ($kpiDefs as $kpi) {
                    // Compute current value inline
                    $cv = 0;
                    $today = date('Y-m-d');
                    switch ($kpi['key']) {
                        case 'daily_orders': $cv = floatval($conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)='$today'")->fetchColumn()); break;
                        case 'daily_gmv': $cv = floatval($conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)='$today' AND status!='cancelled'")->fetchColumn()); break;
                        case 'cancel_rate':
                            $t = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                            $c = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn());
                            $cv = $t > 0 ? round(($c/$t)*100, 2) : 0; break;
                        case 'dispute_rate':
                            $t = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                            $d = intval($conn->query("SELECT COUNT(*) FROM order_disputes")->fetchColumn());
                            $cv = $t > 0 ? round(($d/$t)*100, 2) : 0; break;
                        case 'conversion_rate':
                            $l = intval($conn->query("SELECT COUNT(*) FROM listings WHERE status='active'")->fetchColumn());
                            $o = floatval($conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)='$today'")->fetchColumn());
                            $cv = $l > 0 ? round(($o/$l)*100, 2) : 0; break;
                        case 'avg_rating_drop': $cv = floatval($conn->query("SELECT COALESCE(AVG(rating),5) FROM seller_reviews")->fetchColumn()); break;
                        case 'negative_review_ratio':
                            $t = intval($conn->query("SELECT COUNT(*) FROM seller_reviews")->fetchColumn());
                            $n = intval($conn->query("SELECT COUNT(*) FROM seller_reviews WHERE rating<=2")->fetchColumn());
                            $cv = $t > 0 ? round(($n/$t)*100, 2) : 0; break;
                        case 'kyc_pending_backlog': $cv = floatval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status='pending'")->fetchColumn()); break;
                        case 'unverified_signups': $cv = floatval($conn->query("SELECT COUNT(*) FROM users WHERE role='seller' AND is_verified=0 AND DATE(created_at)='$today'")->fetchColumn()); break;
                        case 'return_rate':
                            $t = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
                            $r = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn());
                            $cv = $t > 0 ? round(($r/$t)*100, 2) : 0; break;
                    }

                    $barPct = $kpi['max'] > 0 ? min(100, round(($cv / $kpi['max']) * 100)) : 0;
                    $barColor = '#81c784'; // green = good
                    if ($kpi['invert']) {
                        // For inverted metrics (high = bad), red when high
                        if ($barPct > 60) $barColor = '#e57373';
                        elseif ($barPct > 30) $barColor = '#ffb74d';
                    } else {
                        // For normal metrics (high = good), red when low
                        if ($barPct < 20) $barColor = '#e57373';
                        elseif ($barPct < 50) $barColor = '#ffb74d';
                    }
                ?>
                <div class="kpi-gauge" id="kpi-<?php echo $kpi['key']; ?>">
                    <div class="kpi-gauge-header">
                        <span class="kpi-gauge-label"><?php echo $kpi['label']; ?></span>
                    </div>
                    <div class="kpi-gauge-value" style="color: <?php echo $barColor; ?>;"><?php echo $kpi['unit'] === 'Rs.' ? 'Rs.' . number_format($cv, 0) : number_format($cv, $cv == intval($cv) ? 0 : 2) . $kpi['unit']; ?></div>
                    <div class="kpi-gauge-bar">
                        <div class="kpi-gauge-bar-fill" style="width: <?php echo $barPct; ?>%; background: <?php echo $barColor; ?>;"></div>
                    </div>
                    <div class="kpi-gauge-footer">
                        <span class="kgf-baseline">Max: <?php echo $kpi['unit'] === 'Rs.' ? 'Rs.' . number_format($kpi['max'], 0) : $kpi['max'] . $kpi['unit']; ?></span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- CONFIG TABLE: Threshold Editor -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="alerts-panel" style="margin-bottom: 28px;">
    <div class="alerts-panel-header">
        <span><i class="fas fa-sliders-h" style="margin-right: 6px; color: #ce93d8;"></i> Alert Rules & Thresholds</span>
        <span style="font-size: 0.68rem; color: var(--admin-muted);"><?php echo count($alertConfigs); ?> rules configured</span>
    </div>
    <div class="alerts-panel-body" style="overflow-x: auto;">
        <table class="config-table">
            <thead>
                <tr>
                    <th>Metric</th>
                    <th>Check Type</th>
                    <th>Threshold</th>
                    <th>Lookback</th>
                    <th>Severity</th>
                    <th>Cooldown</th>
                    <th>Enabled</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alertConfigs as $cfg): ?>
                <tr>
                    <form method="POST">
                        <input type="hidden" name="update_rule" value="1">
                        <input type="hidden" name="rule_id" value="<?php echo $cfg['id']; ?>">
                        <td>
                            <div class="ct-label"><?php echo htmlspecialchars($cfg['label']); ?></div>
                            <div class="ct-desc"><?php echo htmlspecialchars($cfg['description'] ?? ''); ?></div>
                        </td>
                        <td>
                            <span class="ct-type"><?php echo str_replace('_', ' ', $cfg['check_type']); ?></span>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="threshold_value" class="ct-input" value="<?php echo $cfg['threshold_value']; ?>"
                                title="<?php echo in_array($cfg['check_type'], ['threshold_above', 'threshold_below']) ? 'Absolute value' : 'Percentage'; ?>">
                        </td>
                        <td>
                            <input type="number" min="1" max="90" name="lookback_days" class="ct-input" style="width:60px;" value="<?php echo $cfg['lookback_days']; ?>">
                            <span style="font-size:0.65rem; color:var(--admin-muted);">days</span>
                        </td>
                        <td>
                            <select name="severity" class="ct-select">
                                <option value="info" <?php echo $cfg['severity'] === 'info' ? 'selected' : ''; ?>>ℹ️ Info</option>
                                <option value="warning" <?php echo $cfg['severity'] === 'warning' ? 'selected' : ''; ?>>⚠️ Warning</option>
                                <option value="critical" <?php echo $cfg['severity'] === 'critical' ? 'selected' : ''; ?>>🚨 Critical</option>
                            </select>
                        </td>
                        <td>
                            <input type="number" min="1" max="168" name="cooldown_hours" class="ct-input" style="width:60px;" value="<?php echo $cfg['cooldown_hours']; ?>">
                            <span style="font-size:0.65rem; color:var(--admin-muted);">hrs</span>
                        </td>
                        <td style="text-align:center;">
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_enabled" value="1" <?php echo $cfg['is_enabled'] ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </td>
                        <td>
                            <button type="submit" class="ct-save-btn"><i class="fas fa-save"></i> Save</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($alertConfigs)): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--admin-muted); padding:40px;">No alert rules configured. Run the migration first.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- HOW ANOMALY DETECTION WORKS (info panel) -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="alerts-panel" style="margin-bottom: 28px;">
    <div class="alerts-panel-header">
        <span><i class="fas fa-brain" style="margin-right: 6px; color: #81c784;"></i> How Anomaly Detection Works</span>
    </div>
    <div class="alerts-panel-body padded" style="font-size: 0.82rem; color: var(--admin-muted); line-height: 1.7;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
            <div style="padding: 16px; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid rgba(255,255,255,0.04);">
                <div style="font-weight: 700; color: #64b5f6; margin-bottom: 6px; font-size: 0.78rem;">📊 Moving Average Deviation</div>
                Compares today's metric value against the <strong>N-day rolling average</strong>. Fires alert if deviation exceeds threshold %. Best for: daily orders, daily GMV.
            </div>
            <div style="padding: 16px; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid rgba(255,255,255,0.04);">
                <div style="font-weight: 700; color: #e57373; margin-bottom: 6px; font-size: 0.78rem;">📉 Drop / Spike %</div>
                Detects <strong>sudden percentage drops or spikes</strong> against historical baseline. Ideal for conversion rate drops and unverified signup surges.
            </div>
            <div style="padding: 16px; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid rgba(255,255,255,0.04);">
                <div style="font-weight: 700; color: #ffb74d; margin-bottom: 6px; font-size: 0.78rem;">🎯 Threshold (Above/Below)</div>
                Fires when a metric <strong>crosses an absolute boundary</strong>. E.g., if cancellation rate goes above 10%, or avg rating drops below 3.5★.
            </div>
            <div style="padding: 16px; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid rgba(255,255,255,0.04);">
                <div style="font-weight: 700; color: #81c784; margin-bottom: 6px; font-size: 0.78rem;">⏰ Cooldown Periods</div>
                Each rule has a <strong>cooldown timer</strong> (hrs) to prevent duplicate alerts. Avoids noise while ensuring persistent issues are re-flagged periodically.
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
// Dismiss an alert via AJAX
function dismissAlert(id) {
    fetch('../api/alerts.php?action=dismiss', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'ok') {
            const el = document.getElementById('alert-' + id);
            if (el) {
                el.style.transition = 'all 0.3s ease';
                el.style.opacity = '0';
                el.style.transform = 'translateX(40px)';
                setTimeout(() => el.remove(), 300);
            }
        }
    });
}

// Refresh KPIs via AJAX
function refreshKPIs() {
    const icon = document.getElementById('kpiRefreshIcon');
    if (icon) icon.style.animation = 'spin 0.6s linear infinite';

    fetch('../api/alerts.php?action=get_kpi_snapshot')
        .then(r => r.json())
        .then(data => {
            // Update gauge values dynamically
            if (icon) icon.style.animation = '';
            console.log('KPI Snapshot:', data);
        })
        .catch(err => {
            if (icon) icon.style.animation = '';
        });
}

// Auto-run scan on page load check (every 30 min via sessionStorage)
document.addEventListener('DOMContentLoaded', function() {
    const lastScan = sessionStorage.getItem('lastAlertScan');
    const now = Date.now();
    if (!lastScan || (now - parseInt(lastScan)) > 1800000) {
        // Auto-trigger a background check via API (doesn't reload page)
        fetch('../api/alerts.php?action=run_checks')
            .then(r => r.json())
            .then(data => {
                sessionStorage.setItem('lastAlertScan', now.toString());
                if (data.alerts_fired > 0) {
                    // Show a subtle notification
                    console.log('Background scan fired', data.alerts_fired, 'alerts');
                    // Optionally reload to show new alerts
                    if (data.alerts_fired > 0 && !window.location.search.includes('scan=1')) {
                        // Soft reload to show new alerts
                        window.location.reload();
                    }
                }
            });
    }
});
</script>

<style>
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<?php include 'footer.php'; ?>
