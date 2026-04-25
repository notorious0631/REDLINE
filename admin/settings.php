<?php include 'header.php';

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['site_name','contact_email','contact_phone','instagram','facebook','youtube','twitter','linkedin','whatsapp','about_text','shipping_note'];
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
    $success = "Settings saved!";
}

$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM admin_settings");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {}

function sv($k,$d='') { global $settings; return htmlspecialchars($settings[$k] ?? $d); }
?>

<div class="admin-page-header"><div><h1>Settings</h1><p class="page-subtitle">Site configuration</p></div></div>

<?php if ($success): ?><div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>

<form method="POST">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div class="admin-card">
        <div class="admin-card-header"><i class="fas fa-globe"></i> General</div>
        <div class="admin-card-body">
            <div class="admin-form-group"><label class="admin-form-label">Site Name</label><input type="text" name="site_name" class="admin-input" value="<?php echo sv('site_name','REDLINE'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label">Contact Email</label><input type="email" name="contact_email" class="admin-input" value="<?php echo sv('contact_email'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label">Contact Phone</label><input type="text" name="contact_phone" class="admin-input" value="<?php echo sv('contact_phone'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label">About Text</label><textarea name="about_text" class="admin-textarea" rows="3"><?php echo sv('about_text'); ?></textarea></div>
            <div class="admin-form-group"><label class="admin-form-label">Shipping Note</label><textarea name="shipping_note" class="admin-textarea" rows="2"><?php echo sv('shipping_note'); ?></textarea></div>
        </div>
    </div>
    <div class="admin-card" style="height:fit-content;">
        <div class="admin-card-header"><i class="fas fa-share-alt"></i> Social Links</div>
        <div class="admin-card-body">
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-instagram" style="color:#e1306c;"></i> Instagram</label><input type="url" name="instagram" class="admin-input" value="<?php echo sv('instagram'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-facebook" style="color:#4267b2;"></i> Facebook</label><input type="url" name="facebook" class="admin-input" value="<?php echo sv('facebook'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-youtube" style="color:#f00;"></i> YouTube</label><input type="url" name="youtube" class="admin-input" value="<?php echo sv('youtube'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-twitter" style="color:#1da1f2;"></i> Twitter</label><input type="url" name="twitter" class="admin-input" value="<?php echo sv('twitter'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-linkedin" style="color:#0077b5;"></i> LinkedIn</label><input type="url" name="linkedin" class="admin-input" value="<?php echo sv('linkedin'); ?>"></div>
            <div class="admin-form-group"><label class="admin-form-label"><i class="fab fa-whatsapp" style="color:#25d366;"></i> WhatsApp</label><input type="text" name="whatsapp" class="admin-input" value="<?php echo sv('whatsapp'); ?>" placeholder="Phone number with country code"></div>
        </div>
    </div>
</div>
<div style="margin-top:20px;"><button type="submit" class="btn-admin red"><i class="fas fa-save"></i> Save All Settings</button></div>
</form>

<?php include 'footer.php'; ?>
