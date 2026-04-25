<?php
/**
 * REDLINE Negotiation Chat API
 * AJAX endpoint for all chat operations
 */
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue']);
    exit;
}

$userId = intval($_SESSION['user_id']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ─── START or RESUME a negotiation ───
    case 'start':
        $listingId = intval($_POST['listing_id'] ?? 0);
        if ($listingId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid listing']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, seller_id, status FROM listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$listing) {
            echo json_encode(['success' => false, 'error' => 'Listing not found']);
            exit;
        }
        if ($listing['seller_id'] == $userId) {
            echo json_encode(['success' => false, 'error' => 'You cannot negotiate on your own listing']);
            exit;
        }
        if ($listing['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'This listing is no longer available']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM negotiations WHERE listing_id = ? AND buyer_id = ?");
        $stmt->execute([$listingId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode(['success' => true, 'negotiation_id' => $existing['id'], 'resumed' => true]);
        } else {
            $stmt = $conn->prepare("INSERT INTO negotiations (listing_id, buyer_id, seller_id) VALUES (?, ?, ?)");
            $stmt->execute([$listingId, $userId, $listing['seller_id']]);
            $negId = $conn->lastInsertId();

            $stmt = $conn->prepare("INSERT INTO negotiation_messages (negotiation_id, sender_id, message, msg_type) VALUES (?, ?, ?, 'text')");
            $stmt->execute([$negId, $userId, "Hi! I'm interested in this item and would like to negotiate the price."]);

            echo json_encode(['success' => true, 'negotiation_id' => $negId, 'resumed' => false]);
        }
        break;

    // ─── SEND a message or offer ───
    case 'send':
        $negId      = intval($_POST['negotiation_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');
        $msgType    = $_POST['msg_type'] ?? 'text';
        $offerAmount = isset($_POST['offer_amount']) && $_POST['offer_amount'] !== '' ? floatval($_POST['offer_amount']) : null;

        if ($negId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid negotiation']);
            exit;
        }

        // Validate message type
        $validTypes = ['text', 'offer', 'counter'];
        if (!in_array($msgType, $validTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid message type']);
            exit;
        }

        // Verify user is participant
        $stmt = $conn->prepare("SELECT * FROM negotiations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$negId, $userId, $userId]);
        $neg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$neg) {
            echo json_encode(['success' => false, 'error' => 'Negotiation not found']);
            exit;
        }

        // Only allow offers when active; always allow text
        if ($neg['status'] !== 'active' && $msgType !== 'text') {
            echo json_encode(['success' => false, 'error' => 'Offers can only be made while the negotiation is active']);
            exit;
        }

        // Validate content
        if ($msgType === 'text' && empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            exit;
        }
        if (in_array($msgType, ['offer', 'counter']) && ($offerAmount === null || $offerAmount <= 0)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid offer amount']);
            exit;
        }

        // Insert message
        $stmt = $conn->prepare("INSERT INTO negotiation_messages (negotiation_id, sender_id, message, msg_type, offer_amount) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$negId, $userId, $message ?: null, $msgType, $offerAmount]);
        $newMsgId = $conn->lastInsertId();

        // Update offered_price on negotiation record + bump updated_at
        if (in_array($msgType, ['offer', 'counter'])) {
            $stmt = $conn->prepare("UPDATE negotiations SET offered_price = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$offerAmount, $negId]);
        } else {
            // Bump updated_at so the list stays sorted by last activity
            $stmt = $conn->prepare("UPDATE negotiations SET updated_at = NOW() WHERE id = ?");
            $stmt->execute([$negId]);
        }

        echo json_encode(['success' => true, 'message_id' => $newMsgId]);
        break;

    // ─── RESPOND to an offer (accept/reject) ───
    case 'respond':
        $negId    = intval($_POST['negotiation_id'] ?? 0);
        $response = $_POST['response'] ?? '';

        if (!in_array($response, ['accept', 'reject'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid response type']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM negotiations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
        $stmt->execute([$negId, $userId, $userId]);
        $neg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$neg) {
            echo json_encode(['success' => false, 'error' => 'Negotiation not found or unauthorized']);
            exit;
        }

        if ($neg['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'Negotiation already closed']);
            exit;
        }

        // Insert the accept/reject system message
        $label     = $response === 'accept' ? 'Offer accepted! 🎉' : 'Offer declined.';
        $msgTypeDb = $response === 'accept' ? 'accept' : 'reject';

        $stmt = $conn->prepare("INSERT INTO negotiation_messages (negotiation_id, sender_id, message, msg_type, offer_amount) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$negId, $userId, $label, $msgTypeDb, $neg['offered_price']]);

        // Update negotiation status
        $newStatus = $response === 'accept' ? 'accepted' : 'rejected';
        $stmt = $conn->prepare("UPDATE negotiations SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $negId]);

        echo json_encode(['success' => true, 'new_status' => $newStatus]);
        break;

    // ─── FETCH messages (polling) ───
    case 'fetch':
        $negId   = intval($_GET['negotiation_id'] ?? 0);
        $afterId = intval($_GET['after_id'] ?? 0);

        if ($negId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid negotiation']);
            exit;
        }

        // Verify participant
        $stmt = $conn->prepare("SELECT n.*, l.title AS listing_title, l.price AS listing_price
                                FROM negotiations n
                                LEFT JOIN listings l ON n.listing_id = l.id
                                WHERE n.id = ? AND (n.buyer_id = ? OR n.seller_id = ?)");
        $stmt->execute([$negId, $userId, $userId]);
        $neg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$neg) {
            echo json_encode(['success' => false, 'error' => 'Negotiation not found']);
            exit;
        }

        // Fetch new messages since afterId
        $stmt = $conn->prepare("
            SELECT nm.id, nm.negotiation_id, nm.sender_id, nm.message, nm.msg_type,
                   nm.offer_amount, nm.is_read, nm.created_at,
                   u.name AS sender_name, u.avatar AS sender_avatar
            FROM negotiation_messages nm
            LEFT JOIN users u ON nm.sender_id = u.id
            WHERE nm.negotiation_id = ? AND nm.id > ?
            ORDER BY nm.created_at ASC, nm.id ASC
        ");
        $stmt->execute([$negId, $afterId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark messages from the other party as read
        $stmt = $conn->prepare("UPDATE negotiation_messages SET is_read = 1 WHERE negotiation_id = ? AND sender_id != ? AND is_read = 0");
        $stmt->execute([$negId, $userId]);

        echo json_encode([
            'success'         => true,
            'messages'        => $messages,
            'negotiation'     => $neg,
            'current_user_id' => $userId
        ]);
        break;

    // ─── LIST all negotiations for current user ───
    case 'list':
        $role = ($_GET['role'] ?? 'buyer') === 'seller' ? 'seller' : 'buyer';

        if ($role === 'seller') {
            $whereClause = "n.seller_id = ?";
        } else {
            $whereClause = "n.buyer_id = ?";
        }

        $stmt = $conn->prepare("
            SELECT
                n.id, n.listing_id, n.buyer_id, n.seller_id, n.status,
                n.offered_price, n.created_at, n.updated_at,
                l.title  AS listing_title,
                l.image  AS listing_image,
                l.price  AS listing_price,
                l.status AS listing_status,
                buyer.name   AS buyer_name,
                buyer.avatar AS buyer_avatar,
                seller.name   AS seller_name,
                seller.avatar AS seller_avatar,
                (
                    SELECT COUNT(*)
                    FROM negotiation_messages nm
                    WHERE nm.negotiation_id = n.id
                      AND nm.sender_id != ?
                      AND nm.is_read = 0
                ) AS unread_count,
                (
                    SELECT COALESCE(
                        CASE WHEN nm2.msg_type IN ('offer','counter') THEN CONCAT('Offer: Rs.', FORMAT(nm2.offer_amount,0))
                             WHEN nm2.msg_type = 'accept' THEN '✅ Offer Accepted'
                             WHEN nm2.msg_type = 'reject' THEN '❌ Offer Declined'
                             ELSE nm2.message
                        END, '')
                    FROM negotiation_messages nm2
                    WHERE nm2.negotiation_id = n.id
                    ORDER BY nm2.id DESC LIMIT 1
                ) AS last_message,
                (
                    SELECT nm3.created_at
                    FROM negotiation_messages nm3
                    WHERE nm3.negotiation_id = n.id
                    ORDER BY nm3.id DESC LIMIT 1
                ) AS last_message_at
            FROM negotiations n
            LEFT JOIN listings l       ON n.listing_id  = l.id
            LEFT JOIN users    buyer   ON n.buyer_id     = buyer.id
            LEFT JOIN users    seller  ON n.seller_id    = seller.id
            WHERE $whereClause
            ORDER BY n.updated_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        $negotiations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'negotiations' => $negotiations]);
        break;

    // ─── UNREAD COUNT (for nav badge) ───
    case 'unread_count':
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT nm.negotiation_id) AS count
            FROM negotiation_messages nm
            JOIN negotiations n ON nm.negotiation_id = n.id
            WHERE nm.sender_id != ?
              AND nm.is_read = 0
              AND (n.buyer_id = ? OR n.seller_id = ?)
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'count' => intval($result['count'])]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
?>
