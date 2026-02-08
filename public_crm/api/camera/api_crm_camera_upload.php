<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/*
 * Datei: /public/api/camera/api_crm_camera_upload.php
 * Zweck:
 * - Kamera Upload Endpoint (multipart/form-data)
 * - Auth: CRM_Auth_RequireLoginApi()
 * - Optional CSRF-Check (falls CRM_CsrfCheckRequest existiert)
 * - Modul-Guards via settings_crm.php:
 *   - modules.camera === true
 *   - modules.events === true
 *   - events.allowed.event_sources enthält "camera"
 *
 * Erwartet:
 * - POST multipart/form-data:
 *   - image (Blob/File)        Pflicht
 *   - session (string)         Pflicht
 *   - kunden_nummer (string)   Pflicht
 *
 * Antwort:
 * - { ok:true, item:{ id, ts, kunden_nummer, w,h, full, thumb } }
 *
 * WICHTIG:
 * - full/thumb sind PREVIEW-URLs über api_crm_camera_file.php (Auth-geschützt)
 *   und NICHT direkte /tmp/... Pfade.
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (function_exists('CRM_CsrfCheckRequest')) {
    CRM_CsrfCheckRequest();
}

/* =========================================================================================
   Helpers: Exit / Config / Guards
   ========================================================================================= */

function FN_Exit(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function FN_CfgGet(string $path, $default = null)
{
    if (function_exists('CFG_GET')) {
        return CFG_GET($path, $default);
    }
    if (function_exists('CFG')) {
        return CFG($path, $default);
    }

    $cfg = $GLOBALS['__CRM_CONFIG'] ?? null;
    if (!is_array($cfg)) {
        return $default;
    }

    $parts = explode('.', $path);
    $cur = $cfg;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }

    return $cur;
}

function FN_AssertModuleEnabled(string $module): void
{
    $modules = FN_CfgGet('modules', []);
    if (!is_array($modules)) {
        $modules = [];
    }

    if (!isset($modules[$module]) || $modules[$module] !== true) {
        FN_Exit(['ok' => false, 'error' => 'module_disabled', 'module' => $module], 403);
    }
}

function FN_AssertEventSourceAllowed(string $source): void
{
    $allowed = FN_CfgGet('events.allowed.event_sources', []);
    if (!is_array($allowed)) {
        $allowed = [];
    }

    if (!in_array($source, $allowed, true)) {
        FN_Exit(['ok' => false, 'error' => 'event_source_not_allowed', 'source' => $source], 403);
    }
}

/* Guards: Camera nutzt Events-Infrastruktur + Source camera */
FN_AssertModuleEnabled('camera');
FN_AssertModuleEnabled('events');
FN_AssertEventSourceAllowed('camera');

/* =========================================================================================
   Settings (settings_camera.php)
   ========================================================================================= */

$maxBytes   = (int)FN_CfgGet('camera.upload.max_file_size_bytes', 12_000_000);
$thumbMaxPx = (int)FN_CfgGet('camera.upload.thumb_max_px', 512);
$tmpSubDir  = (string)FN_CfgGet('camera.upload.tmp_dir', 'camera'); // unterhalb paths.tmp -> /tmp/<tmpSubDir>

/* =========================================================================================
   Helpers: Sanitizer / FS / Image
   ========================================================================================= */

function FN_SafeToken(string $s, int $maxLen = 64): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
    return substr($s, 0, $maxLen);
}

function FN_SafeKn(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
    return substr($s, 0, 32);
}

function FN_EnsureDir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    @mkdir($dir, 0775, true);
    return is_dir($dir);
}

function FN_RandToken(int $bytes = 6): string
{
    return bin2hex(random_bytes($bytes));
}

function FN_LoadImageFromUpload(string $tmpName): array
{
    $data = @file_get_contents($tmpName);
    if ($data === false || $data === '') {
        return [null, 'upload_read_failed'];
    }

    $img = @imagecreatefromstring($data);
    if (!$img) {
        return [null, 'image_decode_failed'];
    }

    return [$img, ''];
}

function FN_SaveJpeg($img, string $path, int $quality = 90): bool
{
    @imageinterlace($img, true);
    return @imagejpeg($img, $path, $quality);
}

function FN_CreateThumb($img, string $thumbPath, int $thumbMaxPx): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) {
        return false;
    }

    $thumbMaxPx = max(64, (int)$thumbMaxPx);

    $scale = min($thumbMaxPx / $w, $thumbMaxPx / $h, 1.0);
    $nw = (int)max(1, floor($w * $scale));
    $nh = (int)max(1, floor($h * $scale));

    $thumb = imagecreatetruecolor($nw, $nh);
    if (!$thumb) {
        return false;
    }

    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    return FN_SaveJpeg($thumb, $thumbPath, 85);
}

function FN_BuildCameraFileUrlTmp(string $type, string $session, string $file): string
{
    $type = ($type === 'thumb') ? 'thumb' : 'full';

    return '/api/camera/api_crm_camera_file.php?scope=tmp'
        . '&type=' . rawurlencode($type)
        . '&session=' . rawurlencode($session)
        . '&file=' . rawurlencode($file);
}

