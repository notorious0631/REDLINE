<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $upi_id = trim($_POST['upi_id'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');

    if (empty($name)) {
        $error = 'Name is required.';
    } else {
        try {
            $avatarPath = null;
            $bannerPath = null;

            if (!empty($_FILES['avatar']['tmp_name'])) {
                $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $avatarPath = 'uploads/avatars/' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['avatar']['tmp_name'], $avatarPath);
            }
            if (!empty($_FILES['banner']['tmp_name'])) {
                $ext = pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION);
                $bannerPath = 'uploads/banners/' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['banner']['tmp_name'], $bannerPath);
            }

            if ($avatarPath && $bannerPath) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, bio = ?, upi_id = ?, bank_details = ?, avatar = ?, banner = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $bio, $upi_id, $bank_details, $avatarPath, $bannerPath, $userId]);
            } elseif ($avatarPath) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, bio = ?, upi_id = ?, bank_details = ?, avatar = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $bio, $upi_id, $bank_details, $avatarPath, $userId]);
            } elseif ($bannerPath) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, bio = ?, upi_id = ?, bank_details = ?, banner = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $bio, $upi_id, $bank_details, $bannerPath, $userId]);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, bio = ?, upi_id = ?, bank_details = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $bio, $upi_id, $bank_details, $userId]);
            }

            $_SESSION['user_name'] = $name;
            $success = 'Profile updated successfully!';

            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = 'Something went wrong.';
        }
    }
}

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All password fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (password_verify($currentPassword, $hash)) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $userId]);
                $success = 'Password changed successfully!';
            } else {
                $error = 'Current password is incorrect.';
            }
        } catch (PDOException $e) {
            $error = 'Failed to update password.';
        }
    }
}

// Handle store location update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_store_location'])) {
    $store_location = trim($_POST['store_location'] ?? '');
    try {
        $stmt = $conn->prepare("UPDATE users SET store_location = ? WHERE id = ?");
        $stmt->execute([$store_location, $userId]);
        $success = 'Store location updated successfully!';
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'Failed to update store location.';
    }
}

// Fetch user data
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = null;
}

// Stats
$listingCount = 0;
$orderCount = 0;
$totalSpent = 0;
$reviewCount = 0;
$wishlistCount = 0;
$salesCount = 0;
$disputeCount = 0;

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ?");
    $stmt->execute([$userId]);
    $listingCount = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE buyer_id = ? AND status != 'cancelled'");
    $stmt->execute([$userId]);
    $orderStats = $stmt->fetch(PDO::FETCH_NUM);
    $orderCount = $orderStats[0];
    $totalSpent = $orderStats[1];

    // Sales as seller
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status != 'cancelled'");
    $stmt->execute([$userId]);
    $salesCount = $stmt->fetchColumn();

    // Wishlist count
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM wishlists WHERE user_id = ?");
        $stmt->execute([$userId]);
        $wishlistCount = $stmt->fetchColumn();
    } catch (PDOException $e) { $wishlistCount = 0; }

    // Dispute count
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM disputes WHERE buyer_id = ? OR seller_id = ?");
        $stmt->execute([$userId, $userId]);
        $disputeCount = $stmt->fetchColumn();
    } catch (PDOException $e) { $disputeCount = 0; }

} catch (PDOException $e) {}

// Handle Delete Listing
if (isset($_GET['delete_listing'])) {
    $delId = intval($_GET['delete_listing']);
    try {
        $conn->prepare("DELETE FROM listings WHERE id = ? AND seller_id = ?")->execute([$delId, $userId]);
        $success = "Listing deleted successfully.";
    } catch (PDOException $e) {}
}

// Fetch My Listings
$myListings = [];
try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE l.seller_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$userId]);
    $myListings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch My Orders (All)
$myOrders = [];
try {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE buyer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $myOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Recent Orders (Last 5)
$recentOrders = [];
try {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE buyer_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Wishlist items
$wishlistItems = [];
try {
    $stmt = $conn->prepare("
        SELECT w.*, l.title, l.price, l.image, l.status AS listing_status
        FROM wishlists w
        JOIN listings l ON w.listing_id = l.id
        WHERE w.user_id = ?
        ORDER BY w.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $wishlistItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $wishlistItems = []; }

// Active section from GET param
$activeSection = isset($_GET['section']) ? $_GET['section'] : 'overview';
if ($success || $error) {
    if (isset($_POST['update_password'])) $activeSection = 'security';
    elseif (isset($_POST['update_store_location'])) $activeSection = 'location';
    elseif (isset($_POST['update_profile'])) $activeSection = 'profile';
}

include 'includes/header.php';
?>

<style>
/* =========================================
   PROFILE PAGE - PREMIUM REVAMP
   ========================================= */

.profile-shell {
    display: flex;
    min-height: 100vh;
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 24px 80px;
    gap: 28px;
}

/* ---- SIDEBAR ---- */
.profile-sidebar {
    width: 260px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: sticky;
    top: 90px;
    align-self: flex-start;
    height: fit-content;
}

.sidebar-user-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    margin-bottom: 8px;
    position: relative;
    overflow: hidden;
}

.sidebar-user-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 60px;
    background: linear-gradient(135deg, rgba(229,57,53,0.2), rgba(229,57,53,0.05));
}

.sidebar-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--accent-red);
    border: 3px solid var(--bg-base);
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    font-size: 1.8rem;
    font-weight: 800;
    font-family: var(--font-display);
    background-size: cover;
    background-position: center;
    box-shadow: 0 4px 20px rgba(229,57,53,0.3);
    position: relative;
    z-index: 1;
    margin-top: 10px;
}

.sidebar-user-name {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 6px;
}

.sidebar-user-email {
    font-size: 0.78rem;
    color: var(--text-muted);
}

.sidebar-role-badge {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.sidebar-role-badge.admin { background: rgba(229,57,53,0.15); color: #e57373; border: 1px solid rgba(229,57,53,0.3); }
.sidebar-role-badge.seller { background: rgba(33,150,243,0.15); color: #64b5f6; border: 1px solid rgba(33,150,243,0.3); }
.sidebar-role-badge.buyer { background: rgba(76,175,80,0.15); color: #81c784; border: 1px solid rgba(76,175,80,0.3); }

.sidebar-section-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    padding: 8px 12px 4px;
}

.sidebar-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 10px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    background: transparent;
    width: 100%;
    text-align: left;
    transition: all 0.2s ease;
    position: relative;
}

.sidebar-nav-item i {
    width: 18px;
    text-align: center;
    font-size: 0.95rem;
    transition: color 0.2s;
}

.sidebar-nav-item:hover {
    background: rgba(255,255,255,0.05);
    color: var(--text-primary);
}

.sidebar-nav-item.active {
    background: rgba(229,57,53,0.12);
    color: var(--accent-red);
    font-weight: 600;
}
.sidebar-nav-item.active i { color: var(--accent-red); }

.sidebar-nav-item .nav-badge {
    margin-left: auto;
    background: var(--accent-red);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}

.sidebar-divider {
    height: 1px;
    background: var(--border-color);
    margin: 6px 0;
}

/* ---- MAIN CONTENT ---- */
.profile-main {
    flex: 1;
    min-width: 0;
}

/* Section panels */
.profile-section {
    display: none;
    animation: sectionIn 0.3s ease;
}
.profile-section.active {
    display: block;
}
@keyframes sectionIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Page header strip */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}
.section-header h1 {
    font-family: var(--font-display);
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0;
}
.section-header p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 0.88rem;
}

