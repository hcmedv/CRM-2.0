<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/cti/crm_cti_dial.php
 *
 * Zweck:
 * - CTI Aktion (Sipgate): Click-to-Dial
 * - POST JSON: { number:"+49...", caller:"e5"(optional) }
 * - Auth via Session (CRM_Auth_RequireLoginApi)
 * - Caller-Device wird über settings_crm.php eingeschränkt (allowed_devices)
 *
 * Logging:
 * - /log/cti_dial_YYYY-MM-DD.log
 *
 * Secrets:
 * - werden aus /config/secrets_crm.php geladen (return array), Block-Key aus settings: cti.sipgate.secret_key
 *   Beispiel secrets_crm.php:
 *   return ['SIPGATE_CTI' => ['TOKEN_ID'=>'...', 'TOKEN_SECRET'=>'...']];
 */

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function FN_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function FN_Log(string $msg, array $ctx = []): void
{
    $dir = CRM_BASE . '/log';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $file = $dir . '/cti_dial_' . date('Y-m-d') . '.log';
    $row = ['ts' => date('c'), 'msg' => $msg, 'ctx' => $ctx];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

/*
 * 0001 - FN_NormalizeE164
 * Grobe Normalisierung (DE Heuristik) → +49…
 */
function FN_NormalizeE164(string $in): string
{
    $s = trim($in);
    if ($s === '') return '';

    $s = preg_replace('/[^\d+]/', '', $s) ?? '';
    if ($s === '') return '';

    if ($s[0] === '+') return $s;

    if (strpos($s, '00') === 0) return '+' . substr($s, 2);

    if (strpos($s, '0') === 0) return '+49' . ltrim(substr($s, 1), '0');

    return '+' . $s;
}

/*
 * 0002 - FN_LoadSecrets
 * Lädt optional config/secrets_crm.php (return array).
 */
function FN_LoadSecrets(): array
{
    $f = CRM_BASE . '/config/secrets_crm.php';
    if (!is_file($f)) return [];
    $a = require $f;
    return is_array($a) ? $a : [];
}

/*
 * 0003 - FN_SipgateRequest
 * cURL Request (Basic Auth) gegen Sipgate v2.
 */
function FN_SipgateRequest(string $apiBase, string $tokenId, string $tokenSecret, string $method, string $path, ?array $body = null): array
{
    $url = rtrim($apiBase, '/') . '/' . ltrim($path, '/');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $headers = [
        'Accept: application/json',
    ];

    $m = strtoupper($method);
    if ($m === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = $body ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
    } elseif ($m !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $m);
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, $tokenId . ':' . $tokenSecret);

    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = null;
    if ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $json = $tmp;
    }

    return [
        'ok'       => ($code >= 200 && $code < 300),
        'http_code'=> $code,
        'raw'      => $raw,
        'json'     => $json,
        'curl_err' => $err,
    ];
}

/* -------------------- Request / Config -------------------- */

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') FN_Out(['ok' => false, 'error' => 'method_not_allowed'], 405);

$raw = (string)file_get_contents('php://input');
$in  = [];
$j = ($raw !== '') ? json_decode($raw, true) : null;
if (is_array($j)) $in = $j;

$numberIn = (string)($in['number'] ?? $in['to'] ?? $in['callee'] ?? '');
$callerIn = (string)($in['caller'] ?? '');

$callee = FN_NormalizeE164($numberIn);
if ($callee === '' || strlen($callee) < 6) {
    FN_Log('dial_reject_invalid_number', ['in' => $numberIn, 'norm' => $callee]);
    FN_Out(['ok' => false, 'error' => 'invalid_number'], 400);
}

$cti = (array)CRM_CFG('cti', []);
$sip = (array)($cti['sipgate'] ?? []);

if (!(bool)($sip['enabled'] ?? false)) FN_Out(['ok' => false, 'error' => 'cti_disabled'], 400);

$apiBase = (string)($sip['api_base'] ?? 'https://api.sipgate.com/v2');
$secretKey = (string)($sip['secret_key'] ?? 'SIPGATE_CTI');

$allowed = (array)($sip['allowed_devices'] ?? []);
$allowed = array_values(array_filter(array_map('strval', $allowed)));

$caller = trim($callerIn);
if ($caller === '') $caller = trim((string)($sip['default_device'] ?? ''));

$useSipgateDefault = (bool)($sip['use_sipgate_default_device'] ?? true);

/* Secrets */
$secrets = FN_LoadSecrets();
$sec = (array)($secrets[$secretKey] ?? []);
$tokenId     = (string)($sec['TOKEN_ID'] ?? '');
$tokenSecret = (string)($sec['TOKEN_SECRET'] ?? '');

if ($tokenId === '' || $tokenSecret === '') {
    FN_Log('dial_reject_missing_secrets', ['secret_key' => $secretKey]);
    FN_Out(['ok' => false, 'error' => 'missing_secrets', 'secret_key' => $secretKey], 500);
}

/* Sipgate: /users -> userId + defaultDevice */
$users = FN_SipgateRequest($apiBase, $tokenId, $tokenSecret, 'GET', '/users', null);
if (!($users['ok'] ?? false) || !is_array($users['json'])) {
    FN_Log('dial_fail_users', ['http' => $users['http_code'] ?? 0, 'err' => $users['curl_err'] ?? '']);
    FN_Out(['ok' => false, 'error' => 'sipgate_users_failed'], 502);
}

$userId = '';
$defaultDevice = '';

$items = (array)($users['json']['items'] ?? []);
foreach ($items as $u) {
    if (!is_array($u)) continue;
    if (($u['id'] ?? '') !== '') { $userId = (string)$u['id']; }
    if (($u['defaultDevice'] ?? '') !== '') { $defaultDevice = (string)$u['defaultDevice']; }
    if ($userId !== '' && $defaultDevice !== '') break;
}

if ($caller === '' && $useSipgateDefault && $defaultDevice !== '') $caller = $defaultDevice;

if ($caller === '') FN_Out(['ok' => false, 'error' => 'missing_caller'], 400);

/* Allowlist enforce */
if (count($allowed) > 0 && !in_array($caller, $allowed, true)) {
    FN_Log('dial_reject_caller_not_allowed', ['caller' => $caller, 'allowed' => $allowed]);
    FN_Out(['ok' => false, 'error' => 'caller_not_allowed', 'caller' => $caller], 403);
}

/* Sipgate: Start call */
$call = FN_SipgateRequest($apiBase, $tokenId, $tokenSecret, 'POST', '/sessions/calls', [
    'caller' => $caller,
    'callee' => $callee,
]);

if (!($call['ok'] ?? false) || !is_array($call['json'])) {
    FN_Log('dial_fail_call', ['http' => $call['http_code'] ?? 0, 'caller' => $caller, 'callee' => $callee]);
    FN_Out(['ok' => false, 'error' => 'sipgate_call_failed'], 502);
}

$sessionId = (string)($call['json']['sessionId'] ?? '');

FN_Log('dial_ok', [
    'user' => (CRM_Auth_User()['user'] ?? ''),
    'caller' => $caller,
    'callee' => $callee,
    'sessionId' => $sessionId,
]);

FN_Out([
    'ok' => true,
    'provider' => 'sipgate',
    'caller' => $caller,
    'callee' => $callee,
    'sessionId' => $sessionId,
]);