/* =========================================================================================
   Method / Input
   ========================================================================================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    FN_Exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$session = FN_SafeToken((string)($_POST['session'] ?? ''));
if ($session === '') {
    FN_Exit(['ok' => false, 'error' => 'missing_session'], 400);
}

$kn = FN_SafeKn((string)($_POST['kunden_nummer'] ?? ''));
if ($kn === '') {
    FN_Exit(['ok' => false, 'error' => 'missing_kunden_nummer'], 400);
}

if (!isset($_FILES['image'])) {
    FN_Exit(['ok' => false, 'error' => 'no_file'], 400);
}

$f = $_FILES['image'];
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    FN_Exit(['ok' => false, 'error' => 'upload_error'], 400);
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    FN_Exit(['ok' => false, 'error' => 'file_too_large', 'max' => $maxBytes], 400);
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    FN_Exit(['ok' => false, 'error' => 'invalid_upload'], 400);
}

/* =========================================================================================
   Target dirs (TMP)
   ========================================================================================= */

$baseTmp = '';

// bevorzugt: CRM_MOD_PATH('camera','tmp') – kann je nach Implementierung bereits /tmp oder /tmp/camera liefern
if (function_exists('CRM_MOD_PATH')) {
    $baseTmp = (string)CRM_MOD_PATH('camera', 'tmp');
}
$baseTmp = rtrim(str_replace('\\', '/', $baseTmp), '/');

// Fallback: globaler tmp-root aus settings_crm.php
if ($baseTmp === '') {
    $baseTmp = (string)FN_CfgGet('paths.tmp', '');
    $baseTmp = rtrim(str_replace('\\', '/', $baseTmp), '/');
}

if ($baseTmp === '') {
    FN_Exit(['ok' => false, 'error' => 'camera_tmp_dir_missing'], 500);
}

$tmpSubDir = trim(str_replace('\\', '/', $tmpSubDir), '/');

// Duplikate vermeiden, falls CRM_MOD_PATH bereits .../tmp/<tmpSubDir> liefert
$baseTmpNorm = $baseTmp;
if ($tmpSubDir !== '' && preg_match('#/' . preg_quote($tmpSubDir, '#') . '$#', $baseTmpNorm)) {
    $tmpRoot = $baseTmpNorm;
} else {
    $tmpRoot = $baseTmpNorm . ($tmpSubDir !== '' ? '/' . $tmpSubDir : '');
}
$tmpRoot = rtrim(str_replace('\\', '/', $tmpRoot), '/');

if (!FN_EnsureDir($tmpRoot)) {
    FN_Exit(['ok' => false, 'error' => 'camera_tmp_dir_create_failed'], 500);
}

$sessionDir = $tmpRoot . '/' . $session;
$thumbDir   = $sessionDir . '/thumb';

if (!FN_EnsureDir($sessionDir) || !FN_EnsureDir($thumbDir)) {
    FN_Exit(['ok' => false, 'error' => 'session_dir_create_failed'], 500);
}

/* =========================================================================================
   Decode + Save
   ========================================================================================= */

[$img, $err] = FN_LoadImageFromUpload($tmp);
if (!$img) {
    FN_Exit(['ok' => false, 'error' => ($err !== '' ? $err : 'image_invalid')], 400);
}

$ts   = time();
$rand = FN_RandToken(5);
$id   = $ts . '_' . $rand;

$baseName  = 'KN_' . $kn . '_' . $id;
$fullName  = $baseName . '.jpg';
$thumbName = $baseName . '_t.jpg';

$fullPath  = $sessionDir . '/' . $fullName;
$thumbPath = $thumbDir   . '/' . $thumbName;

$w = imagesx($img);
$h = imagesy($img);

$ok1 = FN_SaveJpeg($img, $fullPath, 90);
$ok2 = FN_CreateThumb($img, $thumbPath, $thumbMaxPx);

// PHP 8+ – imagedestroy ist ok, aber nicht nötig; wir vermeiden es hier bewusst.

if (!$ok1 || !$ok2) {
    @unlink($fullPath);
    @unlink($thumbPath);
    FN_Exit(['ok' => false, 'error' => 'save_failed'], 500);
}

/* =========================================================================================
   Public URLs (für UI Vorschau) – über File-API
   ========================================================================================= */

$item = [
    'id'            => $id,
    'created_at'    => gmdate('c'),
    'ts'            => $ts,
    'kunden_nummer' => $kn,
    'w'             => $w,
    'h'             => $h,
    'full'          => FN_BuildCameraFileUrlTmp('full', $session, $fullName),
    'thumb'         => FN_BuildCameraFileUrlTmp('thumb', $session, $thumbName),
];

$metaLine = [
    'created_at' => gmdate('c'),
    'session'    => $session,
    'item'       => $item,
    'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
    'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
];

@file_put_contents(
    $sessionDir . '/meta.jsonl',
    json_encode($metaLine, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

FN_Exit(['ok' => true, 'item' => $item]);
