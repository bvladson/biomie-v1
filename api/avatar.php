<?php

declare(strict_types=1);

// Serve user avatar — public within auth session, no capability required.
require_once dirname(__DIR__) . '/api/bootstrap.php';

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Support\TenantContext;

Auth::requireLogin(true);
header('X-BIOMIE-Context: ' . TenantContext::contextId());
header('Vary: Accept, X-BIOMIE-Context');

$uid = (int) ($_GET['uid'] ?? 0);
if ($uid <= 0) {
    http_response_code(400);
    exit;
}

// Users can only fetch their own avatar (or admin can fetch anyone's)
$currentUser = Auth::currentUser();
$currentId   = (int) ($currentUser['id'] ?? 0);
if ($currentId !== $uid && !Auth::isAdmin()) {
    http_response_code(403);
    exit;
}

$root      = Bootstrap::rootPath();
$dir       = $root . '/storage/users/avatars';
$jpgPath   = $dir . '/' . $uid . '.jpg';
$pngPath   = $dir . '/' . $uid . '.png';
$webpPath  = $dir . '/' . $uid . '.webp';

if (is_file($jpgPath)) {
    $filePath = $jpgPath;
    $mime     = 'image/jpeg';
} elseif (is_file($pngPath)) {
    $filePath = $pngPath;
    $mime     = 'image/png';
} elseif (is_file($webpPath)) {
    $filePath = $webpPath;
    $mime     = 'image/webp';
} else {
    http_response_code(404);
    exit;
}

$mtime = (int) filemtime($filePath);
$etag  = '"' . dechex($uid) . '-' . dechex($mtime) . '"';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}

readfile($filePath);
exit;
