<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: applications.php');
    exit;
}

// Handle Form Submission (Approval/Rejection)
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');
    
    // Ensure we are processing a valid action
    if ($action === 'approve' || $action === 'reject') {
        try {
            $conn->beginTransaction();
            
            // 1. Fetch current application to get user ID
            $stmt = $conn->prepare("SELECT user_id, status FROM seller_applications WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $app = $stmt->fetch();
            
            if ($app && $app['status'] === 'pending') {
                $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
                
                // 2. Update Application Table
                $stmt = $conn->prepare("UPDATE seller_applications SET status = ?, admin_notes = ? WHERE id = ?");
                $stmt->execute([$newStatus, $notes, $id]);
                
                // 3. If Approved, Upgrade User Role and set is_verified
                if ($action === 'approve') {
                    $stmt = $conn->prepare("UPDATE users SET role = 'seller', is_verified = 1 WHERE id = ? AND role = 'buyer'");
                    $stmt->execute([$app['user_id']]);
                    
                    // Copy UPI ID from application to user profile
                    $stmtUpi = $conn->prepare("SELECT upi_id FROM seller_applications WHERE id = ?");
                    $stmtUpi->execute([$id]);
                    $appUpi = $stmtUpi->fetchColumn();
                    if (!empty($appUpi)) {
                        $stmt = $conn->prepare("UPDATE users SET upi_id = ? WHERE id = ?");
                        $stmt->execute([$appUpi, $app['user_id']]);
                    }
                }
                
                $conn->commit();
                $success = "Application successfully " . $newStatus . "!";
            } else {
                $conn->rollBack();
                $error = "This application has already been processed or does not exist.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Database error occurred during processing.";
        }
    }
}

// Fetch Application Details
try {
    $stmt = $conn->prepare("
        SELECT a.*, u.name, u.email, u.phone, u.created_at AS user_joined 
        FROM seller_applications a
        JOIN users u ON a.user_id = u.id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$app) {
        header('Location: applications.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: applications.php');
    exit;
}

$pageTitle = 'Review KYC Application #' . $id;
include 'header.php';
?>

<div class="admin-header">
    <div class="admin-header-title">
        <h1><a href="applications.php" style="color:var(--text-muted); text-decoration:none;"><i class="fas fa-arrow-left"></i></a> Application #<?php echo $id; ?></h1>
        <p>Applicant: <?php echo htmlspecialchars($app['name']); ?></p>
    </div>
    
    <div style="font-size: 1.2rem; font-weight: bold;">
        Status: 
        <?php if($app['status'] === 'pending'): ?>
            <span style="color:#ffb74d;">PENDING</span>
        <?php elseif($app['status'] === 'approved'): ?>
            <span style="color:#4caf50;">APPROVED</span>
        <?php else: ?>
            <span style="color:#e53935;">REJECTED</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
    <div style="background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #81c784; padding: 14px 20px; border-radius: 8px; margin-bottom: 20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e53935; padding: 14px 20px; border-radius: 8px; margin-bottom: 20px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php
// Helper function to render a document preview card
function renderDocCard($title, $path, $icon = 'fa-file-image', $badgeColor = '#ffb74d', $badgeText = '') {
    echo '<div class="admin-card" style="margin-bottom: 20px;">';
    echo '<h3 style="display:flex; align-items:center; gap:10px;"><i class="fas '.$icon.'" style="color:'.$badgeColor.'; font-size:1rem;"></i> '.$title;
    if ($badgeText) echo ' <span style="font-size:0.7rem; background:rgba(255,255,255,0.06); color:var(--text-muted); padding:2px 8px; border-radius:20px; font-weight:600;">'.$badgeText.'</span>';
    echo '</h3>';
    echo '<div style="margin-top:15px; border:1px solid var(--border-color); padding:10px; border-radius:8px; background:var(--bg-body); text-align:center;">';
    if (!empty($path)) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (strtolower($ext) === 'pdf') {
            echo '<embed src="../'.htmlspecialchars($path).'" type="application/pdf" width="100%" height="350px" />';
        } else {
            echo '<img src="../'.htmlspecialchars($path).'" style="max-width:100%; max-height:350px; border-radius:4px;">';
        }
        echo '<br><a href="../'.htmlspecialchars($path).'" target="_blank" class="btn-outline-white" style="margin-top:12px; display:inline-block; font-size:0.85rem;"><i class="fas fa-external-link-alt"></i> Open Full Size</a>';
    } else {
        echo '<div style="color:var(--text-muted); padding: 30px 0;"><i class="fas fa-file-excel" style="font-size:2.5rem; opacity:0.3; margin-bottom:10px; display:block;"></i>Not Provided</div>';
    }
    echo '</div></div>';
}
?>
<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 30px;">
    <!-- Left Col: Docs -->
    <div>
        <?php renderDocCard('Aadhaar Card — Front', $app['aadhar_path'] ?? null, 'fa-id-card', '#3b82f6', 'MANDATORY'); ?>
        <?php renderDocCard('Aadhaar Card — Back', $app['aadhar_back_path'] ?? null, 'fa-id-card', '#3b82f6', 'MANDATORY'); ?>
        
        <div class="admin-card" style="margin-bottom: 20px; border: 1px solid rgba(245,158,11,0.2);">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-camera" style="color:#f59e0b; font-size:1rem;"></i> Selfie with Aadhaar
                <span style="font-size:0.7rem; background:rgba(245,158,11,0.12); color:#f59e0b; padding:2px 8px; border-radius:20px; font-weight:700;">IDENTITY CHECK</span>
            </h3>
            <p style="font-size:0.8rem; color:var(--text-muted); margin-top:8px;">Applicant's photo holding their original Aadhaar card. Verify face matches and card is genuine.</p>
            <div style="margin-top:15px; border:1px solid var(--border-color); padding:10px; border-radius:8px; background:var(--bg-body); text-align:center;">
                <?php if(!empty($app['selfie_with_aadhar_path'])): ?>
                    <img src="../<?php echo htmlspecialchars($app['selfie_with_aadhar_path']); ?>" style="max-width:100%; max-height:400px; border-radius:4px;">
                    <br><a href="../<?php echo htmlspecialchars($app['selfie_with_aadhar_path']); ?>" target="_blank" class="btn-outline-white" style="margin-top:12px; display:inline-block; font-size:0.85rem;"><i class="fas fa-external-link-alt"></i> Open Full Size</a>
                <?php else: ?>
                    <div style="color:var(--text-muted); padding: 30px 0;"><i class="fas fa-user-slash" style="font-size:2.5rem; opacity:0.3; margin-bottom:10px; display:block;"></i>Selfie Not Provided</div>
                <?php endif; ?>
            </div>
        </div>

        <?php renderDocCard('PAN Card', $app['pan_path'] ?? null, 'fa-id-badge', '#10b981', 'OPTIONAL'); ?>
    </div>
    
    <!-- Right Col: Actions & Info -->
    <div>
        <div class="admin-card" style="margin-bottom: 30px;">
            <h3>User Profile</h3>
            <table class="admin-table" style="margin-top:20px;">
                <tr>
                    <td style="color:var(--text-muted); width: 120px;">Name</td>
                    <td style="font-weight:600; color:#fff;"><?php echo htmlspecialchars($app['name']); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted);">Email</td>
                    <td><?php echo htmlspecialchars($app['email']); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted);">Phone</td>
                    <td><?php echo htmlspecialchars($app['phone'] ?? 'None'); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted);">Joined On</td>
                    <td><?php echo date('M d, Y', strtotime($app['user_joined'])); ?></td>
                </tr>
                <?php if (!empty($app['upi_id'])): ?>
                <tr>
                    <td style="color:var(--text-muted);">UPI ID</td>
                    <td style="font-weight:600; color:#10b981;">
                        <i class="fas fa-wallet" style="margin-right:6px;"></i><?php echo htmlspecialchars($app['upi_id']); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <?php if($app['status'] === 'pending'): ?>
        <div class="admin-card">
            <h3 style="color:#ffb74d;"><i class="fas fa-gavel"></i> Administrator Verdict</h3>
            <p style="color:var(--text-secondary); margin-top:10px;">Review the documents carefully. If illegible or suspicious, reject with notes.</p>
            
            <form method="POST" style="margin-top: 20px;">
                <label style="display:block; margin-bottom:8px; color:var(--text-muted);">Rejection Notes (Optional, visible to user upon rejection)</label>
                <textarea name="admin_notes" rows="3" style="width:100%; background:var(--bg-body); border:1px solid var(--border-color); color:#fff; padding:10px; border-radius:6px; margin-bottom:20px;"></textarea>
                
                <div style="display:flex; gap:15px;">
                    <button type="submit" name="action" value="approve" class="btn" style="background:#4caf50; color:#fff; border:none; padding:12px 24px; border-radius:8px; font-weight:bold; flex:1; cursor:pointer;"><i class="fas fa-check"></i> APPROVE SELLER</button>
                    <button type="submit" name="action" value="reject" class="btn" style="background:transparent; color:#e53935; border:1px solid #e53935; padding:12px 24px; border-radius:8px; font-weight:bold; flex:1; cursor:pointer;"><i class="fas fa-times"></i> REJECT</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="admin-card">
            <h3>Archived Notes</h3>
            <p style="margin-top:10px; color:var(--text-secondary); background:var(--bg-body); padding:15px; border-radius:8px; border:1px solid var(--border-color);">
                <?php echo !empty($app['admin_notes']) ? nl2br(htmlspecialchars($app['admin_notes'])) : 'No administrative notes left.'; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
