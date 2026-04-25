<?php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$seller_id = intval($_POST['seller_id'] ?? 0);
$follower_id = $_SESSION['user_id'];

if ($action === 'toggle' && $seller_id > 0) {
    if ($seller_id === $follower_id) {
        echo json_encode(['success' => false, 'message' => 'Cannot follow yourself']);
        exit;
    }
    
    try {
        // Check if currently following
        $stmt = $conn->prepare("SELECT id FROM user_follows WHERE follower_id = ? AND seller_id = ?");
        $stmt->execute([$follower_id, $seller_id]);
        $exists = $stmt->fetchColumn();
        
        $isFollowing = false;
        
        if ($exists) {
            // Unfollow
            $del = $conn->prepare("DELETE FROM user_follows WHERE id = ?");
            $del->execute([$exists]);
            $isFollowing = false;
        } else {
            // Follow
            $ins = $conn->prepare("INSERT INTO user_follows (follower_id, seller_id) VALUES (?, ?)");
            $ins->execute([$follower_id, $seller_id]);
            $isFollowing = true;
        }
        
        // Get new count
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM user_follows WHERE seller_id = ?");
        $countStmt->execute([$seller_id]);
        $newCount = $countStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'isFollowing' => $isFollowing,
            'count' => $newCount
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