/* ---- STAT CARDS ---- */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.stat-card-v2 {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-v2::after {
    content: '';
    position: absolute;
    bottom: -20px; right: -20px;
    width: 80px; height: 80px;
    border-radius: 50%;
    opacity: 0.04;
    background: currentColor;
}

.stat-card-v2:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.25);
}

.stat-card-v2.blue:hover   { border-color: rgba(100,181,246,0.4); }
.stat-card-v2.green:hover  { border-color: rgba(129,199,132,0.4); }
.stat-card-v2.purple:hover { border-color: rgba(186,104,200,0.4); }
.stat-card-v2.red:hover    { border-color: rgba(229,100,100,0.4); }
.stat-card-v2.gold:hover   { border-color: rgba(255,193,7,0.4); }
.stat-card-v2.teal:hover   { border-color: rgba(77,208,225,0.4); }

.stat-icon-v2 {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
}
.stat-icon-v2.blue   { background: rgba(100,181,246,0.12); color: #64b5f6; }
.stat-icon-v2.green  { background: rgba(129,199,132,0.12); color: #81c784; }
.stat-icon-v2.purple { background: rgba(186,104,200,0.12); color: #ba68c8; }
.stat-icon-v2.red    { background: rgba(229,100,100,0.12); color: #ef9a9a; }
.stat-icon-v2.gold   { background: rgba(255,193,7,0.12);   color: #ffd54f; }
.stat-icon-v2.teal   { background: rgba(77,208,225,0.12);  color: #4dd0e1; }

.stat-val { font-size: 1.75rem; font-weight: 800; line-height: 1; color: var(--text-primary); }
.stat-label { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }

/* ---- INFO CARDS ---- */
.info-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 20px;
}
.info-card-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: rgba(255,255,255,0.015);
}
.info-card-header h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.info-card-header h3 i { color: var(--accent-red); font-size: 0.9rem; }
.info-card-body { padding: 24px; }

/* Personal Details rows */
.detail-row {
    display: flex;
    align-items: flex-start;
    padding: 14px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    gap: 16px;
}
.detail-row:last-child { border-bottom: none; }
.detail-label {
    width: 160px;
    flex-shrink: 0;
    font-size: 0.85rem;
    color: var(--text-muted);
    font-weight: 500;
}
.detail-value {
    flex: 1;
    font-size: 0.9rem;
    color: var(--text-primary);
    font-weight: 500;
    word-break: break-all;
}
.detail-value.empty { color: var(--text-muted); font-style: italic; }

/* ---- QUICK ACTIONS GRID ---- */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
}

.quick-action-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--text-secondary);
    transition: all 0.25s ease;
    text-align: center;
    font-size: 0.85rem;
    font-weight: 600;
}
.quick-action-card:hover {
    background: rgba(229,57,53,0.08);
    border-color: rgba(229,57,53,0.3);
    color: var(--text-primary);
    transform: translateY(-3px);
}
.quick-action-card .qa-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.04);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    transition: background 0.25s;
}
.quick-action-card:hover .qa-icon {
    background: rgba(229,57,53,0.15);
    color: var(--accent-red);
}

/* ---- ORDERS ---- */
.order-card-v2 {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 18px 22px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    margin-bottom: 12px;
    transition: all 0.25s ease;
}
.order-card-v2:hover {
    border-color: rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.03);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
}
.ocv2-id { font-size: 1rem; font-weight: 800; color: var(--text-primary); }
.ocv2-date { font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; gap: 5px; margin-top: 3px; }
.ocv2-price { font-weight: 800; font-size: 1rem; }
.ocv2-badge {
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
    padding: 5px 13px; border-radius: 8px; letter-spacing: 0.5px;
    display: inline-flex; align-items: center; gap: 6px;
}
.ocv2-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.ocv2-btn {
    padding: 7px 14px; border-radius: 8px; font-size: 0.78rem; font-weight: 600;
    border: 1px solid rgba(255,255,255,0.1); color: var(--text-secondary);
    background: transparent; text-decoration: none;
    transition: 0.2s; display: inline-flex; align-items: center; gap: 5px;
}
.ocv2-btn:hover { border-color: rgba(255,255,255,0.3); color: #fff; background: rgba(255,255,255,0.05); }
.ocv2-btn.primary { background: var(--accent-red); color: #fff; border-color: var(--accent-red); }
.ocv2-btn.primary:hover { opacity: 0.85; }

/* ---- LISTINGS TABLE ---- */
.listings-table-wrap { overflow-x: auto; }
.listings-table {
    width: 100%;
    border-collapse: collapse;
}
.listings-table th {
    text-align: left;
    padding: 12px 16px;
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    background: rgba(255,255,255,0.02);
    border-bottom: 1px solid var(--border-color);
}
.listings-table td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: middle;
}
.listings-table tr:last-child td { border-bottom: none; }
.listings-table tr:hover td { background: rgba(255,255,255,0.02); }

/* ---- FORM STYLES ---- */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-field { display: flex; flex-direction: column; gap: 6px; }
.form-field.full { grid-column: span 2; }
.form-field label { font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); }
.form-field input,
.form-field textarea,
.form-field select {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    padding: 11px 14px;
    font-size: 0.9rem;
    font-family: var(--font-body, inherit);
    transition: border-color 0.2s, box-shadow 0.2s;
    width: 100%;
}
.form-field input:focus,
.form-field textarea:focus,
.form-field select:focus {
    outline: none;
    border-color: var(--accent-red);
    box-shadow: 0 0 0 3px rgba(229,57,53,0.1);
}
.form-field input:disabled { opacity: 0.45; cursor: not-allowed; }
.form-hint { font-size: 0.74rem; color: var(--text-muted); margin-top: 2px; }
.form-section-divider {
    border: none;
    border-top: 1px solid var(--border-color);
    margin: 28px 0;
}

/* ---- AVATAR UPLOAD PREVIEW ---- */
.avatar-upload-wrap {
    display: flex; align-items: center; gap: 20px; margin-bottom: 24px;
}
.avatar-preview-lg {
    width: 80px; height: 80px; border-radius: 50%;
    background: var(--accent-red);
    border: 3px solid var(--border-color);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; font-weight: 800; color: #fff;
    background-size: cover; background-position: center;
    flex-shrink: 0;
}
.avatar-upload-info { flex: 1; }
.avatar-upload-info p { margin: 0 0 8px; font-size: 0.85rem; color: var(--text-secondary); }
.file-upload-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
    border: 1px solid var(--border-color); color: var(--text-secondary);
    background: rgba(255,255,255,0.04); cursor: pointer; transition: 0.2s;
}
.file-upload-btn:hover { border-color: rgba(255,255,255,0.2); color: var(--text-primary); }
.file-upload-btn input { display: none; }

