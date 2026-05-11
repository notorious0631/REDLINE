<?php
/**
 * Auto-cancel expired orders and restore stock.
 * Called by the payment page JS when timer hits 0, and on page load to catch stale orders.
 * 
 * GET  ?action=check&ids=1,2,3  — Check and cancel specific expired orders
 * GET  ?action=status&ids=1,2,3 — Get current status of orders (for polling)
 */
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';
$idsParam = $_GET['ids'] ?? $_POST['ids'] ?? '';
$idsArray = array_filter(array_map('intval', explode(',', $idsParam)));

if (empty($idsArray)) {
    echo json_encode(['success' => false, 'error' => 'No order IDs provided']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    if ($action === 'check') {
        // Find expired orders that are still pending payment
        $inStr = implode(',', array_fill(0, count($idsArray), '?'));
        $params = $idsArray;
        $params[] = $userId;
        
        $stmt = $conn->prepare("
            SELECT o.id FROM orders o
            WHERE o.id IN ($inStr) 
            AND o.buyer_id = ? 
            AND o.payment_status = 'pending' 
            AND o.payment_deadline IS NOT NULL 
            AND o.payment_deadline < NOW()
        ");
        $stmt->execute($params);
        $expiredIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $cancelledIds = [];
        
        if (!empty($expiredIds)) {
            $conn->beginTransaction();
            
            foreach ($expiredIds as $expiredId) {
                // Cancel the order
                $conn->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")
                     ->execute([$expiredId]);
                
                // Restore stock for each item
                $itemStmt = $conn->prepare("SELECT listing_id, quantity FROM order_items WHERE order_id = ?");
                $itemStmt->execute([$expiredId]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    $conn->prepare("UPDATE listings SET stock = stock + ?, status = 'active' WHERE id = ?")
                         ->execute([$item['quantity'], $item['listing_id']]);
                }
                
                // Notify the seller
                $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES ((SELECT seller_id FROM orders WHERE id = ?), 'order_cancelled', ?, 'seller_dashboard/orders.php')")
                     ->execute([$expiredId, "Order #$expiredId was auto-cancelled due to payment timeout."]);
                
                $cancelledIds[] = $expiredId;
            }
            
            $conn->commit();
        }
        
        echo json_encode([
            'success' => true,
            'cancelled' => $cancelledIds,
            'message' => !empty($cancelledIds) ? 'Expired orders cancelled' : 'No expired orders'
        ]);
        
    } elseif ($action === 'status') {
        // Return current status of orders
        $inStr = implode(',', array_fill(0, count($idsArray), '?'));
        $params = $idsArray;
        $params[] = $userId;
        
        $stmt = $conn->prepare("
            SELECT o.id, o.status, o.payment_status, o.payment_deadline, o.transaction_id,
                   TIMESTAMPDIFF(SECOND, NOW(), o.payment_deadline) AS db_rem_secs
            FROM orders o
            WHERE o.id IN ($inStr) AND o.buyer_id = ?
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($orders as $o) {
            $rem = isset($o['db_rem_secs']) ? (int)$o['db_rem_secs'] : 0;
            $result[] = [
                'id' => (int)$o['id'],
                'status' => $o['status'],
                'payment_status' => $o['payment_status'],
                'expired' => !empty($o['payment_deadline']) && $rem <= 0 && $o['payment_status'] === 'pending',
                'remaining_seconds' => !empty($o['payment_deadline']) ? max(0, $rem) : 0
            ];
        }
        
        echo json_encode(['success' => true, 'orders' => $result]);
    } elseif ($action === 'cancel') {
        // Manually cancel orders (only if pending)
        $inStr = implode(',', array_fill(0, count($idsArray), '?'));
        $params = $idsArray;
        $params[] = $userId;

        $stmt = $conn->prepare("
            SELECT id FROM orders 
            WHERE id IN ($inStr) AND buyer_id = ? AND payment_status = 'pending'
        ");
        $stmt->execute($params);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($orderIds)) {
            $conn->beginTransaction();
            foreach ($orderIds as $id) {
                // Cancel the order
                $conn->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")
                     ->execute([$id]);
                
                // Restore stock
                $itemStmt = $conn->prepare("SELECT listing_id, quantity FROM order_items WHERE order_id = ?");
                $itemStmt->execute([$id]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    $conn->prepare("UPDATE listings SET stock = stock + ?, status = 'active' WHERE id = ?")
                         ->execute([$item['quantity'], $item['listing_id']]);
                }
                
                // Notify seller
                $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES ((SELECT seller_id FROM orders WHERE id = ?), 'order_cancelled', ?, 'seller_dashboard/orders.php')")
                     ->execute([$id, "Order #$id was cancelled by the buyer."]);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Orders cancelled successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'No eligible orders found to cancel']);
        }
    }
    
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
