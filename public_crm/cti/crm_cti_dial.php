<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/cti/crm_cti_dial.php
 *
 * Zweck:
 * - Click-to-Dial über Sipgate (v2 API)
 * - Auth via Session
 * - Caller-Gerät via Allowlist einschränken (cti.sipgate.allowed_devices)
 * - Secrets via CRM_SECRET() (Modul-Secrets: /config/cti/secrets_cti.php)
 *
 * Request:
 * - POST JSON: { "number":"+49...", "caller":"e0"(optional) }
 *
 * Debug:
 * - Optional GET (nur wenn CRM_DEBUG === true): ?number=...&caller=...
 *
 * Logging:
 * - Nur wenn cti.sipgate.debug === true ODER CRM_DEBUG === true
 * - /log/cti_dial_YYYY-MM-DD.log
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
 * 0002 - FN_IsDebugEnabled
 * Debug ist aktiv, wenn Modul-Flag gesetzt oder CRM_DEBUG true ist.
 */
function FN_IsDebugEnabled(array $sipgateCfg): bool
{
    $mod = (bool)($sipgateCfg['debug'] ?? false);
    $glob = (defined('CRM_DEBUG') && CRM_DEBUG);
    return ($mod || $glob);
}

/*
 * 0003 - FN_Log
 * Debug-Log (nur wenn Debug aktiv)
 */
function FN_Log(bool $debug, string $msg, array $ctx = []): void
{
    if (!$debug) return;

    $dir = CRM_BASE . '/log';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $file = $dir . '/cti_dial_' . date('Y-m-d') . '.log';
    $row  = ['ts' => date('c'), 'msg' => $msg, 'ctx' => $ctx];

    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

/*
 * 0004 - FN_SipgateRequest
 * Sipgate v2 API Request per cURL (Basic Auth).
 */
function FN_SipgateRequest(string $apiBase, string $tokenId, string $tokenSecret, string $method, string $path, ?array $body = null): array
{
    $url = rtrim($apiBase, '/') . '/' . ltrim($path, '/');

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'raw' => '', 'json' => null, 'curl_err' => 'curl_init_failed', 'url' => $url];
    }

    $headers = ['Accept: application/json'];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERPWD, $tokenId . ':' . $tokenSecret);

    $m = strtoupper(trim($method));
    if ($m === '') { $m = 'GET'; }

    if ($m === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif ($m === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);

        $payload = '{}';
        if (is_array($body)) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) { $payload = '{}'; }
        }

        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $m);

        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) { $payload = '{}'; }

            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw  = curl_exec($ch);
    $err  = (string)curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Kein curl_close() (PHP 8.5 deprecation-noise). Reset reicht hier.
    curl_reset($ch);

    $rawStr = is_string($raw) ? $raw : '';

    $json = null;
    if ($rawStr !== '') {
        $tmp = json_decode($rawStr, true);
        if (is_array($tmp)) { $json = $tmp; }
    }

    return [
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'raw'       => $rawStr,
        'json'      => $json,
        'curl_err'  => $err,
        'url'       => $url,
    ];
}