/* ---- ALERT ---- */
.profile-alert {
    padding: 14px 18px; border-radius: 10px;
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 20px; font-size: 0.88rem; font-weight: 500;
}
.profile-alert.success { background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #81c784; }
.profile-alert.error   { background: rgba(229,57,53,0.1);  border: 1px solid rgba(229,57,53,0.3);  color: #e57373; }
.profile-alert.warning { background: rgba(255,183,77,0.1); border: 1px solid rgba(255,183,77,0.3); color: #ffb74d; }

/* ---- SELLER CTA ---- */
.seller-cta {
    background: linear-gradient(135deg, rgba(229,57,53,0.12), rgba(229,57,53,0.04));
    border: 1px solid rgba(229,57,53,0.25);
    border-radius: 16px;
    padding: 28px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 20px;
    margin-bottom: 24px;
}

/* ---- PASSWORD STRENGTH ---- */
.pwd-strength-bar {
    height: 4px; border-radius: 2px; margin-top: 8px;
    background: rgba(255,255,255,0.08);
    overflow: hidden;
}
.pwd-strength-fill {
    height: 100%; border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0%;
}
.pwd-strength-label { font-size: 0.73rem; margin-top: 4px; font-weight: 600; }

/* ---- WISHLIST GRID ---- */
.wishlist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}
.wishlist-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px; overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s;
    text-decoration: none; color: inherit;
    display: block;
}
.wishlist-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.25); }
.wishlist-card-img {
    height: 130px; background: var(--bg-surface);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; position: relative;
}
.wishlist-card-img img { width: 100%; height: 100%; object-fit: cover; }
.wishlist-card-img .no-img-icon { font-size: 2rem; color: rgba(255,255,255,0.15); }
.wishlist-card-info { padding: 12px 14px; }
.wishlist-card-title { font-size: 0.85rem; font-weight: 600; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wishlist-card-price { font-size: 0.95rem; font-weight: 800; color: var(--accent-red); }

/* ---- SECURITY ---- */
.security-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 0; border-bottom: 1px solid rgba(255,255,255,0.04);
    gap: 16px; flex-wrap: wrap;
}
.security-item:last-child { border-bottom: none; }
.security-item-info h4 { font-size: 0.95rem; font-weight: 600; margin: 0 0 4px; }
.security-item-info p { font-size: 0.82rem; color: var(--text-muted); margin: 0; }
.security-status { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 600; }
.security-status.ok { color: #81c784; }
.security-status.warn { color: #ffb74d; }

/* ---- EMPTY STATE ---- */
.empty-state {
    text-align: center; padding: 60px 20px;
    background: var(--bg-card);
    border-radius: 16px;
    border: 1px dashed var(--border-color);
}
.empty-state i { font-size: 3rem; opacity: 0.2; margin-bottom: 16px; display: block; }
.empty-state h3 { margin-bottom: 8px; }
.empty-state p  { color: var(--text-secondary); margin-bottom: 20px; }

/* ---- MOBILE SIDEBAR ---- */
.mobile-profile-nav {
    display: none;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 6px;
    margin-bottom: 16px;
    overflow-x: auto;
    overflow-y: hidden;
    gap: 4px;
    white-space: nowrap;
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.mobile-profile-nav::-webkit-scrollbar { display: none; }
.mobile-profile-nav .mpn-item {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 12px; border-radius: 8px;
    font-size: 0.78rem; font-weight: 600;
    cursor: pointer; border: none; background: transparent;
    color: var(--text-secondary); transition: 0.2s;
    flex-shrink: 0;
}
.mobile-profile-nav .mpn-item i { font-size: 0.8rem; }
.mobile-profile-nav .mpn-item.active,
.mobile-profile-nav .mpn-item:hover {
    background: rgba(229,57,53,0.12);
    color: var(--accent-red);
}

/* ---- BTN ---- */
.btn-primary-rl {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 24px; border-radius: 10px;
    background: var(--accent-red); color: #fff;
    font-size: 0.9rem; font-weight: 600;
    border: none; cursor: pointer; transition: 0.25s;
    text-decoration: none;
}
.btn-primary-rl:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-outline-rl {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px; border-radius: 10px;
    background: transparent; color: var(--text-secondary);
    font-size: 0.88rem; font-weight: 600;
    border: 1px solid var(--border-color); cursor: pointer; transition: 0.25s;
    text-decoration: none;
}
.btn-outline-rl:hover { border-color: rgba(255,255,255,0.25); color: var(--text-primary); }

/* ---- RESPONSIVE ---- */
@media (max-width: 900px) {
    .profile-shell { flex-direction: column; padding: 8px 14px 80px; gap: 0; }
    .profile-sidebar { width: 100%; position: static; display: none; }
    .mobile-profile-nav { display: flex; }
    .form-grid { grid-template-columns: 1fr; }
    .form-field.full { grid-column: span 1; }
    .stats-row { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .section-header { flex-direction: column; align-items: flex-start; gap: 12px; margin-bottom: 20px; padding-bottom: 16px; }
    .section-header .btn-outline-rl,
    .section-header .btn-primary-rl { padding: 8px 16px; font-size: 0.82rem; }
    .info-card-body { padding: 16px; }
    .info-card-header { padding: 14px 16px; }
    .order-card-v2 { padding: 14px 16px; flex-direction: column; align-items: flex-start; }
    .ocv2-actions { width: 100%; justify-content: flex-start; }
    .seller-cta { padding: 20px; flex-direction: column; text-align: center; align-items: center; }
    .seller-cta h3 { font-size: 1.1rem; }
    .quick-actions-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .quick-action-card { padding: 14px 10px; font-size: 0.78rem; }
    .quick-action-card .qa-icon { width: 40px; height: 40px; font-size: 1.1rem; }
    .detail-row { flex-direction: column; gap: 4px; }
    .detail-label { width: auto; }
    .avatar-upload-wrap { flex-direction: column; text-align: center; }
    .empty-state { padding: 40px 16px; }
    .wishlist-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
}
@media (max-width: 500px) {
    .profile-shell { padding: 6px 10px 80px; }
    .stats-row { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .stat-card-v2 { padding: 14px; }
    .stat-val { font-size: 1.4rem; }
    .stat-icon-v2 { width: 36px; height: 36px; font-size: 1rem; }
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
    .section-header h1 { font-size: 1.2rem; }
    .section-header p { font-size: 0.8rem; }
    .mobile-profile-nav .mpn-item { padding: 7px 10px; font-size: 0.74rem; }
    .info-card-body { padding: 12px; }
    .ocv2-id { font-size: 0.9rem; }
    .listings-table th, .listings-table td { padding: 10px 10px; font-size: 0.8rem; }
}
</style>

<div class="profile-shell">

    <!-- ===== SIDEBAR ===== -->
    <aside class="profile-sidebar">
        <!-- User Card -->
        <div class="sidebar-user-card">
            <div class="sidebar-avatar" 
                 <?php if(!empty($user['avatar'])) echo 'style="background-image: url('.htmlspecialchars($user['avatar']).'); color: transparent;"'; ?>>
                <?php if(empty($user['avatar'])) echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="sidebar-user-name">
                <?php echo htmlspecialchars($user['name'] ?? ''); ?>
                <?php if(!empty($user['is_verified'])): ?>
                    <i class="fas fa-check-circle" style="color:var(--accent-red);font-size:0.9rem;" title="Verified"></i>
                <?php endif; ?>
            </div>
            <div class="sidebar-user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <span class="sidebar-role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
        </div>

        <!-- Main Navigation -->
        <div class="sidebar-section-label">My Account</div>
        <button class="sidebar-nav-item <?php echo $activeSection == 'overview' ? 'active' : ''; ?>" onclick="showSection('overview')">
            <i class="fas fa-chart-pie"></i> Overview
        </button>
        <button class="sidebar-nav-item <?php echo $activeSection == 'orders' ? 'active' : ''; ?>" onclick="showSection('orders')">
            <i class="fas fa-box-open"></i> My Orders
            <?php if($orderCount > 0): ?><span class="nav-badge"><?php echo $orderCount; ?></span><?php endif; ?>
        </button>
        <button class="sidebar-nav-item <?php echo $activeSection == 'wishlist' ? 'active' : ''; ?>" onclick="showSection('wishlist')">
            <i class="fas fa-heart"></i> Wishlist
            <?php if($wishlistCount > 0): ?><span class="nav-badge"><?php echo $wishlistCount; ?></span><?php endif; ?>
        </button>

        <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
        <div class="sidebar-section-label" style="margin-top:8px;">Seller</div>
        <button class="sidebar-nav-item <?php echo $activeSection == 'listings' ? 'active' : ''; ?>" onclick="showSection('listings')">
            <i class="fas fa-tags"></i> My Listings
            <?php if($listingCount > 0): ?><span class="nav-badge"><?php echo $listingCount; ?></span><?php endif; ?>
        </button>
        <a href="<?php echo $user['role'] === 'admin' ? 'admin/index.php' : 'seller_dashboard/index.php'; ?>" class="sidebar-nav-item">
            <i class="fas fa-chart-line"></i> Dashboard
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label" style="margin-top:8px;">Settings</div>
        <button class="sidebar-nav-item <?php echo $activeSection == 'profile' ? 'active' : ''; ?>" onclick="showSection('profile')">
            <i class="fas fa-user-edit"></i> Edit Profile
        </button>
        <button class="sidebar-nav-item <?php echo $activeSection == 'security' ? 'active' : ''; ?>" onclick="showSection('security')">
            <i class="fas fa-shield-alt"></i> Security & Access
        </button>
        <button class="sidebar-nav-item <?php echo $activeSection == 'location' ? 'active' : ''; ?>" onclick="showSection('location')">
            <i class="fas fa-map-marker-alt"></i> Store Location
        </button>
        <button class="sidebar-nav-item <?php echo $activeSection == 'payment' ? 'active' : ''; ?>" onclick="showSection('payment')">
            <i class="fas fa-university"></i> Payment Details
        </button>

        <div class="sidebar-divider"></div>
        <a href="seller.php?id=<?php echo $userId; ?>" class="sidebar-nav-item">
            <i class="fas fa-user-circle"></i> Public Profile
        </a>
        <a href="CONTACT.php" class="sidebar-nav-item">
            <i class="fas fa-headset"></i> Contact Us
        </a>
        <a href="logout.php" class="sidebar-nav-item" style="color:#e57373 !important;">
            <i class="fas fa-sign-out-alt" style="color:#e57373;"></i> Logout
        </a>
    </aside>

    <!-- ===== MOBILE TOP NAV ===== -->
    <div class="mobile-profile-nav">
        <button class="mpn-item <?php echo $activeSection=='overview'?'active':''; ?>" onclick="showSection('overview')"><i class="fas fa-chart-pie"></i> Overview</button>
        <button class="mpn-item <?php echo $activeSection=='orders'?'active':''; ?>" onclick="showSection('orders')"><i class="fas fa-box"></i> Orders</button>
        <button class="mpn-item <?php echo $activeSection=='wishlist'?'active':''; ?>" onclick="showSection('wishlist')"><i class="fas fa-heart"></i> Wishlist</button>
        <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
        <button class="mpn-item <?php echo $activeSection=='listings'?'active':''; ?>" onclick="showSection('listings')"><i class="fas fa-tags"></i> Listings</button>
        <?php endif; ?>
        <button class="mpn-item <?php echo $activeSection=='profile'?'active':''; ?>" onclick="showSection('profile')"><i class="fas fa-user-edit"></i> Profile</button>
        <button class="mpn-item <?php echo $activeSection=='security'?'active':''; ?>" onclick="showSection('security')"><i class="fas fa-shield-alt"></i> Security</button>
        <button class="mpn-item <?php echo $activeSection=='location'?'active':''; ?>" onclick="showSection('location')"><i class="fas fa-map-marker-alt"></i> Location</button>
        <button class="mpn-item <?php echo $activeSection=='payment'?'active':''; ?>" onclick="showSection('payment')"><i class="fas fa-university"></i> Payment</button>
    </div>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="profile-main">

        <!-- Global Alerts -->
        <?php if (isset($_GET['missing_payment']) && $_GET['missing_payment'] == '1'): ?>
        <div class="profile-alert warning">
            <i class="fas fa-exclamation-triangle"></i>
            <span>You must add UPI ID or Bank Details before accessing the Seller Dashboard.</span>
            <button onclick="showSection('payment')" class="btn-primary-rl" style="margin-left:auto;padding:6px 14px;font-size:0.8rem;">Add Now</button>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="profile-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="profile-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- ===========================
             SECTION: OVERVIEW
        ============================ -->
        <div id="sec-overview" class="profile-section <?php echo $activeSection=='overview'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>Overview</h1>
                    <p>Welcome back, <?php echo htmlspecialchars(explode(' ', $user['name'] ?? 'Collector')[0]); ?>! Here's your activity summary.</p>
                </div>
                <a href="seller.php?id=<?php echo $userId; ?>" class="btn-outline-rl"><i class="fas fa-user-circle"></i> Public Profile</a>
            </div>

            <?php if($user['role'] === 'buyer'): ?>
            <!-- Seller CTA -->
            <div class="seller-cta" data-aos="fade-up">
                <div>
                    <h3 style="margin:0 0 8px;font-family:var(--font-display);"><i class="fas fa-store" style="color:var(--accent-red);margin-right:8px;"></i>Ready to clear your collection?</h3>
                    <p style="margin:0;color:var(--text-secondary);max-width:500px;">Apply to become a Verified Seller and list your diecast models to thousands of collectors across India.</p>
                </div>
                <a href="apply_seller.php" class="btn-primary-rl"><i class="fas fa-id-card"></i> Apply Now</a>
            </div>
            <?php endif; ?>

            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-card-v2 blue">
                    <div class="stat-icon-v2 blue"><i class="fas fa-tags"></i></div>
                    <div>
                        <div class="stat-val"><?php echo number_format($listingCount); ?></div>
                        <div class="stat-label">Active Listings</div>
                    </div>
                </div>
                <div class="stat-card-v2 green">
                    <div class="stat-icon-v2 green"><i class="fas fa-box-open"></i></div>
                    <div>
                        <div class="stat-val"><?php echo number_format($orderCount); ?></div>
                        <div class="stat-label">Orders Placed</div>
                    </div>
                </div>
                <div class="stat-card-v2 purple">
                    <div class="stat-icon-v2 purple"><i class="fas fa-rupee-sign"></i></div>
                    <div>
                        <div class="stat-val">₹<?php echo number_format($totalSpent); ?></div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                </div>
                <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
                <div class="stat-card-v2 gold">
                    <div class="stat-icon-v2 gold"><i class="fas fa-chart-bar"></i></div>
                    <div>
                        <div class="stat-val"><?php echo number_format($salesCount); ?></div>
                        <div class="stat-label">Sales Made</div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="stat-card-v2 teal">
                    <div class="stat-icon-v2 teal"><i class="fas fa-heart"></i></div>
                    <div>
                        <div class="stat-val"><?php echo number_format($wishlistCount); ?></div>
                        <div class="stat-label">Wishlist Items</div>
                    </div>
                </div>
                <div class="stat-card-v2 red">
                    <div class="stat-icon-v2 red"><i class="fas fa-life-ring"></i></div>
                    <div>
                        <div class="stat-val"><?php echo number_format($disputeCount); ?></div>
                        <div class="stat-label">Support Tickets</div>
                    </div>
                </div>
            </div>

            <!-- Personal Details -->
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-user"></i> Personal Details</h3>
                    <button onclick="showSection('profile')" class="btn-outline-rl" style="padding:7px 14px;font-size:0.8rem;"><i class="fas fa-pen"></i> Edit</button>
                </div>
                <div class="info-card-body">
                    <div class="detail-row">
                        <div class="detail-label">Full Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user['name'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email Address</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone Number</div>
                        <div class="detail-value <?php echo empty($user['phone'])?'empty':''; ?>">
                            <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : 'Not provided'; ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Member Since</div>
                        <div class="detail-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Account Type</div>
                        <div class="detail-value">
                            <span class="sidebar-role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Bio</div>
                        <div class="detail-value <?php echo empty($user['bio'])?'empty':''; ?>">
                            <?php echo !empty($user['bio']) ? nl2br(htmlspecialchars($user['bio'])) : 'No bio added yet.'; ?>
                        </div>
                    </div>
                    <?php if(!empty($user['store_location'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Location</div>
                        <div class="detail-value"><i class="fas fa-map-marker-alt" style="color:var(--accent-red);margin-right:6px;"></i><?php echo htmlspecialchars($user['store_location']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Orders Preview -->
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-clock"></i> Recent Orders</h3>
                    <button onclick="showSection('orders')" class="btn-outline-rl" style="padding:7px 14px;font-size:0.8rem;">View All <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="info-card-body" style="padding:0;">
                    <?php if(!empty($recentOrders)): ?>
                    <div style="overflow-x:auto;">
                        <table class="listings-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentOrders as $ro): ?>
                                <?php
                                    $sc='#ccc'; $sb='rgba(255,255,255,0.08)'; $si='circle';
                                    if($ro['status']==='delivered'){ $sc='#81c784'; $sb='rgba(76,175,80,0.12)'; $si='box-open'; }
                                    elseif($ro['status']==='shipped'){ $sc='#4fc3f7'; $sb='rgba(79,195,247,0.12)'; $si='truck'; }
                                    elseif($ro['status']==='cancelled'){ $sc='#e57373'; $sb='rgba(229,57,53,0.12)'; $si='times-circle'; }
                                    elseif($ro['status']==='confirmed'){ $sc='#6ee7b7'; $sb='rgba(16,185,129,0.12)'; $si='check-double'; }
                                    else { $si='clock'; }
                                ?>
                                <tr>
                                    <td style="font-weight:700;">#<?php echo $ro['id']; ?></td>
                                    <td style="color:var(--text-muted);font-size:0.83rem;"><?php echo date('M d, Y', strtotime($ro['created_at'])); ?></td>
                                    <td>
                                        <span class="ocv2-badge" style="color:<?php echo $sc;?>;background:<?php echo $sb;?>;">
                                            <i class="fas fa-<?php echo $si;?>"></i> <?php echo ucfirst($ro['status']); ?>
                                        </span>
                                    </td>
                                    <td style="font-weight:700;">₹<?php echo number_format($ro['total'],0); ?></td>
                                    <td><a href="order_view.php" class="ocv2-btn">Track</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state" style="border:none;border-radius:0;">
                        <i class="fas fa-box-open"></i>
                        <h3>No orders yet</h3>
                        <p>Start shopping to see your orders here.</p>
                        <a href="browse.php" class="btn-primary-rl">Browse Listings</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="info-card-body">
                    <div class="quick-actions-grid">
                        <a href="browse.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-search" style="color:#64b5f6;"></i></div>
                            Browse Listings
                        </a>
                        <a href="wishlist.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-heart" style="color:#ef9a9a;"></i></div>
                            My Wishlist
                        </a>
                        <a href="order_view.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-truck" style="color:#81c784;"></i></div>
                            Track Orders
                        </a>
                        <a href="negotiate.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-comments" style="color:#ba68c8;"></i></div>
                            Negotiations
                        </a>
                        <a href="disputes.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-life-ring" style="color:#ffb74d;"></i></div>
                            Support Tickets
                        </a>
                        <a href="notifications.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-bell" style="color:#4dd0e1;"></i></div>
                            Notifications
                        </a>
                        <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
                        <a href="seller_dashboard/add_listing.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-plus-circle" style="color:var(--accent-red);"></i></div>
                            New Listing
                        </a>
                        <a href="<?php echo $user['role']==='admin'?'admin/index.php':'seller_dashboard/index.php'; ?>" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-chart-line" style="color:#ffd54f;"></i></div>
                            Dashboard
                        </a>
                        <?php else: ?>
                        <a href="apply_seller.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-store" style="color:var(--accent-red);"></i></div>
                            Become Seller
                        </a>
                        <?php endif; ?>
                        <a href="CONTACT.php" class="quick-action-card">
                            <div class="qa-icon"><i class="fas fa-headset" style="color:#6ee7b7;"></i></div>
                            Contact Us
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             SECTION: MY ORDERS
        ============================ -->
        <div id="sec-orders" class="profile-section <?php echo $activeSection=='orders'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>My Orders</h1>
                    <p><?php echo $orderCount; ?> total orders placed</p>
                </div>
                <a href="order_view.php" class="btn-primary-rl"><i class="fas fa-history"></i> Full History</a>
            </div>

            <?php if(!empty($myOrders)): ?>
            <?php foreach($myOrders as $mo): ?>
            <?php
                $os=$mo['status'];
                $sc='#ccc'; $sb='rgba(255,255,255,0.08)'; $si='circle';
                if($os==='delivered'){$sc='#81c784';$sb='rgba(76,175,80,0.12)';$si='box-open';}
                elseif($os==='shipped'){$sc='#4fc3f7';$sb='rgba(79,195,247,0.12)';$si='truck';}
                elseif($os==='cancelled'){$sc='#e57373';$sb='rgba(229,57,53,0.12)';$si='times-circle';}
                elseif($os==='confirmed'){$sc='#6ee7b7';$sb='rgba(16,185,129,0.12)';$si='check-double';}
                else{$si='clock';}
            ?>
            <div class="order-card-v2">
                <div>
                    <div class="ocv2-id">Order #<?php echo $mo['id']; ?></div>
                    <div class="ocv2-date"><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y • h:i A', strtotime($mo['created_at'])); ?></div>
                </div>
                <div class="ocv2-actions">
                    <div class="ocv2-price">₹<?php echo number_format($mo['total'],0); ?></div>
                    <span class="ocv2-badge" style="color:<?php echo $sc;?>;background:<?php echo $sb;?>;"><i class="fas fa-<?php echo $si;?>"></i> <?php echo ucfirst($os); ?></span>
                    <?php if(!empty($mo['seller_id'])): ?>
                    <a href="start_chat.php?seller_id=<?php echo $mo['seller_id']; ?>" class="ocv2-btn" style="color:#60a5fa;border-color:rgba(96,165,250,0.2);"><i class="far fa-comment-dots"></i> Chat</a>
                    <?php endif; ?>
                    <a href="order_view.php" class="ocv2-btn primary">Track <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No orders yet</h3>
                <p>You haven't placed any orders. Start exploring our collection!</p>
                <a href="browse.php" class="btn-primary-rl">Start Shopping</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===========================
             SECTION: WISHLIST
        ============================ -->
        <div id="sec-wishlist" class="profile-section <?php echo $activeSection=='wishlist'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>My Wishlist</h1>
                    <p><?php echo $wishlistCount; ?> saved items</p>
                </div>
                <a href="wishlist.php" class="btn-primary-rl"><i class="fas fa-heart"></i> Full Wishlist</a>
            </div>
            <?php if(!empty($wishlistItems)): ?>
            <div class="wishlist-grid">
                <?php foreach($wishlistItems as $wi): ?>
                <a href="listing.php?id=<?php echo $wi['listing_id']; ?>" class="wishlist-card">
                    <div class="wishlist-card-img">
                        <?php if(!empty($wi['image'])): ?>
                        <img src="<?php echo htmlspecialchars($wi['image']); ?>" alt="<?php echo htmlspecialchars($wi['title']); ?>">
                        <?php else: ?>
                        <i class="fas fa-car no-img-icon"></i>
                        <?php endif; ?>
                        <?php if($wi['listing_status'] === 'sold'): ?>
                        <div style="position:absolute;top:8px;right:8px;background:rgba(229,57,53,0.85);color:#fff;font-size:0.65rem;font-weight:700;padding:3px 8px;border-radius:6px;text-transform:uppercase;">Sold</div>
                        <?php endif; ?>
                    </div>
                    <div class="wishlist-card-info">
                        <div class="wishlist-card-title"><?php echo htmlspecialchars($wi['title']); ?></div>
                        <div class="wishlist-card-price">₹<?php echo number_format($wi['price'],0); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if($wishlistCount > 6): ?>
            <div style="text-align:center;margin-top:20px;">
                <a href="wishlist.php" class="btn-outline-rl">View All <?php echo $wishlistCount; ?> Items</a>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-heart"></i>
                <h3>Wishlist is empty</h3>
                <p>Save items you love to find them easily later.</p>
                <a href="browse.php" class="btn-primary-rl">Explore Listings</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===========================
             SECTION: MY LISTINGS
        ============================ -->
        <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
        <div id="sec-listings" class="profile-section <?php echo $activeSection=='listings'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>My Listings</h1>
                    <p><?php echo $listingCount; ?> total listings</p>
                </div>
                <a href="seller_dashboard/add_listing.php" class="btn-primary-rl"><i class="fas fa-plus"></i> New Listing</a>
            </div>
            <?php if(!empty($myListings)): ?>
            <div class="info-card">
                <div class="listings-table-wrap">
                    <table class="listings-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Views</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($myListings as $ml): ?>
                            <?php
                                $sColor='#ccc';$sBg='rgba(255,255,255,0.08)';
                                if($ml['status']==='active'){$sColor='#81c784';$sBg='rgba(76,175,80,0.12)';}
                                elseif($ml['status']==='sold'){$sColor='#4fc3f7';$sBg='rgba(79,195,247,0.12)';}
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <?php if(!empty($ml['image'])): ?>
                                        <img src="<?php echo htmlspecialchars($ml['image']); ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                                        <?php else: ?>
                                        <div style="width:44px;height:44px;border-radius:8px;background:var(--bg-surface);display:flex;align-items:center;justify-content:center;color:#555;flex-shrink:0;"><i class="fas fa-car"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <a href="listing.php?id=<?php echo $ml['id']; ?>" style="color:var(--text-primary);font-weight:600;text-decoration:none;font-size:0.9rem;"><?php echo htmlspecialchars($ml['title']); ?></a>
                                            <div style="font-size:0.74rem;color:var(--text-muted);margin-top:2px;"><?php echo htmlspecialchars($ml['category_name'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="font-weight:700;">₹<?php echo number_format($ml['price'],0); ?></td>
                                <td>
                                    <span style="color:<?php echo $sColor;?>;background:<?php echo $sBg;?>;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;"><?php echo $ml['status']; ?></span>
                                </td>
                                <td style="color:var(--text-secondary);"><?php echo number_format($ml['views']); ?></td>
                                <td>
                                    <div style="display:flex;gap:8px;">
                                        <a href="listing.php?id=<?php echo $ml['id']; ?>" class="ocv2-btn" title="View"><i class="fas fa-eye"></i></a>
                                        <a href="seller_dashboard/edit_listing.php?id=<?php echo $ml['id']; ?>" class="ocv2-btn" title="Edit"><i class="fas fa-pen"></i></a>
                                        <a href="profile.php?delete_listing=<?php echo $ml['id']; ?>&section=listings" onclick="return confirm('Delete this listing permanently?');" class="ocv2-btn" style="color:#e57373;border-color:rgba(229,57,53,0.2);" title="Delete"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-tags"></i>
                <h3>No listings yet</h3>
                <p>Start selling your diecast collection on REDLINE.</p>
                <a href="seller_dashboard/add_listing.php" class="btn-primary-rl">Create First Listing</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ===========================
             SECTION: EDIT PROFILE
        ============================ -->
        <div id="sec-profile" class="profile-section <?php echo $activeSection=='profile'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>Edit Profile</h1>
                    <p>Update your personal information and public-facing details.</p>
                </div>
            </div>

            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                </div>
                <div class="info-card-body">
                    <form method="POST" action="profile.php" enctype="multipart/form-data" id="profileForm">
                        <input type="hidden" name="update_profile" value="1">

                        <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
                        <!-- Seller redirect to storefront -->
                        <div style="background:linear-gradient(135deg,rgba(229,57,53,0.08),rgba(229,57,53,0.03));border:1px solid rgba(229,57,53,0.2);border-radius:14px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:14px;">
                                <div style="width:44px;height:44px;border-radius:12px;background:rgba(229,57,53,0.12);display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-paint-brush" style="color:var(--accent-red);font-size:1.1rem;"></i>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 4px;font-size:0.95rem;">Storefront Settings Moved</h4>
                                    <p style="margin:0;font-size:0.82rem;color:var(--text-muted);">Update your store name, banner, bio, location & socials from the Seller Dashboard.</p>
                                </div>
                            </div>
                            <a href="seller_dashboard/storefront.php" class="btn-primary-rl" style="white-space:nowrap;"><i class="fas fa-external-link-alt"></i> Update Storefront</a>
                        </div>
                        <?php else: ?>
                        <!-- Avatar & Banner uploads (buyers only) -->
                        <div class="avatar-upload-wrap">
                            <div class="avatar-preview-lg" id="avatarPreview"
                                 <?php if(!empty($user['avatar'])) echo 'style="background-image:url('.htmlspecialchars($user['avatar']).');color:transparent;"'; ?>>
                                <?php if(empty($user['avatar'])) echo strtoupper(substr($user['name']??'U',0,1)); ?>
                            </div>
                            <div class="avatar-upload-info">
                                <p>Profile photo (square, 400×400px recommended)</p>
                                <label class="file-upload-btn">
                                    <i class="fas fa-camera"></i> Change Photo
                                    <input type="file" name="avatar" accept="image/*" onchange="previewAvatar(this)">
                                </label>
                            </div>
                        </div>

                        <div class="form-field full" style="margin-bottom:20px;">
                            <label>Cover Banner</label>
                            <div style="border:1px dashed var(--border-color);border-radius:10px;padding:16px;display:flex;align-items:center;gap:12px;background:rgba(255,255,255,0.02);">
                                <i class="fas fa-image" style="font-size:1.5rem;color:var(--text-muted);"></i>
                                <div style="flex:1;">
                                    <input type="file" name="banner" accept="image/*" style="background:transparent;border:none;padding:0;color:var(--text-secondary);font-size:0.85rem;width:100%;">
                                    <div class="form-hint">Wide banner image, 1920×400px recommended</div>
                                </div>
                                <?php if(!empty($user['banner'])): ?>
                                <img src="<?php echo htmlspecialchars($user['banner']); ?>" style="height:40px;border-radius:6px;object-fit:cover;width:80px;">
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-grid">
                            <div class="form-field">
                                <label for="pf-name">Full Name *</label>
                                <input type="text" id="pf-name" name="name" value="<?php echo htmlspecialchars($user['name']??''); ?>" required placeholder="Your full name">
                            </div>
                            <div class="form-field">
                                <label for="pf-phone">Phone Number</label>
                                <input type="tel" id="pf-phone" name="phone" value="<?php echo htmlspecialchars($user['phone']??''); ?>" placeholder="+91 9876543210">
                            </div>
                            <div class="form-field full">
                                <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
                                <label for="pf-bio">Bio / About Me <span style="font-size:0.72rem;color:var(--text-muted);font-weight:400;">(manage in <a href="seller_dashboard/storefront.php" style="color:var(--accent-red);">Storefront</a>)</span></label>
                                <textarea id="pf-bio" name="bio" rows="4" disabled placeholder="Managed via Seller Dashboard → Update Storefront"><?php echo htmlspecialchars($user['bio']??''); ?></textarea>
                                <?php else: ?>
                                <label for="pf-bio">Bio / About Me</label>
                                <textarea id="pf-bio" name="bio" rows="4" placeholder="Tell buyers about yourself and your collection..."><?php echo htmlspecialchars($user['bio']??''); ?></textarea>
                                <?php endif; ?>
                            </div>
                            <div class="form-field">
                                <label>Email Address</label>
                                <input type="email" value="<?php echo htmlspecialchars($user['email']??''); ?>" disabled>
                                <div class="form-hint">Email cannot be changed.</div>
                            </div>
                            <div class="form-field">
                                <label>Account Type</label>
                                <input type="text" value="<?php echo ucfirst($user['role']??'user'); ?>" disabled>
                            </div>
                        </div>

                        <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
                            <button type="submit" class="btn-primary-rl"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="reset" class="btn-outline-rl">Reset</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===========================
             SECTION: SECURITY
        ============================ -->
        <div id="sec-security" class="profile-section <?php echo $activeSection=='security'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>Security & Access</h1>
                    <p>Manage your password and account security settings.</p>
                </div>
            </div>

            <!-- Security Status Cards -->
            <div class="info-card" style="margin-bottom:20px;">
                <div class="info-card-header">
                    <h3><i class="fas fa-shield-check"></i> Security Overview</h3>
                </div>
                <div class="info-card-body">
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Password</h4>
                            <p>Use a strong, unique password to protect your account.</p>
                        </div>
                        <div class="security-status ok"><i class="fas fa-check-circle"></i> Active</div>
                    </div>
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Email Verification</h4>
                            <p><?php echo htmlspecialchars($user['email']??''); ?></p>
                        </div>
                        <div class="security-status <?php echo $user['is_verified']?'ok':'warn'; ?>">
                            <i class="fas fa-<?php echo $user['is_verified']?'check-circle':'exclamation-circle'; ?>"></i>
                            <?php echo $user['is_verified']?'Verified':'Not Verified'; ?>
                        </div>
                    </div>
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Account Role</h4>
                            <p>Current access level for this account.</p>
                        </div>
                        <span class="sidebar-role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                    </div>
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Member Since</h4>
                            <p>Your account creation date.</p>
                        </div>
                        <div class="security-status ok"><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($user['created_at'])); ?></div>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-key"></i> Change Password</h3>
                </div>
                <div class="info-card-body">
                    <form method="POST" action="profile.php" id="pwdForm">
                        <input type="hidden" name="update_password" value="1">
                        <div class="form-grid" style="grid-template-columns:1fr;">
                            <div class="form-field">
                                <label for="sec-cur-pwd">Current Password</label>
                                <div style="position:relative;">
                                    <input type="password" id="sec-cur-pwd" name="current_password" required placeholder="Enter current password" style="padding-right:44px;">
                                    <button type="button" onclick="togglePwd('sec-cur-pwd', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                            <div class="form-field">
                                <label for="sec-new-pwd">New Password</label>
                                <div style="position:relative;">
                                    <input type="password" id="sec-new-pwd" name="new_password" required minlength="6" placeholder="At least 6 characters" oninput="checkPwdStrength(this)" style="padding-right:44px;">
                                    <button type="button" onclick="togglePwd('sec-new-pwd', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;"><i class="fas fa-eye"></i></button>
                                </div>
                                <div class="pwd-strength-bar"><div class="pwd-strength-fill" id="pwdStrengthFill"></div></div>
                                <div class="pwd-strength-label" id="pwdStrengthLabel" style="color:var(--text-muted);"></div>
                            </div>
                            <div class="form-field">
                                <label for="sec-conf-pwd">Confirm New Password</label>
                                <div style="position:relative;">
                                    <input type="password" id="sec-conf-pwd" name="confirm_password" required minlength="6" placeholder="Repeat new password" style="padding-right:44px;">
                                    <button type="button" onclick="togglePwd('sec-conf-pwd', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:24px;">
                            <button type="submit" class="btn-primary-rl"><i class="fas fa-lock"></i> Change Password</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="info-card" style="border-color:rgba(229,57,53,0.2);">
                <div class="info-card-header" style="background:rgba(229,57,53,0.05);">
                    <h3><i class="fas fa-exclamation-triangle" style="color:#e57373;"></i> <span style="color:#e57373;">Danger Zone</span></h3>
                </div>
                <div class="info-card-body">
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Log Out of All Sessions</h4>
                            <p>Sign out from all active sessions except the current one.</p>
                        </div>
                        <a href="logout.php" class="btn-outline-rl" style="color:#e57373;border-color:rgba(229,57,53,0.3);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===========================
             SECTION: STORE LOCATION
        ============================ -->
        <div id="sec-location" class="profile-section <?php echo $activeSection=='location'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>Store Location</h1>
                    <p>Let buyers know where you're based. Shown on your public profile.</p>
                </div>
            </div>
            <?php if($user['role'] === 'seller' || $user['role'] === 'admin'): ?>
            <div class="info-card">
                <div class="info-card-body" style="text-align:center;padding:40px 24px;">
                    <i class="fas fa-map-marker-alt" style="font-size:2.5rem;color:var(--text-muted);opacity:0.25;margin-bottom:16px;display:block;"></i>
                    <h3 style="margin-bottom:8px;">Location Managed in Storefront</h3>
                    <p style="color:var(--text-secondary);margin-bottom:20px;font-size:0.9rem;">Store location is now managed from the Seller Dashboard storefront editor.</p>
                    <a href="seller_dashboard/storefront.php" class="btn-primary-rl"><i class="fas fa-paint-brush"></i> Update Storefront</a>
                </div>
            </div>
            <?php else: ?>
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-map-marker-alt"></i> Location Details</h3>
                </div>
                <div class="info-card-body">
                    <?php if(!empty($user['store_location'])): ?>
                    <div class="profile-alert" style="background:rgba(77,208,225,0.08);border:1px solid rgba(77,208,225,0.2);color:#4dd0e1;margin-bottom:20px;">
                        <i class="fas fa-map-pin"></i>
                        <span>Current location: <strong><?php echo htmlspecialchars($user['store_location']); ?></strong></span>
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="update_store_location" value="1">
                        <div class="form-field" style="max-width:500px;">
                            <label for="loc-field">Location (City, State)</label>
                            <input type="text" id="loc-field" name="store_location" value="<?php echo htmlspecialchars($user['store_location']??''); ?>" placeholder="e.g. Mumbai, Maharashtra">
                            <div class="form-hint">Optional. Shown publicly to help buyers find local sellers.</div>
                        </div>
                        <div style="margin-top:24px;">
                            <button type="submit" class="btn-primary-rl"><i class="fas fa-save"></i> Save Location</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===========================
             SECTION: PAYMENT DETAILS
        ============================ -->
        <div id="sec-payment" class="profile-section <?php echo $activeSection=='payment'?'active':''; ?>">
            <div class="section-header">
                <div>
                    <h1>Payment Details</h1>
                    <p>Required to receive payments when you sell on REDLINE.</p>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-university"></i> Bank & UPI Details</h3>
                </div>
                <div class="info-card-body">
                    <?php
                    $hasPayment = !empty($user['upi_id']) || !empty($user['bank_details']);
                    ?>
                    <?php if(!$hasPayment): ?>
                    <div class="profile-alert warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Add at least UPI ID or Bank Details to receive payments from buyers.</span>
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="profile.php" enctype="multipart/form-data">
                        <input type="hidden" name="update_profile" value="1">
                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($user['name']??''); ?>">
                        <div class="form-grid">
                            <div class="form-field">
                                <label for="pay-upi">UPI ID</label>
                                <input type="text" id="pay-upi" name="upi_id" value="<?php echo htmlspecialchars($user['upi_id']??''); ?>" placeholder="yourname@upi">
                                <div class="form-hint">Buyers will use this to send you payment.</div>
                            </div>
                            <div class="form-field">
                                <label style="display:flex;align-items:center;gap:6px;"><i class="fas fa-university" style="color:var(--text-muted);font-size:0.8rem;"></i> Bank Details</label>
                                <textarea name="bank_details" rows="5" placeholder="Account Name:&#10;Account Number:&#10;IFSC Code:&#10;Bank Name:"><?php echo htmlspecialchars($user['bank_details']??''); ?></textarea>
                                <div class="form-hint">Optional if UPI is provided.</div>
                            </div>
                        </div>
                        <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
                            <button type="submit" class="btn-primary-rl"><i class="fas fa-save"></i> Save Payment Details</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Status -->
            <div class="info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-info-circle"></i> Payment Status</h3>
                </div>
                <div class="info-card-body">
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>UPI ID</h4>
                            <p><?php echo !empty($user['upi_id']) ? htmlspecialchars($user['upi_id']) : 'Not configured'; ?></p>
                        </div>
                        <div class="security-status <?php echo !empty($user['upi_id'])?'ok':'warn'; ?>">
                            <i class="fas fa-<?php echo !empty($user['upi_id'])?'check-circle':'exclamation-circle'; ?>"></i>
                            <?php echo !empty($user['upi_id'])?'Configured':'Missing'; ?>
                        </div>
                    </div>
                    <div class="security-item">
                        <div class="security-item-info">
                            <h4>Bank Account</h4>
                            <p><?php echo !empty($user['bank_details']) ? 'Added' : 'Not configured'; ?></p>
                        </div>
                        <div class="security-status <?php echo !empty($user['bank_details'])?'ok':'warn'; ?>">
                            <i class="fas fa-<?php echo !empty($user['bank_details'])?'check-circle':'exclamation-circle'; ?>"></i>
                            <?php echo !empty($user['bank_details'])?'Configured':'Missing'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
// ===== SECTION ROUTING =====
function showSection(name) {
    document.querySelectorAll('.profile-section').forEach(s => s.classList.remove('active'));
    const sec = document.getElementById('sec-' + name);
    if (sec) sec.classList.add('active');

    // sidebar
    document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));
    // mobile nav
    document.querySelectorAll('.mpn-item').forEach(b => b.classList.remove('active'));

    // Match sidebar buttons by onclick
    document.querySelectorAll('.sidebar-nav-item[onclick]').forEach(b => {
        if (b.getAttribute('onclick') === "showSection('" + name + "')") {
            b.classList.add('active');
        }
    });
    document.querySelectorAll('.mpn-item[onclick]').forEach(b => {
        if (b.getAttribute('onclick') === "showSection('" + name + "')") {
            b.classList.add('active');
        }
    });

    // Update URL hash without reload
    history.replaceState(null, '', '?section=' + name);
}

// ===== PASSWORD SHOW/HIDE =====
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.innerHTML = isText ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
}

// ===== PASSWORD STRENGTH =====
function checkPwdStrength(input) {
    const val = input.value;
    const fill = document.getElementById('pwdStrengthFill');
    const label = document.getElementById('pwdStrengthLabel');
    let score = 0;
    if (val.length >= 6) score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;

    const levels = [
        { w: '20%', c: '#e57373', t: 'Very Weak' },
        { w: '40%', c: '#ffb74d', t: 'Weak' },
        { w: '60%', c: '#fff176', t: 'Fair' },
        { w: '80%', c: '#81c784', t: 'Strong' },
        { w: '100%', c: '#4caf50', t: 'Very Strong' },
    ];
    const l = levels[Math.min(score - 1, 4)] || { w: '0%', c: '#555', t: '' };
    fill.style.width = val.length ? l.w : '0%';
    fill.style.background = l.c;
    label.textContent = val.length ? l.t : '';
    label.style.color = l.c;
}

// ===== AVATAR PREVIEW =====
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const prev = document.getElementById('avatarPreview');
            prev.style.backgroundImage = 'url(' + e.target.result + ')';
            prev.style.color = 'transparent';
            prev.textContent = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ===== INIT SECTION on load =====
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const sec = urlParams.get('section');
    if (sec) {
        showSection(sec);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
