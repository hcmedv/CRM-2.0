<?php
declare(strict_types=1);

/*
  Datei: /public_crm/api/user/api_user_status_set.php
  Zweck:
  - GET  (auth): liest manual_state / pbx_busy für eingeloggten User aus mitarbeiter_status.json
  - POST (auth): setzt manual_state (online|busy|away|off|auto) für eingeloggten User
  - Persistenz: Datei aus Login-Modul (mitarbeiter_status.json) via CRM_LoginPath('status')
*/

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();
header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------
   Status-Datei auflösen (zentral über Login-Modul)
   ------------------------------------------------- */
$userStatusFile = CRM_LoginPath('status');
if ($userStatusFile === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'login_status_path_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

define('USER_STATUS_DEFAULT_MANUAL', 'auto');

/* -------------------------------------------------
   Helper
   ------------------------------------------------- */
function FN_ReadJsonFile(string $path): array
{
    if (!is_file($path)) { return []; }
    $raw = (string)file_get_contents($path);
    if (trim($raw) === '') { return []; }

    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function FN_WriteJsonFileAtomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) { return false; }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) { return false; }

    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }

    if (@rename($tmp, $path)) { return true; }

    @unlink($tmp);
    return (file_put_contents($path, $json, LOCK_EX) !== false);
}

function FN_GetAuthedUserKey(): string
{
    if (isset($_SESSION['crm_user']['user'])) {
        return (string)$_SESSION['crm_user']['user'];
    }
    return '';
}

/* -------------------------------------------------
   Main
   ------------------------------------------------- */
$method  = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$userKey = FN_GetAuthedUserKey();

if ($userKey === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_user_in_session'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db  = FN_ReadJsonFile($userStatusFile);
$row = is_array($db[$userKey] ?? null) ? (array)$db[$userKey] : [];

if ($method === 'GET') {
    echo json_encode([
        'ok'           => true,
        'user'         => $userKey,
        'manual_state' => (string)($row['manual_state'] ?? USER_STATUS_DEFAULT_MANUAL),
        'pbx_busy'     => (bool)($row['pbx_busy'] ?? false),
        'updated_at'   => $row['updated_at'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $in  = json_decode($raw, true);
    if (!is_array($in)) { $in = []; }

    $manual  = trim((string)($in['manual_state'] ?? ''));
    $allowed = ['online', 'busy', 'away', 'off', 'auto'];

    if (!in_array($manual, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_manual_state'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // WICHTIG: busy bleibt busy (nicht auf online mappen!)
    $db[$userKey] = [
        'manual_state' => $manual,
        'pbx_busy'     => (bool)($row['pbx_busy'] ?? false),
        'updated_at'   => date('c'),
    ];

    if (!FN_WriteJsonFileAtomic($userStatusFile, $db)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'write_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'           => true,
        'user'         => $userKey,
        'manual_state' => (string)$db[$userKey]['manual_state'],
        'pbx_busy'     => (bool)$db[$userKey]['pbx_busy'],
        'updated_at'   => (string)$db[$userKey]['updated_at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
