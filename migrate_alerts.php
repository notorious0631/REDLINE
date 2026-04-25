<?php
/**
 * REDLINE — Alerts & Thresholds Migration
 * Creates alert_configs and alert_history tables.
 * Run once via browser: /migrate_alerts.php
 */
require 'config/db.php';

$queries = [

    // Alert rule configurations (admin-defined thresholds)
    "CREATE TABLE IF NOT EXISTS `alert_configs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `metric_key` VARCHAR(80) NOT NULL UNIQUE COMMENT 'e.g. daily_orders, gmv, cancel_rate',
        `label` VARCHAR(150) NOT NULL COMMENT 'Human-readable name',
        `description` TEXT DEFAULT NULL,
        `check_type` ENUM('drop_pct','spike_pct','threshold_above','threshold_below','moving_avg_deviation') DEFAULT 'drop_pct',
        `threshold_value` DECIMAL(10,2) NOT NULL DEFAULT 20.00 COMMENT 'Percentage or absolute value',
        `lookback_days` INT NOT NULL DEFAULT 7 COMMENT 'Days of history for moving average',
        `severity` ENUM('info','warning','critical') DEFAULT 'warning',
        `is_enabled` TINYINT(1) DEFAULT 1,
        `cooldown_hours` INT DEFAULT 24 COMMENT 'Min hours between duplicate alerts',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Alert history (fired alerts log)
    "CREATE TABLE IF NOT EXISTS `alert_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `config_id` INT DEFAULT NULL,
        `metric_key` VARCHAR(80) NOT NULL,
        `severity` ENUM('info','warning','critical') DEFAULT 'warning',
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NOT NULL,
        `current_value` DECIMAL(15,2) DEFAULT NULL,
        `threshold_value` DECIMAL(15,2) DEFAULT NULL,
        `baseline_value` DECIMAL(15,2) DEFAULT NULL COMMENT 'e.g. 7-day avg for deviation alerts',
        `deviation_pct` DECIMAL(8,2) DEFAULT NULL,
        `is_read` TINYINT(1) DEFAULT 0,
        `is_dismissed` TINYINT(1) DEFAULT 0,
        `fired_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`config_id`) REFERENCES `alert_configs`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Seed default alert rules
    "INSERT IGNORE INTO `alert_configs` (`metric_key`, `label`, `description`, `check_type`, `threshold_value`, `lookback_days`, `severity`) VALUES
        ('daily_orders',        'Daily Order Volume',           'Alert when daily orders drop significantly vs 7-day average.',               'moving_avg_deviation', 30.00, 7, 'warning'),
        ('daily_gmv',           'Daily GMV (Revenue)',          'Alert when daily GMV drops below 7-day rolling average by threshold %.',     'moving_avg_deviation', 20.00, 7, 'critical'),
        ('cancel_rate',         'Cancellation Rate',            'Alert when cancellation rate exceeds threshold.',                            'threshold_above',      10.00, 1, 'critical'),
        ('dispute_rate',        'Dispute Rate',                 'Alert when dispute rate spikes above threshold %.',                          'threshold_above',       5.00, 1, 'critical'),
        ('unverified_signups',  'Unverified Seller Signups',    'Alert on surge of unverified seller signups (possible scam risk).',          'spike_pct',            50.00, 7, 'warning'),
        ('conversion_rate',     'Listing→Order Conversion',     'Alert when conversion rate drops significantly.',                            'drop_pct',             10.00, 7, 'warning'),
        ('avg_rating_drop',     'Avg Seller Rating',            'Alert when platform avg rating drops below threshold.',                      'threshold_below',       3.50, 1, 'warning'),
        ('return_rate',         'Return/Refund Rate',           'Alert when return/refund rate exceeds threshold %.',                         'threshold_above',       8.00, 1, 'warning'),
        ('kyc_pending_backlog', 'KYC Pending Backlog',          'Alert when pending KYC applications exceed threshold count.',                'threshold_above',      10.00, 1, 'info'),
        ('negative_review_ratio','Negative Review Ratio',       'Alert when % of reviews rated ≤2 stars exceeds threshold.',                 'threshold_above',      15.00, 1, 'warning')",
];

echo "<h2>REDLINE — Alerts & Thresholds Migration</h2><pre>";
foreach ($queries as $sql) {
    try {
        $conn->exec($sql);
        echo "✅ Success: " . substr($sql, 0, 90) . "...\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⏭️ Skipped (already exists): " . substr($sql, 0, 90) . "...\n";
        } else {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }
}
echo "\n✅ Migration complete.</pre>";
?>
