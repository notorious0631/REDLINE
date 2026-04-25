<?php
// seller_dashboard/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Redirect buyers to seller application instead of login
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['seller', 'admin'])) {
    header('Location: ../apply_seller.php');
    exit;
}

$sellerPage = basename($_SERVER['PHP_SELF']);

// Fetch user data for sidebar and payment check
try {
    $stmt = $conn->prepare("SELECT name, avatar, upi_id, bank_details FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $sellerUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Enforce payment details requirement for sellers
    if ($_SESSION['role'] === 'seller') {
        if (empty($sellerUser['upi_id']) && empty(trim($sellerUser['bank_details'] ?? ''))) {
            header('Location: ../profile.php?missing_payment=1#edit');
            exit;
        }
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - Seller Hub' : 'Seller Hub - REDLINE'; ?></title>
    <!-- New Minimalistic Seller Dashboard CSS -->
    <link rel="stylesheet" href="../assets/css/seller-dashboard.css?v=<?php echo time(); ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="seller-layout">
    <!-- Sidebar -->
    <aside class="seller-sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="../index.php" class="brand">REDLINE</a>
        </div>
        
        <div class="sidebar-profile">
            <div class="sidebar-avatar" <?php if(!empty($sellerUser['avatar'])) echo 'style="background:url(../'.htmlspecialchars($sellerUser['avatar']).') center/cover; color:transparent;"'; ?>>
                <?php if(empty($sellerUser['avatar'])) echo strtoupper(substr($sellerUser['name'] ?? 'S', 0, 1)); ?>
            </div>
            <div class="sidebar-profile-info">
                <div class="sidebar-profile-name"><?php echo htmlspecialchars($sellerUser['name'] ?? 'Seller'); ?></div>
                <div class="sidebar-profile-role"><i class="fas fa-check-circle"></i> Verified Merchant</div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="index.php" class="<?php echo $sellerPage === 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
            
            <div class="nav-section-title">Sales & Inventory</div>
            <a href="listings.php" class="<?php echo in_array($sellerPage, ['listings.php', 'edit_listing.php']) ? 'active' : ''; ?>">
                <i class="fas fa-box"></i> Inventory
            </a>
            <a href="add_listing.php" class="<?php echo $sellerPage === 'add_listing.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus"></i> Add Product
            </a>
            <a href="orders.php" class="<?php echo $sellerPage === 'orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-bag"></i> Orders
            </a>
            <a href="negotiations.php" class="<?php echo $sellerPage === 'negotiations.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i> Negotiations
            </a>
            <a href="highlights.php" class="<?php echo $sellerPage === 'highlights.php' ? 'active' : ''; ?>">
                <i class="fas fa-circle-notch"></i> Highlights
            </a>
            
            <div class="nav-section-title">Settings</div>
            <a href="storefront.php" class="<?php echo $sellerPage === 'storefront.php' ? 'active' : ''; ?>">
                <i class="fas fa-paint-brush"></i> Update Storefront
            </a>
            <a href="../seller.php?id=<?php echo $_SESSION['user_id']; ?>" target="_blank">
                <i class="fas fa-external-link-alt"></i> View Storefront
            </a>
            <a href="../profile.php#edit">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="../logout.php" style="color:var(--accent-red); margin-top: 10px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="seller-main">
        <div class="seller-topbar">
            <!-- Search bar representing modern dashboards -->
            <div class="topbar-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search orders, inventory...">
            </div>
            <div class="topbar-actions">
                <a href="../index.php" class="btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;"><i class="fas fa-home"></i> Marketplace</a>
                <a href="#" class="btn-icon-only" title="Notifications"><i class="fas fa-bell"></i></a>
            </div>
        </div>
        <div class="seller-content">
