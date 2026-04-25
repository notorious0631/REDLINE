<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'pending';

try {
    $stmt = $conn->prepare("
        SELECT a.*, u.name, u.email 
        FROM seller_applications a
        JOIN users u ON a.user_id = u.id
        WHERE a.status = ?
        ORDER BY a.applied_at DESC
    ");
    $stmt->execute([$statusFilter]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $applications = [];
}

$pageTitle = 'Seller KYC Applications';
include 'header.php';
?>

<div class="admin-header">
    <div class="admin-header-title">
        <h1>Seller KYC Applications</h1>
        <p>Review user documents for merchant approval</p>
    </div>
</div>

<div class="admin-tabs" style="display:flex; gap:10px; margin-bottom: 30px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
    <a href="?status=pending" class="btn <?php echo $statusFilter === 'pending' ? 'btn-red' : 'btn-outline-white'; ?>" style="border-radius: 20px; padding: 6px 16px;">Pending Review</a>
    <a href="?status=approved" class="btn <?php echo $statusFilter === 'approved' ? 'btn-red' : 'btn-outline-white'; ?>" style="border-radius: 20px; padding: 6px 16px;">Approved</a>
    <a href="?status=rejected" class="btn <?php echo $statusFilter === 'rejected' ? 'btn-red' : 'btn-outline-white'; ?>" style="border-radius: 20px; padding: 6px 16px;">Rejected</a>
</div>

<div class="admin-card">
    <div style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Applicant</th>
                    <th>Email</th>
                    <th>Date Applied</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($applications)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px; color:var(--text-muted);">No <?php echo $statusFilter; ?> applications found.</td></tr>
                <?php else: foreach($applications as $app): ?>
                    <tr>
                        <td>#<?php echo $app['id']; ?></td>
                        <td style="font-weight:600; color:#fff;"><?php echo htmlspecialchars($app['name']); ?></td>
                        <td><?php echo htmlspecialchars($app['email']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($app['applied_at'])); ?></td>
                        <td>
                            <?php if($app['status'] === 'pending'): ?>
                                <span class="badge" style="background:#ffb74d; color:#000; padding:4px 8px; border-radius:4px; font-size:0.8rem;">PENDING</span>
                            <?php elseif($app['status'] === 'approved'): ?>
                                <span class="badge" style="background:#4caf50; color:#fff; padding:4px 8px; border-radius:4px; font-size:0.8rem;">APPROVED</span>
                            <?php else: ?>
                                <span class="badge" style="background:#e53935; color:#fff; padding:4px 8px; border-radius:4px; font-size:0.8rem;">REJECTED</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="application_view.php?id=<?php echo $app['id']; ?>" class="btn-outline-white" style="padding: 4px 10px; font-size:0.85rem;"><i class="fas fa-eye"></i> Review</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
