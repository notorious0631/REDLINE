<?php
// seller_dashboard/listings.php
$pageTitle = 'Manage Inventory';
include 'header.php';

$sellerId = $_SESSION['user_id'];

// Auto-add negotiable column if it doesn't exist
try {
    $conn->query("SELECT negotiable FROM listings LIMIT 1");
} catch (PDOException $e) {
    try { $conn->exec("ALTER TABLE listings ADD COLUMN negotiable TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $e2) {}
}

// Handle Delete/Archive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['listing_id'])) {
    $listId = intval($_POST['listing_id']);
    
    // Verify ownership
    $stmt = $conn->prepare("SELECT id FROM listings WHERE id = ? AND seller_id = ?");
    $stmt->execute([$listId, $sellerId]);
    if ($stmt->fetch()) {
        if ($_POST['action'] === 'delete') {
            try {
                $conn->beginTransaction();
                // Delete child rows from all referencing tables first
                try { $conn->prepare("DELETE FROM order_items WHERE listing_id = ?")->execute([$listId]); } catch (PDOException $e) {}
                try { $conn->prepare("DELETE FROM cart_items WHERE listing_id = ?")->execute([$listId]); } catch (PDOException $e) {}
                try { $conn->prepare("DELETE FROM wishlists WHERE listing_id = ?")->execute([$listId]); } catch (PDOException $e) {}
                try { $conn->prepare("DELETE FROM listing_images WHERE listing_id = ?")->execute([$listId]); } catch (PDOException $e) {}
                try { $conn->prepare("DELETE FROM negotiations WHERE listing_id = ?")->execute([$listId]); } catch (PDOException $e) {}
                try {
                    // Delete chat messages for chat rooms linked to this listing, then chat rooms
                    $chatRooms = $conn->prepare("SELECT id FROM chat_rooms WHERE listing_id = ?");
                    $chatRooms->execute([$listId]);
                    foreach ($chatRooms->fetchAll(PDO::FETCH_COLUMN) as $roomId) {
                        $conn->prepare("DELETE FROM chat_messages WHERE chat_room_id = ?")->execute([$roomId]);
                    }
                    $conn->prepare("DELETE FROM chat_rooms WHERE listing_id = ?")->execute([$listId]);
                } catch (PDOException $e) {}
                // Now safe to delete the listing
                $conn->prepare("DELETE FROM listings WHERE id = ?")->execute([$listId]);
                $conn->commit();
                $success = "Listing successfully deleted.";
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Failed to delete listing. Please try again.";
            }
        } elseif ($_POST['action'] === 'toggle_negotiate') {
            $current = $conn->prepare("SELECT negotiable FROM listings WHERE id = ?");
            $current->execute([$listId]);
            $curVal = intval($current->fetchColumn());
            $newVal = $curVal ? 0 : 1;
            $conn->prepare("UPDATE listings SET negotiable = ? WHERE id = ?")->execute([$newVal, $listId]);
            $success = $newVal ? "Negotiation enabled for this listing." : "Negotiation disabled. This item will be sold at listing price only.";
        } elseif ($_POST['action'] === 'relist') {
            $price = floatval($_POST['price'] ?? 0);
            $stock = max(1, intval($_POST['stock'] ?? 1));
            
            if ($price > 0 && $stock > 0) {
                $conn->prepare("UPDATE listings SET status = 'active', views = 0, price = ?, stock = ? WHERE id = ? AND status = 'sold'")->execute([$price, $stock, $listId]);
                $success = "Listing has been successfully renewed and is now active again!";
            } else {
                $error = "Invalid price or stock quantity.";
            }
        }
    }
}

// Fetch Listings
$listings = [];
try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name 
        FROM listings l 
        LEFT JOIN categories c ON l.category_id = c.id 
        WHERE l.seller_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$sellerId]);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<div class="page-header">
    <div class="page-title">
        <h1>Inventory Management</h1>
        <p>Edit, view, or remove your diecast listings</p>
    </div>
    <a href="add_listing.php" class="btn-primary"><i class="fas fa-plus"></i> Add Item</a>
</div>

