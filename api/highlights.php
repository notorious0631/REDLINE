<?php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json');

// Auto-create tables if they don't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS seller_highlights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seller_id INT NOT NULL,
            title VARCHAR(100) NOT NULL DEFAULT 'Highlight',
            cover_image VARCHAR(500) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_seller (seller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS highlight_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            highlight_id INT NOT NULL,
            image_path VARCHAR(500) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_highlight (highlight_id),
            FOREIGN KEY (highlight_id) REFERENCES seller_highlights(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Public: Fetch highlights for a seller ──
if ($action === 'fetch' && isset($_GET['seller_id'])) {
    $sid = intval($_GET['seller_id']);
    try {
        $stmt = $conn->prepare("
            SELECT sh.*, 
                   (SELECT COUNT(*) FROM highlight_images WHERE highlight_id = sh.id) as image_count
            FROM seller_highlights sh
            WHERE sh.seller_id = ?
            ORDER BY sh.sort_order ASC, sh.created_at DESC
        ");
        $stmt->execute([$sid]);
        $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch images for each highlight
        foreach ($highlights as &$h) {
            $imgStmt = $conn->prepare("SELECT id, image_path, sort_order FROM highlight_images WHERE highlight_id = ? ORDER BY sort_order ASC, id ASC");
            $imgStmt->execute([$h['id']]);
            $h['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($h);

        echo json_encode(['success' => true, 'highlights' => $highlights]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// ── Auth check for all write operations ──
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Login required']);
    exit;
}

$userId = intval($_SESSION['user_id']);

// ── Create new highlight ──
if ($action === 'create') {
    $title = trim($_POST['title'] ?? 'Highlight');
    if (empty($title)) $title = 'Highlight';

    try {
        $stmt = $conn->prepare("INSERT INTO seller_highlights (seller_id, title) VALUES (?, ?)");
        $stmt->execute([$userId, $title]);
        $hid = $conn->lastInsertId();
        echo json_encode(['success' => true, 'highlight_id' => $hid]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to create highlight']);
    }
    exit;
}

// ── Update highlight title ──
if ($action === 'update_title') {
    $hid = intval($_POST['highlight_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    if (!$hid || !$title) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }
    try {
        $conn->prepare("UPDATE seller_highlights SET title = ? WHERE id = ? AND seller_id = ?")->execute([$title, $hid, $userId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed']);
    }
    exit;
}

// ── Upload images to a highlight ──
if ($action === 'upload_image') {
    $hid = intval($_POST['highlight_id'] ?? 0);
    if (!$hid) {
        echo json_encode(['success' => false, 'error' => 'Missing highlight ID']);
        exit;
    }

    // Verify ownership
    $stmt = $conn->prepare("SELECT id FROM seller_highlights WHERE id = ? AND seller_id = ?");
    $stmt->execute([$hid, $userId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No image uploaded']);
        exit;
    }

    $uploadDir = '../uploads/highlights/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
        exit;
    }

    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 5MB)']);
        exit;
    }

    $filename = 'hl_' . $hid . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        $imgPath = 'uploads/highlights/' . $filename;
        try {
            $conn->prepare("INSERT INTO highlight_images (highlight_id, image_path) VALUES (?, ?)")->execute([$hid, $imgPath]);
            $imgId = $conn->lastInsertId();

            // Set as cover if it's the first image
            $stmt = $conn->prepare("SELECT cover_image FROM seller_highlights WHERE id = ?");
            $stmt->execute([$hid]);
            $cover = $stmt->fetchColumn();
            if (empty($cover)) {
                $conn->prepare("UPDATE seller_highlights SET cover_image = ? WHERE id = ?")->execute([$imgPath, $hid]);
            }

            echo json_encode(['success' => true, 'image_id' => $imgId, 'image_path' => $imgPath]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'DB error']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
    }
    exit;
}

// ── Set cover image ──
if ($action === 'set_cover') {
    $hid = intval($_POST['highlight_id'] ?? 0);
    $imgPath = trim($_POST['image_path'] ?? '');
    if (!$hid || !$imgPath) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }
    try {
        $conn->prepare("UPDATE seller_highlights SET cover_image = ? WHERE id = ? AND seller_id = ?")->execute([$imgPath, $hid, $userId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed']);
    }
    exit;
}

// ── Delete an image ──
if ($action === 'delete_image') {
    $imgId = intval($_POST['image_id'] ?? 0);
    if (!$imgId) {
        echo json_encode(['success' => false, 'error' => 'Missing image ID']);
        exit;
    }
    try {
        // Get image info and verify ownership
        $stmt = $conn->prepare("
            SELECT hi.image_path, hi.highlight_id 
            FROM highlight_images hi
            JOIN seller_highlights sh ON hi.highlight_id = sh.id
            WHERE hi.id = ? AND sh.seller_id = ?
        ");
        $stmt->execute([$imgId, $userId]);
        $img = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$img) {
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }

        $conn->prepare("DELETE FROM highlight_images WHERE id = ?")->execute([$imgId]);

        // Delete file
        $filePath = '../' . $img['image_path'];
        if (file_exists($filePath)) @unlink($filePath);

        // Update cover if it was the cover
        $stmt = $conn->prepare("SELECT cover_image FROM seller_highlights WHERE id = ?");
        $stmt->execute([$img['highlight_id']]);
        if ($stmt->fetchColumn() === $img['image_path']) {
            $newCover = $conn->prepare("SELECT image_path FROM highlight_images WHERE highlight_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
            $newCover->execute([$img['highlight_id']]);
            $nc = $newCover->fetchColumn() ?: null;
            $conn->prepare("UPDATE seller_highlights SET cover_image = ? WHERE id = ?")->execute([$nc, $img['highlight_id']]);
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed']);
    }
    exit;
}

// ── Delete entire highlight ──
if ($action === 'delete') {
    $hid = intval($_POST['highlight_id'] ?? 0);
    if (!$hid) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }
    try {
        // Get all images to delete files
        $stmt = $conn->prepare("
            SELECT hi.image_path FROM highlight_images hi
            JOIN seller_highlights sh ON hi.highlight_id = sh.id
            WHERE sh.id = ? AND sh.seller_id = ?
        ");
        $stmt->execute([$hid, $userId]);
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($images as $ip) {
            $fp = '../' . $ip;
            if (file_exists($fp)) @unlink($fp);
        }

        $conn->prepare("DELETE FROM seller_highlights WHERE id = ? AND seller_id = ?")->execute([$hid, $userId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
