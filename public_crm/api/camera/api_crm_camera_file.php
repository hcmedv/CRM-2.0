<?php
declare(strict_types=1);

/*
 * Datei: /public/api/camera/api_camera_file.php
 * Zweck:
 * - Auth-geschützter File-Endpoint für Kamera-Bilder (TMP + DATA)
 * - Liefert Full/Thumb für UI-Preview (Lightbox/Thumbnails) und finalisierte Dateien aus /data
 *
 * Query:
 * - scope   (string)  'tmp' | 'data'   (default: tmp)
 * - type    (string)  'full' | 'thumb' (default: full)
 * - file    (string)  Pflicht (Dateiname inkl. Endung: jpg/jpeg/png/webp)
 *
 * TMP (scope=tmp):
 * - session (string)  Pflicht
 * - Pfad: <MOD_TMP>/<upload.tmp_dir>/<session>/[thumb/]<file>
 *
 * DATA (scope=data):
 * - kn      (string)  Pflicht (Kundennummer)
 * - session (string)  optional (falls später pro Session final abgelegt wird)
 * - Pfad: <MOD_DATA>/<files.store_dir>/<kn>/[<session>/][thumb/]<file>
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('X-Content-Type-Options: nosniff');

function FN_SafeToken(string $s, int $maxLen = 64): string
{
    $s = trim($s);
    if ($s === '') { return ''; }
    $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
    return substr($s, 0, $maxLen);
}

function FN_SafeFile(string $s, int $maxLen = 180): string
{
    $s = trim($s);
    if ($s === '') { return ''; }

    $s = basename(str_replace('\\', '/', $s));
    $s = preg_replace('/[^0-9A-Za-z_\-\.]/', '', $s) ?? '';
    $s = substr($s, 0, $maxLen);

    if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', $s)) { return ''; }
    return $s;
}

function FN_MimeByExt(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png':  return 'image/png';
        case 'webp': return 'image/webp';
        case 'jpg':
        case 'jpeg':
        default:     return 'image/jpeg';
    }
}

function FN_Exit(int $code, string $msg = ''): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/* -------------------------------------------------
   Input
------------------------------------------------- */
$scope   = strtolower(trim((string)($_GET['scope'] ?? 'tmp')));
$type    = strtolower(trim((string)($_GET['type'] ?? 'full')));
$file    = FN_SafeFile((string)($_GET['file'] ?? ''));

$session = FN_SafeToken((string)($_GET['session'] ?? ''), 64);
$kn      = FN_SafeToken((string)($_GET['kn'] ?? ''), 32);

if ($file === '') {
    FN_Exit(400, 'bad_request');
}

if ($scope !== 'data') { $scope = 'tmp'; }
if ($type !== 'thumb') { $type = 'full'; }

/* -------------------------------------------------
   Settings / Roots (aus settings_camera.php)
------------------------------------------------- */
$tmpBase  = rtrim(str_replace('\\', '/', (string)CRM_MOD_PATH('camera', 'tmp')), '/');
$dataBase = rtrim(str_replace('\\', '/', (string)CRM_MOD_PATH('camera', 'data')), '/');

$tmpDirRel   = (string)CRM_MOD_CFG('camera', 'upload.tmp_dir', 'camera');     // z.B. 'camera'
$storeDirRel = (string)CRM_MOD_CFG('camera', 'files.store_dir', 'camera');    // z.B. 'camera'

$tmpRoot  = $tmpBase  . '/' . ltrim($tmpDirRel, '/');
$dataRoot = $dataBase . '/' . ltrim($storeDirRel, '/');

$tmpRoot  = rtrim(str_replace('\\', '/', $tmpRoot), '/');
$dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');

/* -------------------------------------------------
   Build Path
------------------------------------------------- */
$root = '';
$path = '';

if ($scope === 'tmp') {

    if ($session === '') {
        FN_Exit(400, 'missing_session');
    }

    $root = $tmpRoot;
    $baseDir = $root . '/' . $session;

    $path = ($type === 'thumb')
        ? ($baseDir . '/thumb/' . $file)
        : ($baseDir . '/' . $file);

    header('Cache-Control: no-store');

} else {

    if ($kn === '') {
        FN_Exit(400, 'missing_kn');
    }

    $root = $dataRoot;
    $baseDir = $root . '/' . $kn;

    if ($session !== '') {
        $baseDir .= '/' . $session;
    }

    $path = ($type === 'thumb')
        ? ($baseDir . '/thumb/' . $file)
        : ($baseDir . '/' . $file);

    header('Cache-Control: private, max-age=3600');
}

/* -------------------------------------------------
   Realpath-Safety
------------------------------------------------- */
$rootReal = realpath($root);
if ($rootReal === false) {
    FN_Exit(500, 'root_missing');
}
$rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');

$pathReal = realpath($path);
if ($pathReal === false) {
    FN_Exit(404, 'not_found');
}
$pathReal = str_replace('\\', '/', $pathReal);

if (strpos($pathReal, $rootReal . '/') !== 0) {
    FN_Exit(400, 'path_invalid');
}

if (!is_file($pathReal)) {
    FN_Exit(404, 'not_found');
}

/* -------------------------------------------------
   Output
------------------------------------------------- */
header('Content-Type: ' . FN_MimeByExt($file));

$fp = fopen($pathReal, 'rb');
if (!$fp) {
    FN_Exit(500, 'read_failed');
}

fpassthru($fp);
fclose($fp);
exit;
