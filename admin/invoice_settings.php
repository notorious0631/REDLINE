<?php include 'header.php';

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['invoice_company_name', 'invoice_address', 'invoice_tax_id', 'invoice_prefix', 'invoice_footer_notes'];
    foreach ($keys as $key) {
        $val = trim($_POST[$key] ?? '');
        try {
            $stmt = $conn->prepare("SELECT id FROM admin_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            if ($stmt->fetch()) {
                $conn->prepare("UPDATE admin_settings SET setting_value = ? WHERE setting_key = ?")->execute([$val, $key]);
            } else {
                $conn->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
            }
        } catch (PDOException $e) {}
    }
    $success = "Invoice settings saved successfully!";
}

$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'invoice_%'");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {}

function inv_val($k, $d='') { global $settings; return htmlspecialchars($settings[$k] ?? $d); }
?>

<div class="admin-page-header">
    <div>
        <h1>Invoice Settings</h1>
        <p class="page-subtitle">Customize the appearance and details of your printed invoices</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>

<form method="POST">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <div class="admin-card">
            <div class="admin-card-header"><i class="fas fa-building"></i> Company Details (For Invoice)</div>
            <div class="admin-card-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Company / Business Name</label>
                    <input type="text" name="invoice_company_name" class="admin-input" value="<?php echo inv_val('invoice_company_name', 'REDLINE'); ?>" placeholder="e.g. REDLINE Diecast Ltd.">
                    <small style="color:var(--admin-muted);font-size:0.75rem;">This replaces the default site name on the invoice.</small>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Business Address</label>
                    <textarea name="invoice_address" class="admin-textarea" rows="3" placeholder="Full address to display on the invoice"><?php echo inv_val('invoice_address'); ?></textarea>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Tax ID / GSTIN</label>
                    <input type="text" name="invoice_tax_id" class="admin-input" value="<?php echo inv_val('invoice_tax_id'); ?>" placeholder="e.g. 27AAAAA0000A1Z5">
                </div>
            </div>
        </div>

        <div class="admin-card" style="height:fit-content;">
            <div class="admin-card-header"><i class="fas fa-file-invoice"></i> Formatting & Notes</div>
            <div class="admin-card-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Invoice Number Prefix</label>
                    <input type="text" name="invoice_prefix" class="admin-input" value="<?php echo inv_val('invoice_prefix', 'INV-'); ?>" placeholder="e.g. INV- or ORD-">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Footer Terms / Notes</label>
                    <textarea name="invoice_footer_notes" class="admin-textarea" rows="3" placeholder="e.g. Thank you for shopping! Returns accepted within 7 days."><?php echo inv_val('invoice_footer_notes', 'Thank you for shopping at REDLINE!'); ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <div style="margin-top:20px;">
        <button type="submit" class="btn-admin red"><i class="fas fa-save"></i> Save Invoice Settings</button>
    </div>
</form>

<?php include 'footer.php'; ?>