<?php if (isset($success)): ?>
    <div style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); color: var(--accent-green); padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.2); color: var(--accent-red); padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="table-container">
        <table class="seller-table">
            <thead>
                <tr>
                     <th style="width: 80px;">Item</th>
                    <th>Details</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Views</th>
                    <th>Status</th>
                    <th>Negotiate</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($listings)): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 60px; color:var(--text-muted);">
                        <i class="fas fa-box-open" style="font-size:3rem; opacity:0.15; margin-bottom:16px;"></i><br>
                        You have no items in your inventory.
                    </td></tr>
                <?php else: foreach($listings as $item): ?>
                    <tr>
                        <td>
                            <?php if(!empty($item['image'])): ?>
                                <img src="../<?php echo htmlspecialchars($item['image']); ?>" style="width:56px; height:56px; object-fit:cover; border-radius:10px;">
                            <?php else: ?>
                                <div style="width:56px; height:56px; background:rgba(255,255,255,0.03); border-radius:10px; display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-car-side"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color:var(--text-primary); display:block; margin-bottom:4px; font-size:1rem;"><?php echo htmlspecialchars($item['title']); ?></strong>
                            <span style="font-size:0.8rem; color:var(--text-secondary);"><?php echo htmlspecialchars($item['category_name']); ?> • <?php echo ucfirst($item['condition']); ?></span>
                        </td>
                        <td style="color:var(--accent-red); font-weight:700;">
                            Rs. <?php echo number_format($item['price'], 0); ?>
                            <?php if(!empty($item['is_mrp'])): ?><br><span style="display:inline-block; margin-top:4px; font-size:0.6rem; background:rgba(16,185,129,0.15); color:var(--accent-green); padding:2px 4px; border-radius:4px; text-transform:uppercase; border:1px solid rgba(16,185,129,0.3);">MRP</span><?php endif; ?>
                            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:500; margin-top:6px;">
                                <?php if(floatval($item['shipping_fee'] ?? 0) > 0): ?>
                                    <i class="fas fa-truck" style="font-size:0.7rem; opacity:0.7;"></i> + Rs. <?php echo number_format($item['shipping_fee'], 0); ?>
                                <?php else: ?>
                                    <span style="color:var(--accent-green); opacity:0.8;"><i class="fas fa-truck" style="font-size:0.7rem;"></i> FREE</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <?php $stock = intval($item['stock'] ?? 1); ?>
                            <?php if($stock <= 0): ?>
                                <span style="color:#e57373; font-weight:700; font-size:0.85rem;">Out</span>
                            <?php elseif($stock <= 3): ?>
                                <span style="color:#ffb74d; font-weight:700; font-size:0.85rem;"><?php echo $stock; ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-secondary); font-weight:600; font-size:0.85rem;"><?php echo $stock; ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-secondary);"><i class="fas fa-eye" style="opacity:0.6; margin-right:6px;"></i> <?php echo number_format($item['views']); ?></td>
                        <td>
                            <?php if($item['status'] === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php elseif($item['status'] === 'sold'): ?>
                                <span class="badge badge-danger">Sold</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($item['status'] === 'active'): ?>
                            <?php $isNeg = intval($item['negotiable'] ?? 1); ?>
                            <div class="nego-toggle <?php echo $isNeg ? 'on' : ''; ?>" onclick="toggleNegotiate(<?php echo $item['id']; ?>, <?php echo $isNeg; ?>)" title="<?php echo $isNeg ? 'Click to disable negotiation' : 'Click to enable negotiation'; ?>">
                                <span class="nego-knob"></span>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <a href="../listing.php?id=<?php echo $item['id']; ?>" target="_blank" class="btn-icon-only" title="Preview Listing"><i class="fas fa-external-link-alt"></i></a>
                                <?php if($item['status'] !== 'sold'): ?>
                                <a href="edit_listing.php?id=<?php echo $item['id']; ?>" class="btn-icon-only" title="Edit Listing" style="color:var(--accent-amber, #f59e0b); border-color:rgba(245,158,11,0.3);"><i class="fas fa-pen"></i></a>
                                <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to completely delete this listing? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="listing_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn-icon-only" style="color:var(--accent-red); border-color:rgba(229,57,53,0.3);" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php else: ?>
                                <button type="button" class="btn-icon-only" style="color:var(--accent-green); border-color:rgba(16,185,129,0.3);" title="Renew Listing" onclick="openRelistModal(<?php echo $item['id']; ?>, <?php echo $item['price']; ?>)">
                                    <i class="fas fa-redo"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Relist / Renew Modal -->
<div id="relistModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="modal-content" style="background:var(--bg-surface); padding:24px; border-radius:12px; width:90%; max-width:400px; border:1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        <h3 style="margin-top:0; margin-bottom:6px; color:var(--text-primary);">Renew Listing</h3>
        <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:20px;">Update price and stock quantity before reactivating.</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="relist">
            <input type="hidden" name="listing_id" id="relistListingId" value="">
            
            <div style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-size:0.9rem; color:var(--text-secondary); font-weight:500;">Price (Rs.) *</label>
                <input type="number" name="price" id="relistPrice" style="width:100%; box-sizing:border-box; padding:12px 16px; background:rgba(255,255,255,0.03); border:1px solid var(--border-color); color:var(--text-primary); border-radius:10px; font-family:var(--font-sans);" step="0.01" min="1" required>
            </div>
            
            <div style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:8px; font-size:0.9rem; color:var(--text-secondary); font-weight:500;">Stock Quantity *</label>
                <input type="number" name="stock" style="width:100%; box-sizing:border-box; padding:12px 16px; background:rgba(255,255,255,0.03); border:1px solid var(--border-color); color:var(--text-primary); border-radius:10px; font-family:var(--font-sans);" min="1" value="1" required>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" style="padding:10px 20px; background:transparent; border:1px solid var(--border-color); color:var(--text-primary); border-radius:10px; cursor:pointer; font-weight:600; font-family:var(--font-sans);" onclick="closeRelistModal()">Cancel</button>
                <button type="submit" style="padding:10px 20px; background:var(--accent-green); color:#fff; border:none; border-radius:10px; font-weight:bold; cursor:pointer; font-family:var(--font-sans);">Renew Item</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRelistModal(id, currentPrice) {
    document.getElementById('relistListingId').value = id;
    document.getElementById('relistPrice').value = currentPrice;
    
    const modal = document.getElementById('relistModal');
    modal.style.display = 'flex';
    // Add subtle animation
    modal.querySelector('.modal-content').style.opacity = '0';
    modal.querySelector('.modal-content').style.transform = 'scale(0.95)';
    modal.querySelector('.modal-content').style.transition = 'all 0.2s ease-out';
    
    requestAnimationFrame(() => {
        modal.querySelector('.modal-content').style.opacity = '1';
        modal.querySelector('.modal-content').style.transform = 'scale(1)';
    });
}

function closeRelistModal() {
    const modal = document.getElementById('relistModal');
    modal.querySelector('.modal-content').style.opacity = '0';
    modal.querySelector('.modal-content').style.transform = 'scale(0.95)';
    setTimeout(() => {
        modal.style.display = 'none';
    }, 200);
}

// Close on outside click
document.getElementById('relistModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRelistModal();
    }
});

// Negotiate toggle
function toggleNegotiate(listingId, currentState) {
    var msg;
    if (currentState === 1) {
        msg = 'Disable negotiation for this listing? This item will be sold at listing price only!';
    } else {
        msg = 'Enable negotiation for this listing? Buyers will be able to negotiate the price.';
    }
    
    if (!confirm(msg)) {
        return;
    }
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="toggle_negotiate"><input type="hidden" name="listing_id" value="' + listingId + '">';
    document.body.appendChild(form);
    form.submit();
}
</script>

<style>
.nego-toggle {
    position: relative; display: inline-block; width: 40px; height: 22px;
    cursor: pointer; background: rgba(255,255,255,0.08); border-radius: 22px;
    transition: all 0.25s;
}
.nego-toggle.on { background: rgba(76,175,80,0.25); }
.nego-knob {
    position: absolute;
    width: 16px; height: 16px; left: 3px; top: 3px;
    background: #888; border-radius: 50%;
    transition: all 0.25s;
}
.nego-toggle.on .nego-knob {
    transform: translateX(18px); background: #4caf50;
    box-shadow: 0 0 6px rgba(76,175,80,0.5);
}
</style>

<?php include 'footer.php'; ?>