/* -------------------- Request -------------------- */

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$in = [];
if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $j   = (trim($raw) !== '') ? json_decode($raw, true) : null;
    if (is_array($j)) { $in = $j; }
} elseif ($method === 'GET') {
    if (!defined('CRM_DEBUG') || !CRM_DEBUG) {
        FN_Out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    $in = [
        'number' => (string)($_GET['number'] ?? ''),
        'caller' => (string)($_GET['caller'] ?? ''),
    ];
} else {
    FN_Out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$numberIn = (string)($in['number'] ?? $in['to'] ?? $in['callee'] ?? '');
$callerIn = (string)($in['caller'] ?? '');

$callee = FN_NormalizeE164($numberIn);
if ($callee === '' || strlen($callee) < 6) {
    FN_Out(['ok' => false, 'error' => 'invalid_number'], 400);
}

/* -------------------- Config -------------------- */

$cti = (array)CRM_CFG('cti', []);
$sip = (array)($cti['sipgate'] ?? []);

$debug = FN_IsDebugEnabled($sip);

FN_Log($debug, 'dial_request_start', [
    'method' => $method,
    'user'   => (CRM_Auth_User()['user'] ?? ''),
]);

FN_Log($debug, 'cti_cfg', ['cti' => $cti ?: null]);

if (!(bool)($sip['enabled'] ?? false)) {
    FN_Log($debug, 'dial_reject_cti_disabled');
    FN_Out(['ok' => false, 'error' => 'cti_disabled'], 400);
}

$apiBase   = (string)($sip['api_base'] ?? 'https://api.sipgate.com/v2');
$secretKey = trim((string)($sip['secret_key'] ?? 'sipgate_cti'));
if ($secretKey === '') { $secretKey = 'sipgate_cti'; }

$allowed = (array)($sip['allowed_devices'] ?? []);
$allowed = array_values(array_filter(array_map('strval', $allowed)));

$caller = trim((string)$callerIn);
if ($caller === '') { $caller = trim((string)($sip['default_device'] ?? '')); }

$useSipgateDefault = (bool)($sip['use_sipgate_default_device'] ?? true);

/* -------------------- Secrets -------------------- */
$tokenId     = (string)CRM_SECRET('cti.' . $secretKey . '.token_id', '');
$tokenSecret = (string)CRM_SECRET('cti.' . $secretKey . '.token_secret', '');

if ($tokenId === '' || $tokenSecret === '') {
    FN_Log($debug, 'dial_reject_missing_secrets', ['secret_key' => $secretKey]);
    FN_Out(['ok' => false, 'error' => 'missing_secrets', 'secret_key' => $secretKey], 500);
}


/* -------------------- Sipgate: /users (defaultDevice) -------------------- */

$users = FN_SipgateRequest($apiBase, $tokenId, $tokenSecret, 'GET', '/users', null);
if (!($users['ok'] ?? false) || !is_array($users['json'])) {
    FN_Log($debug, 'dial_fail_users', ['http' => $users['http_code'] ?? 0, 'err' => $users['curl_err'] ?? '']);
    FN_Out(['ok' => false, 'error' => 'sipgate_users_failed'], 502);
}

$defaultDevice = '';
$items = (array)($users['json']['items'] ?? []);
foreach ($items as $u) {
    if (!is_array($u)) continue;
    if (($u['defaultDevice'] ?? '') !== '') { $defaultDevice = (string)$u['defaultDevice']; break; }
}

if ($caller === '' && $useSipgateDefault && $defaultDevice !== '') { $caller = $defaultDevice; }

if ($caller === '') {
    FN_Log($debug, 'dial_reject_missing_caller', ['defaultDevice' => $defaultDevice]);
    FN_Out(['ok' => false, 'error' => 'missing_caller'], 400);
}

if (count($allowed) > 0 && !in_array($caller, $allowed, true)) {
    FN_Log($debug, 'dial_reject_caller_not_allowed', ['caller' => $caller, 'allowed' => $allowed]);
    FN_Out(['ok' => false, 'error' => 'caller_not_allowed', 'caller' => $caller], 403);
}

/* -------------------- Sipgate: Start call -------------------- */

$call = FN_SipgateRequest($apiBase, $tokenId, $tokenSecret, 'POST', '/sessions/calls', [
    'caller' => $caller,
    'callee' => $callee,
]);

if (!($call['ok'] ?? false) || !is_array($call['json'])) {
    FN_Log($debug, 'dial_fail_call', [
        'http'   => $call['http_code'] ?? 0,
        'caller' => $caller,
        'callee' => $callee,
        'raw'    => (string)($call['raw'] ?? ''),
    ]);
    FN_Out(['ok' => false, 'error' => 'sipgate_call_failed'], 502);
}

$sessionId = (string)($call['json']['sessionId'] ?? '');

FN_Log($debug, 'dial_ok', [
    'user'      => (CRM_Auth_User()['user'] ?? ''),
    'caller'    => $caller,
    'callee'    => $callee,
    'sessionId' => $sessionId,
]);

FN_Out([
    'ok'        => true,
    'provider'  => 'sipgate',
    'caller'    => $caller,
    'callee'    => $callee,
    'sessionId' => $sessionId,
]);
