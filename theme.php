<?php
/**
 * theme.php — سرو CSS داینامیک از تنظیمات
 * استفاده: <link rel="stylesheet" href="theme.php">
 */
require_once __DIR__ . '/engine/Engine.php';
require_once __DIR__ . '/engine/SettingsService.php';

$db       = new LinerLightEngine();
$settings = new SettingsService($db);

$css  = $settings->generateCSS();
$etag = md5($css);

// کش ۱ ساعته
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('ETag: "' . $etag . '"');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . $etag . '"') {
    http_response_code(304);
    exit;
}

echo $css;
