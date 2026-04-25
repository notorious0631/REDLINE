<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// Admin guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$adminPage = basename($_SERVER['PHP_SELF']);
$adminName = $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>REDLINE Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=Poppins:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">

<!-- Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
    <a href="index.php" class="sidebar-logo">
        <img src="../assets/images/logo.jpeg" alt="REDLINE">
        <span>REDLINE</span>
        <small>ADMIN</small>
    </a>

    <nav class="sidebar-nav">
        <div class="sidebar-section">
            <div class="sidebar-section-label">Overview</div>
            <a href="index.php" class="<?php echo $adminPage === 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
            <a href="reports.php" class="<?php echo $adminPage === 'reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="trust_metrics.php" class="<?php echo $adminPage === 'trust_metrics.php' ? 'active' : ''; ?>">
                <i class="fas fa-shield-alt"></i> Trust & Quality
            </a>
            <?php
            // Count unread alerts for sidebar badge
            $unreadAlertCount = 0;
            try {
                $stmt = $conn->query("SELECT COUNT(*) FROM alert_history WHERE is_read = 0 AND is_dismissed = 0");
                $unreadAlertCount = intval($stmt->fetchColumn());
            } catch (PDOException $e) {}
            ?>
            <a href="alerts.php" class="<?php echo $adminPage === 'alerts.php' ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-bell"></i> Alerts</span>
                <?php if ($unreadAlertCount > 0): ?>
                    <span style="background:var(--admin-orange); color:#000; border-radius:12px; padding:2px 8px; font-size:0.7rem; font-weight:bold; margin-right:10px;"><?php echo $unreadAlertCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="engagement.php" class="<?php echo $adminPage === 'engagement.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Engagement
            </a>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Management</div>
            <a href="users.php" class="<?php echo $adminPage === 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="listings.php" class="<?php echo $adminPage === 'listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i> Listings
            </a>
            <a href="orders.php" class="<?php echo $adminPage === 'orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i> Orders
            </a>
            <?php
            // Calculate open disputes count for the notification badge
            try {
                $stmt = $conn->query("SELECT COUNT(*) FROM order_disputes WHERE status IN ('open', 'investigating')");
                $openDisputesCount = $stmt->fetchColumn();
            } catch (PDOException $e) {
                $openDisputesCount = 0;
            }
            ?>
            <a href="disputes.php" class="<?php echo $adminPage === 'disputes.php' ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-life-ring"></i> Disputes</span>
                <?php if ($openDisputesCount > 0): ?>
                    <span style="background:var(--accent-red, #e53935); color:#fff; border-radius:12px; padding:2px 8px; font-size:0.75rem; font-weight:bold; margin-right:10px;"><?php echo $openDisputesCount; ?></span>
                <?php endif; ?>
            </a>
            <?php
            // Calculate pending chat reports count
            $pendingChatReports = 0;
            try {
                $stmt = $conn->query("SELECT COUNT(*) FROM chat_reports WHERE status = 'pending'");
                $pendingChatReports = $stmt->fetchColumn();
            } catch (PDOException $e) {}
            ?>
            <a href="chat_reports.php" class="<?php echo $adminPage === 'chat_reports.php' ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-flag"></i> Chat Reports</span>
                <?php if ($pendingChatReports > 0): ?>
                    <span style="background:#ff9800; color:#000; border-radius:12px; padding:2px 8px; font-size:0.75rem; font-weight:bold; margin-right:10px;"><?php echo $pendingChatReports; ?></span>
                <?php endif; ?>
            </a>
            <?php
            // Calculate unread contact messages
            $unreadContactMessages = 0;
            try {
                $stmt = $conn->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0");
                $unreadContactMessages = $stmt->fetchColumn();
            } catch (PDOException $e) {}
            ?>
            <a href="contact_messages.php" class="<?php echo $adminPage === 'contact_messages.php' ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-envelope-open-text"></i> Contact Msgs</span>
                <?php if ($unreadContactMessages > 0): ?>
                    <span style="background:var(--admin-blue, #2196f3); color:#fff; border-radius:12px; padding:2px 8px; font-size:0.75rem; font-weight:bold; margin-right:10px;"><?php echo $unreadContactMessages; ?></span>
                <?php endif; ?>
            </a>
            <a href="invoice.php" class="<?php echo $adminPage === 'invoice.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice"></i> Invoices
            </a>
            <?php
            // Calculate pending KYC count for the notification badge
            try {
                $stmt = $conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'");
                $pendingKycCount = $stmt->fetchColumn();
            } catch (PDOException $e) {
                $pendingKycCount = 0;
            }
            ?>
            <a href="applications.php" class="<?php echo $adminPage === 'applications.php' || $adminPage === 'application_view.php' ? 'active' : ''; ?>" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-id-card"></i> KYC Applications</span>
                <?php if ($pendingKycCount > 0): ?>
                    <span style="background:var(--accent-red); color:#fff; border-radius:12px; padding:2px 8px; font-size:0.75rem; font-weight:bold; margin-right:10px;"><?php echo $pendingKycCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="categories.php" class="<?php echo $adminPage === 'categories.php' ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> Categories
            </a>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-section-label">System</div>
            <a href="carousel.php" class="<?php echo $adminPage === 'carousel.php' ? 'active' : ''; ?>">
                <i class="fas fa-images"></i> Homepage Carousel
            </a>
            <a href="settings.php" class="<?php echo $adminPage === 'settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="invoice_settings.php" class="<?php echo $adminPage === 'invoice_settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i> Invoice Settings
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div style="margin-bottom: 6px;"><?php echo htmlspecialchars($adminName); ?></div>
        <a href="../index.php"><i class="fas fa-external-link-alt"></i> View Site</a> &nbsp;·&nbsp;
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="admin-main">
