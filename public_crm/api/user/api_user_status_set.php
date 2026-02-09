<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/user/api_user_status_set.php
 *
 * Zweck:
 * - Setzt den manuellen Verfügbarkeitsstatus eines eingeloggten Users
 * - Persistiert nach /data/login/mitarbeiter_status.json
 *
 * Regeln:
 * - logged_in darf NICHT verloren gehen -> Update erfolgt immer per Merge auf bestehenden User-Record
 * - manual_state wird gesetzt (online|busy|away|auto|off)
 * - pbx_busy wird hier NICHT geändert (kommt separat aus PBX)
 *
 * Request:
 * - POST JSON: {"manual_state":"busy"}  oder Form: manual_state=busy
 *
 * Response:
 * - {"ok":true,"user":"admin","row":{...}}
 */

require_once dirname(__DIR__, 2) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('CRM_Auth_IsLoggedIn') || !CRM_Auth_IsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===================================================================================================================== */
/* HELPERS                                                                                                               */
/* ===================================================================================================================== */

function FN_ReadJsonBody(): array
{
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) === '') { return []; }

    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function FN_WriteJsonFileAtomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) { return false; }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') { return false; }

    $tmp = $path . '.tmp.' . (string)getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }

    if (@rename($tmp, $path)) { return true; }
    @unlink($tmp);

    return (file_put_contents($path, $json, LOCK_EX) !== false);
}

/*
 * WICHTIG: Merge-Update, damit Keys wie "logged_in" erhalten bleiben.
 */
function FN_Status_UpdateUser(array $db, string $userKey, array $set): array
{
    $prev = (isset($db[$userKey]) && is_array($db[$userKey])) ? (array)$db[$userKey] : [];

    $allowed = ['manual_state', 'pbx_busy', 'updated_at', 'logged_in'];
    $clean = [];
    foreach ($set as $k => $v) {
        if (in_array((string)$k, $allowed, true)) {
            $clean[$k] = $v;
        }
    }

    $db[$userKey] = $clean + $prev;

    return $db;
}

function FN_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===================================================================================================================== */
/* INPUT                                                                                                                 */
/* ===================================================================================================================== */

$in = FN_ReadJsonBody();
$state = trim((string)($in['manual_state'] ?? ($_POST['manual_state'] ?? '')));

$allowedStates = ['online', 'busy', 'away', 'auto', 'off'];
if (!in_array($state, $allowedStates, true)) {
    FN_Out(['ok' => false, 'error' => 'invalid_state', 'allowed' => $allowedStates], 400);
}

$u = (function_exists('CRM_Auth_User') ? (array)CRM_Auth_User() : []);
$userKey = trim((string)($u['user'] ?? ''));
if ($userKey === '') {
    FN_Out(['ok' => false, 'error' => 'user_missing'], 500);
}

$statusFile = (function_exists('CRM_LoginPath') ? (string)CRM_LoginPath('status') : '');
if ($statusFile === '') {
    FN_Out(['ok' => false, 'error' => 'status_file_missing'], 500);
}

/* ===================================================================================================================== */
/* UPDATE                                                                                                                */
/* ===================================================================================================================== */

$db = CRM_LoadJsonFile($statusFile, []);
if (!is_array($db)) { $db = []; }

$db = FN_Status_UpdateUser($db, $userKey, [
    'manual_state' => $state,
    'updated_at'   => date('c'),
    // logged_in bleibt unberührt
    // pbx_busy bleibt unberührt
]);

if (!FN_WriteJsonFileAtomic($statusFile, $db)) {
    FN_Out(['ok' => false, 'error' => 'write_failed'], 500);
}

$row = (isset($db[$userKey]) && is_array($db[$userKey])) ? $db[$userKey] : [];

FN_Out([
    'ok'   => true,
    'user' => $userKey,
    'row'  => $row,
]);
