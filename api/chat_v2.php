<?php
/**
 * REDLINE Chat v2 API
 * Unified endpoint for all chat operations
 */
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$userId = intval($_SESSION['user_id']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: check if user is online (seen within 60s)
function isOnline($conn, $uid) {
    $stmt = $conn->prepare("SELECT last_seen FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['last_seen']) return ['online' => false, 'last_seen' => null];
    $diff = time() - strtotime($row['last_seen']);
    return ['online' => $diff <= 60, 'last_seen' => $row['last_seen']];
}

// Auto-expire disabled — negotiations stay active until manually accepted/rejected

switch ($action) {

    // ─── LIST conversations ───
    case 'list':
        $type = $_GET['type'] ?? 'buying';
        $validTypes = ['buying', 'selling', 'direct'];
        if (!in_array($type, $validTypes)) $type = 'buying';

        if ($type === 'selling') {
            $where = "c.seller_id = ? AND c.type IN ('buying','selling')";
        } elseif ($type === 'direct') {
            $where = "(c.buyer_id = ? OR c.seller_id = ?) AND c.type = 'direct'";
        } else {
            $where = "c.buyer_id = ? AND c.type IN ('buying','selling')";
        }

        // For direct chats: auto-delete conversations older than 62 days with no messages in 62 days
        if ($type === 'direct') {
            try {
                $conn->prepare("DELETE FROM conversations WHERE type = 'direct'
                    AND DATEDIFF(NOW(), updated_at) > 62
                    AND (buyer_id = ? OR seller_id = ?)")
                    ->execute([$userId, $userId]);
            } catch (PDOException $e) {}
        }

        $params = [$userId];
        if ($type === 'direct') $params[] = $userId; // for the OR clause

        $stmt = $conn->prepare("
            SELECT
                c.id, c.type, c.listing_id, c.buyer_id, c.seller_id,
                c.status, c.offered_price, c.expires_at, c.created_at, c.updated_at,
                l.title AS listing_title, l.image AS listing_image, l.price AS listing_price, l.status AS listing_status, l.stock AS listing_stock,
                buyer.name AS buyer_name, buyer.avatar AS buyer_avatar, buyer.last_seen AS buyer_last_seen,
                seller.name AS seller_name, seller.avatar AS seller_avatar, seller.last_seen AS seller_last_seen,
                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id AND cm.sender_id != ? AND cm.is_read = 0) AS unread_count,
                (SELECT COALESCE(
                    CASE
                        WHEN cm2.msg_type IN ('offer','counter') THEN CONCAT('💰 Offer: Rs.', FORMAT(cm2.offer_amount,0))
                        WHEN cm2.msg_type = 'accept' THEN '✅ Offer Accepted'
                        WHEN cm2.msg_type = 'reject' THEN '❌ Offer Declined'
                        WHEN cm2.msg_type = 'image' THEN '📷 Image'
                        WHEN cm2.msg_type = 'payment_proof' THEN '💳 Payment Proof'
                        WHEN cm2.msg_type = 'system' THEN CONCAT('ℹ ', LEFT(cm2.message, 40))
                        ELSE cm2.message
                    END, '')
                    FROM chat_messages cm2 WHERE cm2.conversation_id = c.id ORDER BY cm2.id DESC LIMIT 1
                ) AS last_message,
                (SELECT cm3.created_at FROM chat_messages cm3 WHERE cm3.conversation_id = c.id ORDER BY cm3.id DESC LIMIT 1) AS last_message_at
            FROM conversations c
            LEFT JOIN listings l ON c.listing_id = l.id
            LEFT JOIN users buyer ON c.buyer_id = buyer.id
            LEFT JOIN users seller ON c.seller_id = seller.id
            WHERE $where
            ORDER BY c.updated_at DESC
        ");

        // For unread_count subquery, need userId first, then the where params
        $allParams = array_merge([$userId], $params);
        $stmt->execute($allParams);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'conversations' => $conversations]);
        break;

    // ─── START a conversation ───
    case 'start':
        $listingId = intval($_POST['listing_id'] ?? 0);
        $directSellerId = intval($_POST['direct_seller_id'] ?? 0);

        if ($directSellerId > 0) {
            // Direct chat
            if ($directSellerId == $userId) {
                echo json_encode(['success' => false, 'error' => 'Cannot chat with yourself']);
                exit;
            }
            // Check existing
            $stmt = $conn->prepare("SELECT id FROM conversations WHERE type = 'direct'
                AND ((buyer_id = ? AND seller_id = ?) OR (buyer_id = ? AND seller_id = ?))");
            $stmt->execute([$userId, $directSellerId, $directSellerId, $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode(['success' => true, 'conversation_id' => $existing['id'], 'resumed' => true]);
            } else {
                $stmt = $conn->prepare("INSERT INTO conversations (type, buyer_id, seller_id) VALUES ('direct', ?, ?)");
                $stmt->execute([$userId, $directSellerId]);
                $newId = $conn->lastInsertId();
                // Welcome message
                $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type) VALUES (?, 0, ?, 'system')")
                     ->execute([$newId, 'Direct chat started. This conversation is permanent.']);
                echo json_encode(['success' => true, 'conversation_id' => $newId, 'resumed' => false]);
            }
        } elseif ($listingId > 0) {
            // Buying chat (negotiation)
            $stmt = $conn->prepare("SELECT id, seller_id, status FROM listings WHERE id = ?");
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$listing) {
                echo json_encode(['success' => false, 'error' => 'Listing not found']);
                exit;
            }
            if ($listing['seller_id'] == $userId) {
                echo json_encode(['success' => false, 'error' => 'Cannot negotiate on your own listing']);
                exit;
            }

            // Check existing active negotiation
            $stmt = $conn->prepare("SELECT id FROM conversations WHERE listing_id = ? AND buyer_id = ? AND type IN ('buying','selling') AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$listingId, $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode(['success' => true, 'conversation_id' => $existing['id'], 'resumed' => true]);
            } else {
                $stmt = $conn->prepare("INSERT INTO conversations (type, listing_id, buyer_id, seller_id) VALUES ('buying', ?, ?, ?)");
                $stmt->execute([$listingId, $userId, $listing['seller_id']]);
                $newId = $conn->lastInsertId();

                $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type) VALUES (?, ?, ?, 'text')")
                     ->execute([$newId, $userId, "Hi! I'm interested in this item and would like to negotiate."]);

                echo json_encode(['success' => true, 'conversation_id' => $newId, 'resumed' => false]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing listing_id or direct_seller_id']);
        }
        break;

    // ─── FETCH messages ───
    case 'fetch':
        $convId  = intval($_GET['conversation_id'] ?? 0);
        $afterId = intval($_GET['after_id'] ?? 0);

        if ($convId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid conversation']);
            exit;
        }

        // Verify participant
        $stmt = $conn->prepare("SELECT c.*, l.title AS listing_title, l.price AS listing_price, l.image AS listing_image, l.status AS listing_status, l.stock AS listing_stock,
                                       buyer.name AS buyer_name, buyer.avatar AS buyer_avatar, buyer.last_seen AS buyer_last_seen, buyer.id AS buyer_uid,
                                       seller.name AS seller_name, seller.avatar AS seller_avatar, seller.last_seen AS seller_last_seen, seller.id AS seller_uid
                                FROM conversations c
                                LEFT JOIN listings l ON c.listing_id = l.id
                                LEFT JOIN users buyer ON c.buyer_id = buyer.id
                                LEFT JOIN users seller ON c.seller_id = seller.id
                                WHERE c.id = ? AND (c.buyer_id = ? OR c.seller_id = ?)");
        $stmt->execute([$convId, $userId, $userId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        // Refresh status
        $stmt = $conn->prepare("SELECT status FROM conversations WHERE id = ?");
        $stmt->execute([$convId]);
        $freshStatus = $stmt->fetch(PDO::FETCH_ASSOC);
        $conv['status'] = $freshStatus['status'];

        // Check 2 months payment expiry
        $stmtPayment = $conn->prepare("SELECT created_at FROM chat_messages WHERE conversation_id = ? AND msg_type = 'payment_proof' ORDER BY id DESC LIMIT 1");
        $stmtPayment->execute([$convId]);
        $pp = $stmtPayment->fetch(PDO::FETCH_ASSOC);
        $conv['payment_proof_at'] = $pp ? $pp['created_at'] : null;
        $conv['is_payment_expired'] = false;
        if ($pp && (time() - strtotime($pp['created_at'])) > 60 * 86400) {
            $conv['is_payment_expired'] = true;
        }

        // Fetch messages
        $stmt = $conn->prepare("
            SELECT cm.*, u.name AS sender_name, u.avatar AS sender_avatar, u.id AS sender_uid
            FROM chat_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            WHERE cm.conversation_id = ? AND cm.id > ?
            ORDER BY cm.created_at ASC, cm.id ASC
        ");
        $stmt->execute([$convId, $afterId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark as read
        $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_read = 0")
             ->execute([$convId, $userId]);

        // Partner presence
        $partnerId = ($conv['buyer_id'] == $userId) ? $conv['seller_id'] : $conv['buyer_id'];
        $presence = isOnline($conn, $partnerId);

        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'conversation' => $conv,
            'partner_presence' => $presence,
            'current_user_id' => $userId
        ]);
        break;

    // ─── SEND message ───
    case 'send':
        $convId   = intval($_POST['conversation_id'] ?? 0);
        $message  = trim($_POST['message'] ?? '');
        $msgType  = $_POST['msg_type'] ?? 'text';
        $offerAmt = isset($_POST['offer_amount']) && $_POST['offer_amount'] !== '' ? floatval($_POST['offer_amount']) : null;
        $imgPath  = trim($_POST['image_path'] ?? '');

        $validTypes = ['text', 'image', 'offer', 'counter'];
        if (!in_array($msgType, $validTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid message type']);
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


        // Check 2 months payment expiry
        $stmtPayment = $conn->prepare("SELECT created_at FROM chat_messages WHERE conversation_id = ? AND msg_type = 'payment_proof' ORDER BY id DESC LIMIT 1");
        $stmtPayment->execute([$convId]);
        $pp = $stmtPayment->fetch(PDO::FETCH_ASSOC);
        if ($pp && (time() - strtotime($pp['created_at'])) > 60 * 86400) {
            echo json_encode(['success' => false, 'error' => 'This negotiation chat has expired (2 months since payment proof).']);
            exit;
        }

        // Offers only when active (and only for buying/selling chats)
        if (in_array($msgType, ['offer', 'counter'])) {
            if ($conv['status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Offers can only be made while negotiation is active']);
                exit;
            }
            if ($conv['type'] === 'direct') {
                echo json_encode(['success' => false, 'error' => 'Offers are not available in direct chats']);
                exit;
            }
            if ($offerAmt === null || $offerAmt <= 0) {
                echo json_encode(['success' => false, 'error' => 'Enter a valid offer amount']);
                exit;
            }
        }

        if ($msgType === 'text') {
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit;
            }
            if (containsBlockedLinks($message)) {
                echo json_encode(['success' => false, 'error' => 'WhatsApp group and Telegram links are not allowed.']);
                exit;
            }
        }

        if ($msgType === 'image' && empty($imgPath)) {
            echo json_encode(['success' => false, 'error' => 'Image path required']);
            exit;
        }

        // Insert message
        $stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type, offer_amount, image_path) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$convId, $userId, $message ?: null, $msgType, $offerAmt, $imgPath ?: null]);
        $newMsgId = $conn->lastInsertId();

        // Update conversation
        if (in_array($msgType, ['offer', 'counter'])) {
            $conn->prepare("UPDATE conversations SET offered_price = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([$offerAmt, $convId]);
        } else {
            $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")
                 ->execute([$convId]);
        }

        echo json_encode(['success' => true, 'message_id' => $newMsgId]);
        break;

    // ─── RESPOND to offer ───
    case 'respond':
        $convId   = intval($_POST['conversation_id'] ?? 0);
        $response = $_POST['response'] ?? '';

        if (!in_array($response, ['accept', 'reject'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid response']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$convId, $userId, $userId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }


        if ($conv['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'Negotiation already closed']);
            exit;
        }

        $label = $response === 'accept' ? 'Offer accepted! 🎉' : 'Offer declined.';
        $newStatus = $response === 'accept' ? 'accepted' : 'rejected';

        $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type, offer_amount) VALUES (?, ?, ?, ?, ?)")
             ->execute([$convId, $userId, $label, $response, $conv['offered_price']]);
        $conn->prepare("UPDATE conversations SET status = ?, updated_at = NOW() WHERE id = ?")
             ->execute([$newStatus, $convId]);

        echo json_encode(['success' => true, 'new_status' => $newStatus]);
        break;

    // ─── REPORT a chat ───
    case 'report':
        $convId = intval($_POST['conversation_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (empty($reason)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a reason']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$convId, $userId, $userId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        $reportedUser = ($conv['buyer_id'] == $userId) ? $conv['seller_id'] : $conv['buyer_id'];

        // Prevent duplicate reports
        $stmt = $conn->prepare("SELECT id FROM chat_reports WHERE conversation_id = ? AND reporter_id = ? AND status = 'pending'");
        $stmt->execute([$convId, $userId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You already have a pending report for this chat']);
            exit;
        }

        $conn->prepare("INSERT INTO chat_reports (conversation_id, reporter_id, reported_user_id, reason) VALUES (?, ?, ?, ?)")
             ->execute([$convId, $userId, $reportedUser, $reason]);

        echo json_encode(['success' => true, 'message' => 'Report submitted. Admin will review shortly.']);
        break;

    // ─── HEARTBEAT (online presence) ───
    case 'heartbeat':
        $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$userId]);
        echo json_encode(['success' => true]);
        break;

    // ─── PRESENCE check ───
    case 'presence':
        $targetId = intval($_GET['user_id'] ?? 0);
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user']);
            exit;
        }
        $p = isOnline($conn, $targetId);
        echo json_encode(['success' => true, 'online' => $p['online'], 'last_seen' => $p['last_seen']]);
        break;

    // ─── UNREAD COUNT (nav badge) ───
    case 'unread_count':
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT cm.conversation_id) AS count
            FROM chat_messages cm
            JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.sender_id != ? AND cm.is_read = 0
              AND (c.buyer_id = ? OR c.seller_id = ?)
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'count' => intval($r['count'])]);
        break;


    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
?>
