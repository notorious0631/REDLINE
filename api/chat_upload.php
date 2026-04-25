<?php
/**
 * REDLINE Chat v2 — Image Upload Handler
 * Handles image uploads for chat messages and payment proofs
 */
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$userId = intval($_SESSION['user_id']);
$convId = intval($_POST['conversation_id'] ?? 0);
$uploadType = $_POST['upload_type'] ?? 'image'; // 'image' or 'payment_proof'

if ($convId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation']);
    exit;
}

// Verify participant
$stmt = $conn->prepare("SELECT * FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
$stmt->execute([$convId, $userId, $userId]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$conv) {
    echo json_encode(['success' => false, 'error' => 'Conversation not found']);
    exit;
}

// Validate file
if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['image'];
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum 5MB allowed.']);
    exit;
}

// Validate type
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, WebP, and GIF images are allowed.']);
    exit;
}

// Create upload directory
$uploadDir = '../uploads/chat/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (!$ext) {
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext = $extMap[$mime] ?? 'jpg';
}
$filename = 'chat_' . $convId . '_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . $filename;
$dbPath = 'uploads/chat/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Insert message
$msgType = ($uploadType === 'payment_proof') ? 'payment_proof' : 'image';
$msgText = ($uploadType === 'payment_proof') ? 'Payment proof uploaded' : null;

$stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type, image_path) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$convId, $userId, $msgText, $msgType, $dbPath]);
$msgId = $conn->lastInsertId();

// Bump conversation
$conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$convId]);

echo json_encode([
    'success' => true,
    'message_id' => $msgId,
    'image_path' => $dbPath,
    'msg_type' => $msgType
]);
?>
