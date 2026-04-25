<?php
/**
 * REDLINE Chat System v2 — Migration
 * Run once to create new tables. Old tables are preserved (not dropped).
 */
require_once 'config/db.php';

$results = [];

try {
    // ─── 1. Add last_seen to users if missing ───
    try {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `last_seen` DATETIME DEFAULT NULL");
        $results[] = "✅ Added `last_seen` column to users table";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "⏭ `last_seen` column already exists";
        } else {
            throw $e;
        }
    }

    // ─── 2. conversations table ───
    $conn->exec("CREATE TABLE IF NOT EXISTS `conversations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `type` ENUM('buying','selling','direct') NOT NULL DEFAULT 'buying',
        `listing_id` INT DEFAULT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `status` ENUM('active','accepted','rejected','expired','completed') DEFAULT 'active',
        `offered_price` DECIMAL(10,2) DEFAULT NULL,
        `expires_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        INDEX `idx_conv_buyer` (`buyer_id`),
        INDEX `idx_conv_seller` (`seller_id`),
        INDEX `idx_conv_type` (`type`),
        INDEX `idx_conv_listing` (`listing_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results[] = "✅ Created `conversations` table";

    // ─── 3. chat_messages table ───
    $conn->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `conversation_id` INT NOT NULL,
        `sender_id` INT NOT NULL,
        `message` TEXT DEFAULT NULL,
        `msg_type` ENUM('text','image','offer','counter','accept','reject','system','payment_proof') DEFAULT 'text',
        `offer_amount` DECIMAL(10,2) DEFAULT NULL,
        `image_path` VARCHAR(500) DEFAULT NULL,
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        INDEX `idx_cm_conv` (`conversation_id`),
        INDEX `idx_cm_sender` (`sender_id`),
        INDEX `idx_cm_read` (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results[] = "✅ Created `chat_messages` table";

    // ─── 4. chat_reports table ───
    $conn->exec("CREATE TABLE IF NOT EXISTS `chat_reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `conversation_id` INT NOT NULL,
        `reporter_id` INT NOT NULL,
        `reported_user_id` INT NOT NULL,
        `reason` TEXT NOT NULL,
        `status` ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
        `admin_notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`reported_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        INDEX `idx_cr_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results[] = "✅ Created `chat_reports` table";

    // ─── 5. Migrate old negotiations data if tables exist ───
    try {
        $check = $conn->query("SELECT COUNT(*) FROM negotiations")->fetchColumn();
        if ($check > 0) {
            // Migrate old negotiations → conversations
            $conn->exec("INSERT IGNORE INTO `conversations` (id, type, listing_id, buyer_id, seller_id, status, offered_price, created_at, updated_at)
                SELECT id, 'buying', listing_id, buyer_id, seller_id,
                       CASE status WHEN 'active' THEN 'active' WHEN 'accepted' THEN 'accepted' WHEN 'rejected' THEN 'rejected' ELSE 'expired' END,
                       offered_price, created_at, updated_at
                FROM negotiations");
            $results[] = "✅ Migrated " . $check . " negotiations → conversations";

            // Migrate old negotiation_messages → chat_messages
            $msgCount = $conn->query("SELECT COUNT(*) FROM negotiation_messages")->fetchColumn();
            if ($msgCount > 0) {
                $conn->exec("INSERT IGNORE INTO `chat_messages` (id, conversation_id, sender_id, message, msg_type, offer_amount, is_read, created_at)
                    SELECT id, negotiation_id, sender_id, message, msg_type, offer_amount, is_read, created_at
                    FROM negotiation_messages");
                $results[] = "✅ Migrated " . $msgCount . " messages → chat_messages";
            }
        } else {
            $results[] = "⏭ No old negotiation data to migrate";
        }
    } catch (PDOException $e) {
        $results[] = "⏭ Old negotiations table not found — fresh install";
    }

    // ─── 6. Create uploads/chat directory ───
    $chatUploadDir = __DIR__ . '/uploads/chat';
    if (!is_dir($chatUploadDir)) {
        mkdir($chatUploadDir, 0777, true);
        $results[] = "✅ Created uploads/chat/ directory";
    } else {
        $results[] = "⏭ uploads/chat/ directory already exists";
    }

} catch (PDOException $e) {
    $results[] = "❌ Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html><head><title>REDLINE Chat v2 Migration</title>
<style>
body { background: #0d0d0d; color: #fff; font-family: 'Poppins', sans-serif; padding: 40px; }
.result { padding: 10px 16px; margin: 6px 0; background: rgba(255,255,255,0.03); border-radius: 8px; font-size: 0.9rem; border-left: 3px solid #e53935; }
h1 { font-size: 1.5rem; color: #e53935; margin-bottom: 24px; }
a { color: #e53935; text-decoration: none; }
</style></head><body>
<h1>🔄 Chat System v2 Migration</h1>
<?php foreach ($results as $r): ?>
    <div class="result"><?php echo $r; ?></div>
<?php endforeach; ?>
<p style="margin-top:24px;"><a href="index.php">← Back to Marketplace</a></p>
</body></html>
