<?php
/**
 * REDLINER — Dynamic XML Sitemap
 * Automatically generates a sitemap with all public pages,
 * active listings, and approved seller profiles.
 */
require_once 'config/db.php';

header('Content-Type: application/xml; charset=utf-8');

$baseUrl = getBaseUrl();

// Helper to output a <url> node
function sitemapUrl($loc, $lastmod = '', $changefreq = 'weekly', $priority = '0.5') {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($lastmod) echo "    <lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
    echo "    <changefreq>{$changefreq}</changefreq>\n";
    echo "    <priority>{$priority}</priority>\n";
    echo "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// ─── Static Pages ───
$staticPages = [
    ['url' => '',              'freq' => 'daily',   'priority' => '1.0'],
    ['url' => 'browse.php',    'freq' => 'daily',   'priority' => '0.9'],
    ['url' => 'features.php',  'freq' => 'monthly', 'priority' => '0.6'],
    ['url' => 'terms.php',     'freq' => 'monthly', 'priority' => '0.3'],
    ['url' => 'privacy.php',   'freq' => 'monthly', 'priority' => '0.3'],
    ['url' => 'CONTACT.php',   'freq' => 'monthly', 'priority' => '0.5'],
    ['url' => 'apply_seller.php','freq' => 'monthly','priority' => '0.6'],
];

$today = date('Y-m-d');
foreach ($staticPages as $page) {
    sitemapUrl($baseUrl . '/' . $page['url'], $today, $page['freq'], $page['priority']);
}

// ─── Active Listings ───
try {
    $stmt = $conn->query("
        SELECT id, title, updated_at, created_at
        FROM listings
        WHERE status = 'active'
        ORDER BY updated_at DESC
    ");
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listings as $l) {
        // Build SEO-friendly slug
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $l['title']), '-'));
        $url  = $baseUrl . '/listing/' . $l['id'] . '/' . $slug;
        $lastmod = substr($l['updated_at'] ?? $l['created_at'], 0, 10);
        sitemapUrl($url, $lastmod, 'weekly', '0.8');
    }
} catch (PDOException $e) {}

// ─── Approved Seller Profiles ───
try {
    $stmt = $conn->query("
        SELECT id, name, store_name, updated_at, created_at
        FROM users
        WHERE role IN ('seller','admin') AND is_verified = 1
        ORDER BY updated_at DESC
    ");
    $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sellers as $s) {
        $url     = $baseUrl . '/seller.php?id=' . $s['id'];
        $lastmod = substr($s['updated_at'] ?? $s['created_at'], 0, 10);
        sitemapUrl($url, $lastmod, 'weekly', '0.7');
    }
} catch (PDOException $e) {}

// ─── Category Pages ───
try {
    $stmt = $conn->query("SELECT slug FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
    $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cats as $cat) {
        $url = $baseUrl . '/browse.php?category=' . urlencode($cat['slug']);
        sitemapUrl($url, $today, 'daily', '0.75');
    }
} catch (PDOException $e) {}

echo '</urlset>' . "\n";
