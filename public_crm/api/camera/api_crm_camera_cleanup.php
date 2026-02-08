<?php
declare(strict_types=1);

/*
 * Datei: /public/api/camera/api_camera_cleanup.php
 * Zweck:
 * - Löscht eine Camera-Upload-Session im TMP (Full/Thumb/meta.jsonl)
 * - Auth (+ optional CSRF) geschützt
 *
 * Erwartet:
 * - POST (application/x-www-form-urlencoded oder application/json)
 *   - session (string) Pflicht
 *
 * Antwort:
 * - { ok:true, deleted:true|false, session:"..." }
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

if (function_exists('CRM_CsrfCheckRequest')) {
    CRM_CsrfCheckRequest();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function FN_Exit(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function FN_SafeToken(string $s, int $maxLen = 64): string
{
    $s = trim($s);
    if ($s === '') { return ''; }
    $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
    return substr($s, 0, $maxLen);
}

function FN_RmTree(string $dir): bool
{
    if ($dir === '' || !is_dir($dir)) { return false; }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $f) {
        $p = (string)$f->getPathname();
        if ($f->isDir()) { @rmdir($p); } else { @unlink($p); }
    }

    @rmdir($dir);
    return !is_dir($dir);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    FN_Exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

/* -------------------------------------------------
   Input: session aus POST oder JSON
------------------------------------------------- */
$session = '';
if (isset($_POST['session'])) {
    $session = (string)$_POST['session'];
} else {
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $session = (string)($dec['session'] ?? '');
        }
    }
}

$session = FN_SafeToken($session);
if ($session === '') {
    FN_Exit(['ok' => false, 'error' => 'missing_session'], 400);
}

/* -------------------------------------------------
   TMP Root aus Modul-Settings
------------------------------------------------- */
$tmpBase = rtrim(str_replace('\\', '/', (string)CRM_MOD_PATH('camera', 'tmp')), '/');
$tmpRel  = (string)CRM_MOD_CFG('camera', 'upload.tmp_dir', 'camera'); // z.B. 'camera'

$tmpRoot = $tmpBase . '/' . ltrim($tmpRel, '/');
$tmpRoot = rtrim(str_replace('\\', '/', $tmpRoot), '/');

if ($tmpRoot === '' || !is_dir($tmpRoot)) {
    FN_Exit(['ok' => false, 'error' => 'camera_tmp_root_missing'], 500);
}

$rootReal = realpath($tmpRoot);
if ($rootReal === false) {
    FN_Exit(['ok' => false, 'error' => 'camera_tmp_root_invalid'], 500);
}
$rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');

/* -------------------------------------------------
   Session Dir + Safety
------------------------------------------------- */
$sessionDir = $tmpRoot . '/' . $session;

$sessionReal = realpath($sessionDir);
if ($sessionReal === false) {
    FN_Exit(['ok' => true, 'deleted' => false, 'session' => $session, 'error' => 'session_not_found'], 200);
}
$sessionReal = rtrim(str_replace('\\', '/', $sessionReal), '/');

if (strpos($sessionReal, $rootReal . '/') !== 0) {
    FN_Exit(['ok' => false, 'error' => 'path_invalid'], 400);
}

$deleted = FN_RmTree($sessionReal);

FN_Exit(['ok' => true, 'deleted' => $deleted, 'session' => $session]);
