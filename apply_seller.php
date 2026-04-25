<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$error = '';
$success = '';
$pending = false;

// Check current role and existing applications
try {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();

    if ($role === 'seller' || $role === 'admin') {
        header('Location: seller_dashboard/index.php');
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM seller_applications WHERE user_id = ? ORDER BY applied_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $existingApp = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingApp && $existingApp['status'] === 'pending') {
        $pending = true;
    } else {
        $pending = false;
    }
} catch (PDOException $e) {
    $error = "Database validation failed.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pending) {
    // Validate mandatory fields: Aadhar front, Aadhar back, Selfie with Aadhar, UPI ID
    $hasFront = !empty($_FILES['aadhar']['tmp_name']) && $_FILES['aadhar']['error'] === UPLOAD_ERR_OK;
    $hasBack = !empty($_FILES['aadhar_back']['tmp_name']) && $_FILES['aadhar_back']['error'] === UPLOAD_ERR_OK;
    $hasSelfie = !empty($_FILES['selfie_aadhar']['tmp_name']) && $_FILES['selfie_aadhar']['error'] === UPLOAD_ERR_OK;
    $hasPan = !empty($_FILES['pan']['tmp_name']) && $_FILES['pan']['error'] === UPLOAD_ERR_OK;
    $upiId = trim($_POST['upi_id'] ?? '');

    if (empty($upiId)) {
        $error = "UPI ID is mandatory to apply as a seller.";
    } elseif (!preg_match('/^[a-zA-Z0-9.\-_]+@[a-zA-Z0-9]+$/', $upiId)) {
        $error = "Please enter a valid UPI ID (e.g. yourname@upi).";
    } elseif (!$hasFront || !$hasBack || !$hasSelfie) {
        $error = "Aadhaar Card front, back, and selfie with Aadhaar are all mandatory.";
    } else {
        $uploadDir = 'uploads/kyc/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $aadharPath = null;
        $aadharBackPath = null;
        $panPath = null;
        $selfiePath = null;
        $uploadSuccess = true;
        $maxSize = 5 * 1024 * 1024; // 5MB
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

        // Helper to validate and upload a file
        $processUpload = function($fileKey, $prefix) use ($uploadDir, $userId, $maxSize, $allowedMimes, &$error) {
            $file = $_FILES[$fileKey];
            if ($file['size'] > $maxSize) {
                $error = ucfirst($prefix) . " file exceeds 5MB limit.";
                return false;
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMimes)) {
                $error = ucfirst($prefix) . " file must be JPG, PNG, WebP, or PDF.";
                return false;
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $dest = $uploadDir . $prefix . '_' . $userId . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $error = "Failed to upload " . $prefix . " file.";
                return false;
            }
            return $dest;
        };

        // Process Aadhar Front (mandatory)
        $aadharPath = $processUpload('aadhar', 'aadhar_front');
        if (!$aadharPath) $uploadSuccess = false;

        // Process Aadhar Back (mandatory)
        if ($uploadSuccess) {
            $aadharBackPath = $processUpload('aadhar_back', 'aadhar_back');
            if (!$aadharBackPath) $uploadSuccess = false;
        }

        // Process Selfie with Aadhar (mandatory)
        if ($uploadSuccess) {
            $selfiePath = $processUpload('selfie_aadhar', 'selfie_aadhar');
            if (!$selfiePath) $uploadSuccess = false;
        }

        // Process PAN (optional)
        if ($uploadSuccess && $hasPan) {
            $panPath = $processUpload('pan', 'pan');
            if (!$panPath) $uploadSuccess = false;
        }

        if ($uploadSuccess) {
            try {
                $stmt = $conn->prepare("INSERT INTO seller_applications (user_id, aadhar_path, aadhar_back_path, pan_path, selfie_with_aadhar_path, upi_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $aadharPath, $aadharBackPath, $panPath, $selfiePath, $upiId]);
                
                // Also save UPI to user profile so it's ready when approved
                $stmt = $conn->prepare("UPDATE users SET upi_id = ? WHERE id = ? AND (upi_id IS NULL OR upi_id = '')");
                $stmt->execute([$upiId, $userId]);
                
                $success = "Application submitted successfully! Your documents are under review.";
                $pending = true;
            } catch (PDOException $e) {
                $error = "Failed to submit application. Please try again.";
            }
        }
    }
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/auth.css">
<style>
.kyc-card { max-width: 680px; margin: 80px auto; padding: 40px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 10px 40px rgba(0,0,0,0.5); text-align: center; }
.kyc-icon { font-size: 4rem; color: var(--accent-red); margin-bottom: 20px; }
.kyc-title { font-family: 'Cinzel', serif; margin-bottom: 10px; font-size: 2rem; color: #fff; }
.kyc-desc { color: var(--text-secondary); margin-bottom: 30px; font-size: 1rem; line-height: 1.6; }
.kyc-form { text-align: left; }
.kyc-upload-box { border: 2px dashed var(--border-color); padding: 20px; text-align: center; border-radius: 12px; margin-bottom: 16px; background: var(--bg-body); transition: 0.3s; cursor: pointer; position:relative; }
.kyc-upload-box:hover { border-color: var(--accent-red); background: rgba(229,57,53,0.03); }
.kyc-upload-box i.upload-icon { font-size: 2rem; color: var(--text-muted); margin-bottom: 10px; transition: 0.3s; }
.kyc-upload-box:hover i.upload-icon { color: var(--accent-red); }
.kyc-upload-box label { display: block; cursor: pointer; color: var(--text-primary); font-weight: 500; font-size:0.95rem; }
.kyc-upload-box input[type="file"] { display: none; }
.kyc-upload-box .kyc-file-hint { font-size:0.8rem; color:var(--text-muted); display:block; margin-top:5px; }
.kyc-upload-box .kyc-required { position:absolute; top:10px; right:12px; font-size:0.7rem; font-weight:700; letter-spacing:0.5px; padding:2px 8px; border-radius:20px; }
.kyc-required.mandatory { background:rgba(229,57,53,0.15); color:var(--accent-red); }
.kyc-required.optional { background:rgba(255,255,255,0.06); color:var(--text-muted); }
.kyc-upload-box.has-file { border-color: rgba(16,185,129,0.5); background: rgba(16,185,129,0.04); }
.kyc-upload-box.has-file i.upload-icon { color: #10b981; }
.kyc-upload-box .file-name { font-size:0.85rem; color:#10b981; margin-top:6px; font-weight:500; display:none; }
.kyc-upload-box.has-file .file-name { display:block; }
.kyc-section-title { font-size:0.85rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; font-weight:600; margin:24px 0 12px; padding-bottom:8px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:8px; }
.kyc-section-title i { font-size:0.75rem; }
.kyc-status { padding: 30px; border-radius: 12px; font-size: 1.1rem; font-weight: 500; display:flex; flex-direction:column; align-items:center; gap:20px; }
.kyc-pending { background: rgba(255, 183, 77, 0.1); border: 1px solid rgba(255, 183, 77, 0.3); color: #ffb74d; }
.kyc-rejected { background: rgba(229, 57, 53, 0.1); border: 1px solid rgba(229, 57, 53, 0.3); color: #e53935; }
.kyc-selfie-note { background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; gap:12px; align-items:flex-start; }
.kyc-selfie-note i { color:#3b82f6; font-size:1.2rem; margin-top:2px; flex-shrink:0; }
.kyc-selfie-note p { color:var(--text-secondary); font-size:0.85rem; line-height:1.5; margin:0; }
.kyc-selfie-note strong { color:#60a5fa; }
</style>

<div class="container-rl">
    <div class="kyc-card" data-aos="fade-up">
        
        <?php if ($success): ?>
            <div class="auth-alert success" style="margin-bottom:20px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="auth-alert error" style="margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($pending): ?>
            <i class="fas fa-hourglass-half kyc-icon" style="color:#ffb74d;"></i>
            <h2 class="kyc-title">Application Under Review</h2>
            <div class="kyc-status kyc-pending">
                Our administration team is currently verifying your submitted documents. <br>This usually takes 1-2 business days.
                <a href="profile.php" class="btn-outline-white">Return to Profile</a>
            </div>
        <?php elseif(!empty($existingApp) && $existingApp['status'] === 'rejected'): ?>
            <i class="fas fa-times-circle kyc-icon" style="color:#e53935;"></i>
            <h2 class="kyc-title">Application Rejected</h2>
            <div class="kyc-status kyc-rejected" style="margin-bottom:30px;">
                Your previous application was rejected. Please review your documents and ensure they are clear and match your official details.
                <br><br><strong>Reason:</strong> <?php echo htmlspecialchars($existingApp['admin_notes'] ?? 'Documents illegible or invalid.'); ?>
            </div>
            <h3 style="text-align:left; border-top: 1px solid var(--border-color); padding-top:20px; margin-top:10px;">Submit Fresh Application</h3>
        <?php endif; ?>

        <?php if (!$pending): ?>
            <?php if (empty($existingApp) || (!empty($existingApp) && $existingApp['status'] !== 'rejected')): ?>
                <i class="fas fa-id-card kyc-icon"></i>
                <h2 class="kyc-title">Become a Verified Seller</h2>
                <p class="kyc-desc">To ensure the highest quality and safety on REDLINE, upload your Aadhaar Card (front & back), a selfie holding your Aadhaar, and optionally your PAN Card.</p>
            <?php endif; ?>

            <form class="kyc-form" method="POST" enctype="multipart/form-data" id="kycForm">
                
                <!-- Aadhaar Section -->
                <div class="kyc-section-title"><i class="fas fa-id-card"></i> Aadhaar Card Verification</div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="kyc-upload-box" onclick="document.getElementById('aadhar').click()" id="box_aadhar">
                        <span class="kyc-required mandatory">REQUIRED</span>
                        <i class="fas fa-image upload-icon"></i>
                        <label>Aadhaar Front Side</label>
                        <span class="kyc-file-hint">Clear photo of the front</span>
                        <input type="file" id="aadhar" name="aadhar" accept="image/*,.pdf" onchange="handleFileSelect(this, 'box_aadhar')">
                        <span class="file-name" id="fname_aadhar"></span>
                    </div>

                    <div class="kyc-upload-box" onclick="document.getElementById('aadhar_back').click()" id="box_aadhar_back">
                        <span class="kyc-required mandatory">REQUIRED</span>
                        <i class="fas fa-image upload-icon"></i>
                        <label>Aadhaar Back Side</label>
                        <span class="kyc-file-hint">Clear photo of the back</span>
                        <input type="file" id="aadhar_back" name="aadhar_back" accept="image/*,.pdf" onchange="handleFileSelect(this, 'box_aadhar_back')">
                        <span class="file-name" id="fname_aadhar_back"></span>
                    </div>
                </div>

                <!-- Selfie Verification -->
                <div class="kyc-section-title"><i class="fas fa-camera"></i> Identity Verification Selfie</div>
                
                <div class="kyc-selfie-note">
                    <i class="fas fa-info-circle"></i>
                    <p>Take a <strong>clear photo of yourself holding your original Aadhaar card</strong> next to your face. Both your face and the Aadhaar details must be visible. This prevents identity fraud and ensures you are the genuine cardholder.</p>
                </div>

                <div class="kyc-upload-box" onclick="document.getElementById('selfie_aadhar').click()" id="box_selfie">
                    <span class="kyc-required mandatory">REQUIRED</span>
                    <i class="fas fa-camera upload-icon"></i>
                    <label>Selfie with Original Aadhaar Card</label>
                    <span class="kyc-file-hint">Photo showing your face + Aadhaar card clearly • JPG, PNG max 5MB</span>
                    <input type="file" id="selfie_aadhar" name="selfie_aadhar" accept="image/*" onchange="handleFileSelect(this, 'box_selfie')">
                    <span class="file-name" id="fname_selfie"></span>
                </div>

                <!-- UPI Section (Mandatory) -->
                <div class="kyc-section-title"><i class="fas fa-money-bill-wave"></i> UPI Payment Details</div>

                <div class="kyc-selfie-note" style="border-color:rgba(229,57,53,0.2); background:rgba(229,57,53,0.05);">
                    <i class="fas fa-exclamation-circle" style="color:var(--accent-red);"></i>
                    <p>Your <strong style="color:var(--accent-red);">UPI ID is mandatory</strong> for receiving direct payments from buyers. This will be shown to buyers when they purchase your products. Ensure it is active and correct.</p>
                </div>

                <div class="kyc-upi-input" style="position:relative;">
                    <span class="kyc-required mandatory" style="position:absolute; top:12px; right:14px; font-size:0.7rem; font-weight:700; letter-spacing:0.5px; padding:2px 8px; border-radius:20px; z-index:2;">REQUIRED</span>
                    <div style="display:flex; align-items:center; gap:12px; background:var(--bg-body); border:2px solid var(--border-color); border-radius:12px; padding:16px 20px; transition:0.3s;" id="upi_input_box">
                        <i class="fas fa-wallet" style="font-size:1.5rem; color:var(--text-muted); transition:0.3s;" id="upi_icon"></i>
                        <div style="flex:1;">
                            <label for="upi_id" style="display:block; font-weight:600; font-size:0.9rem; color:var(--text-primary); margin-bottom:4px;">UPI ID</label>
                            <input type="text" id="upi_id" name="upi_id" placeholder="e.g. yourname@upi, 9876543210@paytm" 
                                   style="width:100%; background:transparent; border:none; outline:none; color:#fff; font-size:1rem; padding:4px 0;"
                                   value="<?php echo htmlspecialchars($_POST['upi_id'] ?? ''); ?>"
                                   oninput="validateUPILive(this)">
                            <span class="kyc-file-hint" style="display:block; margin-top:4px;">Enter your active UPI address for receiving buyer payments</span>
                        </div>
                    </div>
                    <div id="upi_validation_msg" style="font-size:0.8rem; margin-top:6px; padding-left:4px; display:none;"></div>
                </div>

                <!-- PAN (Optional) -->
                <div class="kyc-section-title" style="margin-top:28px;"><i class="fas fa-id-badge"></i> PAN Card (Optional)</div>

                <div class="kyc-upload-box" onclick="document.getElementById('pan').click()" id="box_pan">
                    <span class="kyc-required optional">OPTIONAL</span>
                    <i class="fas fa-id-badge upload-icon"></i>
                    <label>Upload PAN Card Front</label>
                    <span class="kyc-file-hint">JPG, PNG, WebP, or PDF max 5MB</span>
                    <input type="file" id="pan" name="pan" accept="image/*,.pdf" onchange="handleFileSelect(this, 'box_pan')">
                    <span class="file-name" id="fname_pan"></span>
                </div>

                <button type="submit" class="btn-auth" style="margin-top:24px; width:100%;" onclick="return validateKYC()">
                    Submit Application <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <script>
            function handleFileSelect(input, boxId) {
                var box = document.getElementById(boxId);
                var fnameEl = box.querySelector('.file-name');
                if (input.files && input.files[0]) {
                    box.classList.add('has-file');
                    fnameEl.textContent = '✓ ' + input.files[0].name;
                    fnameEl.style.display = 'block';
                } else {
                    box.classList.remove('has-file');
                    fnameEl.style.display = 'none';
                }
            }

            function validateUPILive(input) {
                var box = document.getElementById('upi_input_box');
                var icon = document.getElementById('upi_icon');
                var msg = document.getElementById('upi_validation_msg');
                var val = input.value.trim();
                var upiRegex = /^[a-zA-Z0-9.\-_]+@[a-zA-Z0-9]+$/;
                
                if (val === '') {
                    box.style.borderColor = 'var(--border-color)';
                    icon.style.color = 'var(--text-muted)';
                    msg.style.display = 'none';
                } else if (upiRegex.test(val)) {
                    box.style.borderColor = 'rgba(16,185,129,0.5)';
                    icon.style.color = '#10b981';
                    msg.style.display = 'block';
                    msg.style.color = '#10b981';
                    msg.innerHTML = '<i class="fas fa-check-circle"></i> Valid UPI format';
                } else {
                    box.style.borderColor = 'rgba(229,57,53,0.5)';
                    icon.style.color = '#e53935';
                    msg.style.display = 'block';
                    msg.style.color = '#e53935';
                    msg.innerHTML = '<i class="fas fa-times-circle"></i> Invalid format — use format: name@bank';
                }
            }

            function validateKYC() {
                var aadhar = document.getElementById('aadhar').value;
                var aadharBack = document.getElementById('aadhar_back').value;
                var selfie = document.getElementById('selfie_aadhar').value;
                var upi = document.getElementById('upi_id').value.trim();
                var upiRegex = /^[a-zA-Z0-9.\-_]+@[a-zA-Z0-9]+$/;
                
                if (!upi) {
                    alert('UPI ID is mandatory to apply as a seller.');
                    document.getElementById('upi_id').focus();
                    return false;
                }
                if (!upiRegex.test(upi)) {
                    alert('Please enter a valid UPI ID (e.g. yourname@upi).');
                    document.getElementById('upi_id').focus();
                    return false;
                }
                if (!aadhar) {
                    alert('Please upload the front side of your Aadhaar Card.');
                    return false;
                }
                if (!aadharBack) {
                    alert('Please upload the back side of your Aadhaar Card.');
                    return false;
                }
                if (!selfie) {
                    alert('Please upload a selfie of yourself holding your original Aadhaar Card.');
                    return false;
                }
                return true;
            }
            </script>
        <?php endif; ?>

    </div>
</div>

<?php include 'includes/footer.php'; ?>
