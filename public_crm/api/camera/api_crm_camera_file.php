<?php
declare(strict_types=1);

/*
 * Datei: /public/api/camera/api_crm_camera_file.php
 *
 * Zweck:
 * - Auth-geschützter File-Endpoint für Kamera-Bilder (TMP + DATA)
 * - Liefert Full/Thumb für UI-Preview (Queue/Lightbox) und finalisierte Dateien aus /data
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
 * - Pfad: <MOD_DATA>/<kn>/[thumb/]<file>
 *
 * Hinweis:
 * - thumb/ wird NICHT im Event gespeichert. Dieser Endpoint baut thumb/ selbst über type=thumb.
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

if (function_exists('CRM_CsrfCheckRequest')) {
    // Optional – falls du GETs ohne CSRF willst, diese Zeile entfernen
    CRM_CsrfCheckRequest();
}

header('X-Content-Type-Options: nosniff');

/* =========================================================
   Helpers
   ========================================================= */

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

function FN_PathNorm(string $p): string
{
    return rtrim(str_replace('\\', '/', $p), '/');
}

function FN_IsPathInside(string $path, string $root): bool
{
    $path = FN_PathNorm($path);
    $root = FN_PathNorm($root);
    if ($path === '' || $root === '') { return false; }
    return (strpos($path . '/', $root . '/') === 0);
}

function FN_TmpRootResolved(): string
{
    $base = FN_PathNorm((string)CRM_MOD_PATH('camera', 'tmp')); // kann .../tmp oder .../tmp/camera sein

    $tmpSub = (string)CRM_MOD_CFG('camera', 'upload.tmp_dir', 'camera');
    $tmpSub = trim(str_replace('\\', '/', $tmpSub), '/');

    if ($base === '') { return ''; }

    if ($tmpSub !== '' && preg_match('#/' . preg_quote($tmpSub, '#') . '$#', $base)) {
        return $base;
    }

    return $base . ($tmpSub !== '' ? '/' . $tmpSub : '');
}

function FN_DataRootResolvedForKn(string $kn): string
{
    $base = FN_PathNorm((string)CRM_MOD_PATH('camera', 'data')); // z.B. .../data/camera
    if ($base === '') { return ''; }

    // Finalize legt bei dir direkt unter <dataBase>/<KN>/... ab (ohne extra store_dir)
    return $base . '/' . $kn;
}

/* =========================================================
   Input
   ========================================================= */

$scope   = strtolower(trim((string)($_GET['scope'] ?? 'tmp')));
$type    = strtolower(trim((string)($_GET['type'] ?? 'full')));

$file    = FN_SafeFile((string)($_GET['file'] ?? ''));
$session = FN_SafeToken((string)($_GET['session'] ?? ''), 80);
$kn      = FN_SafeToken((string)($_GET['kn'] ?? ''), 32);

if ($file === '') {
    FN_Exit(400, 'bad_request');
}

if ($scope !== 'data') { $scope = 'tmp'; }
if ($type !== 'thumb') { $type = 'full'; }

/* =========================================================
   Build Path
   ========================================================= */

$root = '';
$path = '';

if ($scope === 'tmp') {

    if ($session === '') {
        FN_Exit(400, 'missing_session');
    }

    $root = FN_TmpRootResolved();
    if ($root === '') {
        FN_Exit(500, 'tmp_root_missing');
    }

    $baseDir = $root . '/' . $session;

    $path = ($type === 'thumb')
        ? ($baseDir . '/thumb/' . $file)
        : ($baseDir . '/' . $file);

    header('Cache-Control: no-store');

} else {

    if ($kn === '') {
        FN_Exit(400, 'missing_kn');
    }

    $root = FN_DataRootResolvedForKn($kn);
    if ($root === '') {
        FN_Exit(500, 'data_root_missing');
    }

    $path = ($type === 'thumb')
        ? ($root . '/thumb/' . $file)
        : ($root . '/' . $file);

    header('Cache-Control: private, max-age=3600');
}

/* =========================================================
   Realpath-Safety
   ========================================================= */

$rootReal = realpath($root);
if ($rootReal === false) {
    FN_Exit(500, 'root_missing');
}
$rootReal = FN_PathNorm($rootReal);

$pathReal = realpath($path);
if ($pathReal === false) {
    FN_Exit(404, 'not_found');
}
$pathReal = str_replace('\\', '/', $pathReal);

if (!FN_IsPathInside($pathReal, $rootReal)) {
    FN_Exit(400, 'path_invalid');
}

if (!is_file($pathReal)) {
    FN_Exit(404, 'not_found');
}

/* =========================================================
   Output
   ========================================================= */

header('Content-Type: ' . FN_MimeByExt($file));

$fp = fopen($pathReal, 'rb');
if (!$fp) {
    FN_Exit(500, 'read_failed');
}

fpassthru($fp);
fclose($fp);
exit;
