<?php
/**
 * Migration: Add storefront fields to users table
 * - store_name, social_instagram, social_facebook, social_twitter, show_reviews
 */
require_once 'config/db.php';

$migrations = [
    "ALTER TABLE users ADD COLUMN store_name VARCHAR(255) DEFAULT NULL AFTER name",
    "ALTER TABLE users ADD COLUMN social_instagram VARCHAR(255) DEFAULT NULL AFTER store_location",
    "ALTER TABLE users ADD COLUMN social_facebook VARCHAR(255) DEFAULT NULL AFTER social_instagram",
    "ALTER TABLE users ADD COLUMN social_twitter VARCHAR(255) DEFAULT NULL AFTER social_facebook",
    "ALTER TABLE users ADD COLUMN show_reviews TINYINT(1) DEFAULT 1 AFTER social_twitter",
];

echo "<h2>Storefront Migration</h2><pre>";

foreach ($migrations as $sql) {
    try {
        $conn->exec($sql);
        echo "✅ SUCCESS: $sql\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⚠️ SKIP (already exists): $sql\n";
        } else {
            echo "❌ ERROR: $sql\n   " . $e->getMessage() . "\n";
        }
    }
}

echo "\n🎉 Migration complete!</pre>";
