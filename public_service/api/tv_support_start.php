<?php
declare(strict_types=1);

/*
  Datei: /public_service/api/tv_support_start.php
  Zweck:
  - Service-Endpunkt: nimmt Support-Code entgegen und liefert End-Customer-Link als JSON
  - Same-Origin (Service): kein CORS, kein Login
  - Liest Sessions aus JSON-Store (wie vom internen CRM-Tool geschrieben)
  - Validiert: code, expires_at, used(optional)
  - Optional: markiert Session als used
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

######## SETTINGS ########################################################################################################################

define('STORE_DIR',  'teamviewer');                         // MUSS zu deinem Writer passen (aktuell: teamviewer)
define('STORE_FILE', 'teamviewer_support_sessions.json');   // MUSS zu settings_teamviewer.php passen
define('MAX_READ_BYTES', 1024 * 1024);                      // 1 MB Safety
define('ALLOW_REUSE', false);                               // false = nach "used" nicht erneut ausgeben

######## HELPER ########################################################################################################################

function FN_JsonOut(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function FN_PathStoreFile(): string
{
    $base = realpath(__DIR__ . '/../../data');
    if ($base === false) { $base = __DIR__ . '/../../data'; }
    return rtrim((string)$base, '/\\') . '/' . STORE_DIR . '/' . STORE_FILE;
}

function FN_ReadJsonFile(string $file): array
{
    if (!is_file($file)) { return []; }

    $size = (int)@filesize($file);
    if ($size <= 0) { return []; }
    if ($size > MAX_READ_BYTES) { return ['_err' => 'file_too_large']; }

    $raw = (string)@file_get_contents($file);
    if ($raw === '') { return []; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return ['_err' => 'non_json']; }

    return $j;
}

function FN_SaveJsonLocked(string $file, array $db): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) { return false; }
    }

    $fp = @fopen($file, 'c+');
    if ($fp === false) { return false; }

    $ok = false;
    if (flock($fp, LOCK_EX)) {
        $db['updated_at'] = date('c');
        $raw = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($raw !== false) {
            ftruncate($fp, 0);
            rewind($fp);
            $ok = (fwrite($fp, $raw) !== false);
            fflush($fp);
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    return $ok;
}

function FN_ReadInputJson(): array
{
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) === '') { return []; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

######## MAIN ###########################################################################################################################

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    FN_JsonOut(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$in = FN_ReadInputJson();
$code = trim((string)($in['code'] ?? ''));
$code = preg_replace('/\s+/', '', $code); // Leerzeichen im Code erlauben

if ($code === '') {
    FN_JsonOut(200, ['ok' => false, 'error' => 'code_empty']);
}

$storeFile = FN_PathStoreFile();
$db = FN_ReadJsonFile($storeFile);
if (!is_array($db) || isset($db['_err'])) {
    FN_JsonOut(502, ['ok' => false, 'error' => 'store_read_failed', 'detail' => (string)($db['_err'] ?? '')]);
}

$sessions = (array)($db['sessions'] ?? []);
$now = time();

$foundIdx = -1;
$found = null;

foreach ($sessions as $i => $s) {
    if (!is_array($s)) { continue; }

    $sCode = preg_replace('/\s+/', '', (string)($s['code'] ?? ''));
    if ($sCode !== $code) { continue; }

    $exp = (int)($s['expires_at'] ?? 0);
    if ($exp > 0 && $exp < $now) { continue; } // abgelaufen

    $used = (bool)($s['used'] ?? false);
    if ($used && !ALLOW_REUSE) { continue; }

    $link = trim((string)($s['end_customer_link'] ?? ''));
    if ($link === '') { continue; }

    $foundIdx = (int)$i;
    $found = $s;
    break;
}

if ($foundIdx < 0 || !is_array($found)) {
    FN_JsonOut(200, ['ok' => false, 'error' => 'not_found_or_expired']);
}

// optional: als "used" markieren + IP setzen
$found['used'] = true;
$found['used_at'] = $now;
$found['used_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');

$sessions[$foundIdx] = $found;
$db['sessions'] = $sessions;

FN_SaveJsonLocked($storeFile, $db);

FN_JsonOut(200, [
    'ok'   => true,
    'link' => (string)$found['end_customer_link'],
    'key'  => (string)($found['key'] ?? '')
]);
