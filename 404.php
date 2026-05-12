<?php
$pageTitle = "404 — Car Not Found";
include 'includes/header.php';
?>

<style>
    .error-container {
        min-height: 80vh;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 40px 20px;
        background: radial-gradient(circle at center, rgba(229,57,53,0.05) 0%, transparent 70%);
    }
    .error-content {
        max-width: 600px;
    }
    .error-code {
        font-family: var(--font-display, outfit, sans-serif);
        font-size: 10rem;
        font-weight: 900;
        line-height: 1;
        background: linear-gradient(180deg, var(--accent-red) 0%, #8e0000 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 20px;
        opacity: 0.8;
    }
    .error-title {
        font-family: var(--font-display);
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 20px;
        color: #fff;
    }
    .error-desc {
        color: var(--text-secondary);
        font-size: 1.15rem;
        margin-bottom: 40px;
        line-height: 1.6;
    }
    .car-icon {
        font-size: 4rem;
        color: var(--accent-red);
        margin-bottom: 20px;
        animation: drive 3s infinite linear;
        display: inline-block;
    }
    @keyframes drive {
        0% { transform: translateX(-20px); opacity: 0; }
        10% { opacity: 1; }
        90% { opacity: 1; }
        100% { transform: translateX(20px); opacity: 0; }
    }
    .btn-red {
        padding: 16px 40px;
        border-radius: 50px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        transition: all 0.3s;
    }
    .btn-red:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(229,57,53,0.3);
    }
</style>

<div class="error-container">
    <div class="error-content" data-aos="zoom-in">
        <div class="car-icon">
            <i class="fas fa-car-side"></i>
        </div>
        <div class="error-code">404</div>
        <h1 class="error-title">Oops! Wrong Turn.</h1>
        <p class="error-desc">
            The page you're looking for has been moved, deleted, or never existed. 
            Maybe it was a limited edition that sold out too fast?
        </p>
        <a href="index.php" class="btn-red">
            <i class="fas fa-home"></i> Back to the Pit Stop
        </a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
