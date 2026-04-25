<?php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in first']);
    exit;
}

$action = $_POST['action'] ?? '';
$listing_id = intval($_POST['listing_id'] ?? 0);
$user_id = $_SESSION['user_id'];

if ($action === 'toggle' && $listing_id > 0) {
    try {
        // Check if already wishlisted
        $stmt = $conn->prepare("SELECT id FROM wishlists WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$user_id, $listing_id]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            // Remove from wishlist
            $conn->prepare("DELETE FROM wishlists WHERE id = ?")->execute([$exists]);
            echo json_encode(['success' => true, 'wishlisted' => false]);
        } else {
            // Add to wishlist
            $conn->prepare("INSERT INTO wishlists (user_id, listing_id) VALUES (?, ?)")->execute([$user_id, $listing_id]);
            echo json_encode(['success' => true, 'wishlisted' => true]);
        }
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
}

if ($action === 'remove' && $listing_id > 0) {
    try {
        $conn->prepare("DELETE FROM wishlists WHERE user_id = ? AND listing_id = ?")->execute([$user_id, $listing_id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
