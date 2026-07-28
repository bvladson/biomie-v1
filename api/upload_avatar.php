<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Http;
use BIomie\Support\TenantContext;

Auth::requireLogin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::methodNotAllowed(['POST']);
}

// CSRF via header (JS fetch sends X-CSRF-Token) or fallback to POST field
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
if (!Auth::verifyCsrf($token)) {
    Http::json(['ok' => false, 'message' => 'Token CSRF inválido.'], 403);
}

$currentUser = Auth::currentUser();
$uid         = (int) ($currentUser['id'] ?? 0);
if ($uid <= 0) {
    Http::json(['ok' => false, 'message' => 'Usuário não autenticado.'], 401);
}

$file = $_FILES['avatar'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande. Máximo: 2 MB.',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
        default => 'Erro no upload. Tente novamente.',
    };
    Http::json(['ok' => false, 'message' => $msg], 400);
}

// Validate size: max 2 MB
if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
    Http::json(['ok' => false, 'message' => 'Arquivo muito grande. Máximo: 2 MB.'], 400);
}

// Validate image type via GD (not just extension/MIME header)
$tmpPath = (string) ($file['tmp_name'] ?? '');
if (!is_file($tmpPath)) {
    Http::json(['ok' => false, 'message' => 'Arquivo temporário inválido.'], 400);
}

$imageInfo = @getimagesize($tmpPath);
if (!$imageInfo) {
    Http::json(['ok' => false, 'message' => 'O arquivo enviado não é uma imagem válida.'], 400);
}

$allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
if (!in_array($imageInfo[2], $allowedTypes, true)) {
    Http::json(['ok' => false, 'message' => 'Formato não suportado. Use JPG, PNG ou WEBP.'], 400);
}

// ── Save ─────────────────────────────────────────────────────────────────────
$root      = Bootstrap::rootPath();
$avatarDir = $root . '/storage/users/avatars';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}

$destPath = $avatarDir . '/' . $uid . '.jpg';

// Resize and convert to JPEG using GD (128×128 contain, white bg)
$saved = false;
if (function_exists('imagecreatefromjpeg')) {
    $src = match ($imageInfo[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($tmpPath),
        default        => false,
    };

    if ($src !== false) {
        $origW = (int) imagesx($src);
        $origH = (int) imagesy($src);
        $size  = 128;
        $scale = min($size / max($origW, 1), $size / max($origH, 1));
        $newW  = (int) round($origW * $scale);
        $newH  = (int) round($origH * $scale);

        $dst = imagecreatetruecolor($size, $size);
        if ($dst !== false) {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white ?: 0);
            $x = (int) round(($size - $newW) / 2);
            $y = (int) round(($size - $newH) / 2);
            imagecopyresampled($dst, $src, $x, $y, 0, 0, $newW, $newH, $origW, $origH);
            $saved = imagejpeg($dst, $destPath, 88);
            imagedestroy($dst);
        }
        imagedestroy($src);
    }
}

if (!$saved) {
    // GD unavailable or failed — store as-is (converted by move_uploaded_file)
    $saved = move_uploaded_file($tmpPath, $destPath);
}

if (!$saved) {
    Http::json(['ok' => false, 'message' => 'Não foi possível salvar a imagem. Tente novamente.'], 500);
}

// Clean up any alternate extension files left from previous uploads
foreach (['.png', '.webp'] as $ext) {
    $old = $avatarDir . '/' . $uid . $ext;
    if (is_file($old)) {
        @unlink($old);
    }
}

$cacheBuster = (string) filemtime($destPath);

Http::json([
    'ok'  => true,
    'url' => \BIomie\Auth::url(
        'api/avatar.php?uid=' . $uid
        . '&v=' . $cacheBuster
        . '&ctx=' . rawurlencode(TenantContext::contextId())
    ),
]);
