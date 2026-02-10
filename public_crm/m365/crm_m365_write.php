<?php
declare(strict_types=1);
#ini_set('display_errors', '1');
#ini_set('display_startup_errors', '1');
#error_reporting(E_ALL);

/*
================================================================================
CRM – Microsoft 365 WRITE (Writer v2) – STATUS: PAUSIERT / STAND EINGEFROREN
Datei: /public/m365/crm_m365_write.php

Zweck / Rolle:
- Reiner Transport- und Service-Endpoint für Schreiboperationen nach Microsoft 365
- Führt ausschließlich Jobs aus, die von außen übergeben werden (Job-Payload)
- Trifft KEINE fachlichen Entscheidungen (kein Business-Logic-Layer)
- Der Writer ist bewusst „dumm“: was gesendet wird, entscheidet der Aufrufer

Abgrenzung:
- NICHT gekoppelt an M365 READ oder MIRROR
- Keine impliziten Abhängigkeiten zu CRM-Logik, Events, Kalender, Kontakte
- OAuth / Token-Handling ist rein technisch (Zugriff auf Graph)

Aktueller Implementierungsstand (Februar 2026):
- HTTP & CLI Endpoint funktionsfähig
- Token-Handling (client_credentials) implementiert
- Token-Cache wird mit Reader geteilt (/data/m365/token.json)
- Job-Dispatch vorhanden:
    - send_mail (Graph: /users/{upn}/sendMail)

Bekannter Status / offene Punkte:
- Graph API antwortet aktuell mit 403 (ErrorAccessDenied) bei sendMail
- Ursache ist NICHT Code, sondern M365 / Entra ID Konfiguration:
    - Fehlende oder eingeschränkte Application Permissions (Mail.Send)
    - oder aktive Exchange Application Access Policy
- Code ist technisch korrekt, Integration bewusst pausiert

WICHTIGES DESIGN-PRINZIP:
- Der Writer ist KEIN Workflow-Modul
- Er weiß nicht:
    - warum etwas gesendet wird
    - woher die Daten kommen
    - was fachlich korrekt ist
- Er weiß nur:
    - welchen Job-Typ er ausführen soll
    - mit welchem User / UPN
    - mit welchen Daten

Security:
- Optionaler Shared-Token (jobs_access_token) aus secrets_m365.php
- Übergabe per:
    - Header: X-CRM-Token
    - Authorization: Bearer
    - Query: ?token=

Reaktivierung / Weiterarbeit (für später):
1) Entra ID:
   - Microsoft Graph → Application Permission → Mail.Send
   - Admin-Consent erteilen
2) Exchange Online:
   - Prüfen, ob Application Access Policy aktiv ist
3) Danach:
   - send_mail erneut testen
   - ggf. weitere Job-Typen ergänzen (Calendar, Contacts, Tasks)

Fazit:
- Technisch sauberer Writer-Service
- Aktuell bewusst eingefroren
- Keine Umstrukturierung nötig, nur Konfigurations-Freigaben

================================================================================
*/

$MOD = 'm365';
define('LOG_CHANNEL', 'm365_write');

require_once __DIR__ . '/../_inc/bootstrap.php';

/* ---- Fallback Logging (wenn bootstrap noch keine zentralen Logger hat) ---- */
if (!function_exists('CRM_LogInfo'))  { function CRM_LogInfo(string $msg, array $ctx = []): void { ; } }
if (!function_exists('CRM_LogError')) { function CRM_LogError(string $msg, array $ctx = []): void { ; } }
if (!function_exists('CRM_LogDebug')) { function CRM_LogDebug(string $msg, array $ctx = []): void { ; } }

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $expected = FN_M365W_SecretsGet('jobs_access_token', '');
    $given    = FN_M365W_GetTokenFromRequest();

    if ($expected !== '') {
        if ($given === '' || !hash_equals($expected, $given)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            exit;
        }
    } else {
        // Wenn kein Token gesetzt ist: bewusst offen (wie bei anderen Modulen üblich),
        // aber sauber loggen, damit man es nicht "vergisst".
        CRM_LogError('jobs_access_token_missing_in_secrets', []);
    }
}

$startTs = microtime(true);

try {
    $cfg = FN_M365W_LoadSettings();

    if ($isCli) {
        $res = FN_M365W_HandleCli($cfg);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    $req = FN_M365W_ReadJsonRequest();
    $job = is_array($req['job'] ?? null) ? (array)$req['job'] : [];

    if ($job === []) {
        $ms = (int)max(1, ceil((microtime(true) - $startTs) * 1000));
        echo json_encode([
            'ok' => true,
            'noop' => true,
            'duration_ms' => $ms,
            'hint' => 'POST JSON: {"job": {...}}'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    $out = FN_M365W_RunJob($cfg, $job);

    $ms = (int)max(1, ceil((microtime(true) - $startTs) * 1000));
    $out['duration_ms'] = $ms;

    if (empty($out['ok'])) {
    // Fachliche Fehler = 400, technische = 500
    $err = (string)($out['error'] ?? '');
    if (str_starts_with($err, 'user_') || str_starts_with($err, 'missing_')) {
            http_response_code(400);
        } else {
            http_response_code(500);
        }
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;

} catch (Throwable $e) {

    $ms = (int)max(1, ceil((microtime(true) - $startTs) * 1000));

    CRM_LogError('unhandled_exception', [
        'msg'  => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    if (!$isCli) { http_response_code(500); }

    echo json_encode([
        'ok' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'duration_ms' => $ms
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

###########################################################################################################################################
/*
 * 0001 - FN_M365W_LoadSettings
 * Lädt /config/m365/settings_m365.php und gibt den Block ['m365'] zurück.
 */
function FN_M365W_LoadSettings(): array
{
    $file = dirname(__DIR__, 2) . '/config/m365/settings_m365.php';
    if (!is_file($file)) {
        CRM_LogError('settings_missing', ['file' => $file]);
        return [];
    }

    $cfg = (array)require $file;
    return (array)($cfg['m365'] ?? []);
}

###########################################################################################################################################
/*
 * 0002 - FN_M365W_LoadSecrets
 * Lädt /config/m365/secrets_m365.php und gibt den Block ['m365'] zurück (flach).
 */
function FN_M365W_LoadSecrets(): array
{
    $file = dirname(__DIR__, 2) . '/config/m365/secrets_m365.php';
    if (!is_file($file)) {
        CRM_LogError('secrets_missing', ['file' => $file]);
        return [];
    }

    $sec = (array)require $file;
    return (array)($sec['m365'] ?? []);
}

###########################################################################################################################################
/*
 * 0003 - FN_M365W_SecretsGet
 * Liest einen flachen Key aus secrets['m365'].
 */
function FN_M365W_SecretsGet(string $key, mixed $default = null): mixed
{
    static $sec = null;
    if ($sec === null) { $sec = FN_M365W_LoadSecrets(); }

    if (is_array($sec) && array_key_exists($key, $sec)) { return $sec[$key]; }
    return $default;
}

###########################################################################################################################################
/*
 * 0004 - FN_M365W_DataPath
 * Baut absolute Pfade unter /data/<rel>.
 */
function FN_M365W_DataPath(string $rel): string
{
    $root = dirname(__DIR__, 2);
    $rel  = ltrim($rel, '/');
    return $root . '/data/' . $rel;
}

###########################################################################################################################################
/*
 * 0005 - FN_M365W_GetTokenFromRequest
 * Token aus Header/Bearer/Query.
 */
function FN_M365W_GetTokenFromRequest(): string
{
    $t = (string)($_SERVER['HTTP_X_CRM_TOKEN'] ?? '');
    if (trim($t) !== '') { return trim($t); }

    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $hdr = trim($hdr);
    if (stripos($hdr, 'Bearer ') === 0) {
        $b = trim(substr($hdr, 7));
        if ($b !== '') { return $b; }
    }

    $t = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
    return trim($t);
}

###########################################################################################################################################
/*
 * 0006 - FN_M365W_ReadJsonRequest
 * Liest JSON Body (HTTP).
 */
function FN_M365W_ReadJsonRequest(): array
{
    $raw = (string)file_get_contents('php://input');
    $raw = trim($raw);

    if ($raw === '') { return []; }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

###########################################################################################################################################
/*
 * 0007 - FN_M365W_TokenCacheFile
 * Token Cache: /data/m365/token.json (shared mit Reader).
 */
function FN_M365W_TokenCacheFile(array $cfg): string
{
    $dir  = (string)(($cfg['state']['dir'] ?? 'm365'));
    $file = (string)(($cfg['state']['files']['token'] ?? 'token.json'));
    return FN_M365W_DataPath(rtrim($dir, '/') . '/' . ltrim($file, '/'));
}

###########################################################################################################################################
/*
 * 0008 - FN_M365W_GetAccessToken
 * Holt/cached AccessToken (client_credentials) via token.json.
 */
function FN_M365W_GetAccessToken(array $cfg, bool $forceRefresh): string|false
{
    $tenantId     = trim((string)FN_M365W_SecretsGet('tenant_id', ''));
    $clientId     = trim((string)FN_M365W_SecretsGet('client_id', ''));
    $clientSecret = trim((string)FN_M365W_SecretsGet('client_secret', ''));

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        CRM_LogError('secrets_missing_required', []);
        return false;
    }

    $cacheFile = FN_M365W_TokenCacheFile($cfg);
    $skew      = 120;

    if (!$forceRefresh) {
        $cached = FN_M365W_ReadJsonFile($cacheFile);
        if (is_array($cached)) {
            $token     = trim((string)($cached['oauth_access_token'] ?? ''));
            $expiresAt = (int)($cached['oauth_expires_at'] ?? 0);

            if ($token !== '' && $expiresAt > 0 && (($expiresAt - $skew) > time())) {
                CRM_LogDebug('token_cache_hit', []);
                return $token;
            }
        }
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

    $postData = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'grant_type'    => 'client_credentials',
        'scope'         => 'https://graph.microsoft.com/.default'
    ]);

    CRM_LogDebug('token_request', ['url' => $tokenUrl]);

    $resp = FN_M365W_HttpRequest(
        'POST',
        $tokenUrl,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        $postData,
        20
    );

    if (($resp['ok'] ?? false) !== true) {
        CRM_LogError('token_http_failed', ['status' => (int)($resp['status'] ?? 0), 'error' => (string)($resp['error'] ?? '')]);
        return false;
    }

    $json = json_decode((string)($resp['body'] ?? ''), true);
    if (!is_array($json) || empty($json['access_token'])) {
        CRM_LogError('token_invalid_response', []);
        return false;
    }

    $accessToken = (string)$json['access_token'];
    $expiresIn   = (int)($json['expires_in'] ?? 3600);
    $expiresAt   = time() + max(60, $expiresIn);

    $cachePayload = [
        'oauth_access_token' => $accessToken,
        'oauth_expires_at'   => $expiresAt,
        'oauth_expires_in'   => $expiresIn,
        'cached_at'          => date('c'),
    ];

    FN_M365W_WriteJsonFileAtomic($cacheFile, $cachePayload, true);

    return $accessToken;
}

###########################################################################################################################################
/*
 * 0009 - FN_M365W_GraphBase
 * Graph Base URL (optional in secrets graph_base_url, fallback v1.0).
 */
function FN_M365W_GraphBase(): string
{
    $base = trim((string)FN_M365W_SecretsGet('graph_base_url', ''));
    if ($base !== '') { return rtrim($base, '/'); }

    return 'https://graph.microsoft.com/v1.0';
}

###########################################################################################################################################
/*
 * 0010 - FN_M365W_UserGetUpn
 * Resolves userKey -> UPN via settings_m365 users[].
 */
function FN_M365W_UserGetUpn(array $cfg, string $userKey): string
{
    $users = (array)($cfg['users'] ?? []);
    $u     = (array)($users[$userKey] ?? []);
    return trim((string)($u['upn'] ?? ''));
}

###########################################################################################################################################
/*
 * 0011 - FN_M365W_UserAllows
 * Prüft userCfg read/write pro area.
 */
function FN_M365W_UserAllows(array $userCfg, string $dir, string $area): bool
{
    $block = (array)($userCfg[$dir] ?? []);
    return (bool)($block[$area] ?? false);
}

###########################################################################################################################################
/*
 * 0012 - FN_M365W_RunJob
 * Dispatch für Job-Typen (aktuell: send_mail).
 */
function FN_M365W_RunJob(array $cfg, array $job): array
{
    $type = strtolower(trim((string)($job['type'] ?? '')));
    if ($type === '') {
        return ['ok' => false, 'error' => 'missing_job_type'];
    }

    $token = FN_M365W_GetAccessToken($cfg, false);
    if ($token === false) {
        return ['ok' => false, 'error' => 'token_failed'];
    }

    if ($type === 'send_mail') {
        $res = FN_M365W_JobSendMail($cfg, $token, $job);

        // 401 => einmal refresh + retry
        if (!empty($res['auth'])) {
            $token2 = FN_M365W_GetAccessToken($cfg, true);
            if ($token2 !== false) {
                $res = FN_M365W_JobSendMail($cfg, $token2, $job);
            }
        }

        return $res;
    }

    return ['ok' => false, 'error' => 'unsupported_job_type', 'type' => $type];
}


###########################################################################################################################################
/*
 * 0013 - FN_M365W_JobSendMail
 * Sendet Mail via Graph: POST /users/{upn}/sendMail
 * Hinweis: App-only benötigt Graph Application Permission Mail.Send + ggf. Exchange Application Access Policy.
 */
function FN_M365W_JobSendMail(array $cfg, string $token, array $job): array
{
    $userKey  = trim((string)($job['user'] ?? ''));
    $to       = (array)($job['to'] ?? []);
    $subject  = (string)($job['subject'] ?? '');
    $bodyText = (string)($job['body_text'] ?? '');
    $bodyHtml = (string)($job['body_html'] ?? '');

    if ($userKey === '') { return ['ok' => false, 'error' => 'missing_user']; }

    $upn = FN_M365W_UserGetUpn($cfg, $userKey);
    if ($upn === '') { return ['ok' => false, 'error' => 'unknown_user', 'user' => $userKey]; }

    $users = (array)($cfg['users'] ?? []);
    $uCfg  = (array)($users[$userKey] ?? []);
    if (!FN_M365W_UserAllows($uCfg, 'write', 'mail')) {
        return ['ok' => false, 'error' => 'user_not_allowed_write_mail', 'user' => $userKey];
    }

    $toList = [];
    foreach ($to as $addr) {
        $addr = trim((string)$addr);
        if ($addr === '') { continue; }
        $toList[] = ['emailAddress' => ['address' => $addr]];
    }
    if (count($toList) === 0) { return ['ok' => false, 'error' => 'missing_to']; }

    $contentType = 'Text';
    $content     = trim($bodyText) !== '' ? $bodyText : '(no body)';
    if (trim($bodyHtml) !== '') { $contentType = 'HTML'; $content = $bodyHtml; }

    $payload = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => $contentType,
                'content'     => $content,
            ],
            'toRecipients' => $toList,
        ],
        'saveToSentItems' => true
    ];

    $base = FN_M365W_GraphBase();
    $url  = $base . '/users/' . rawurlencode($upn) . '/sendMail';

    $resp = FN_M365W_HttpRequest(
        'POST',
        $url,
        [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        30
    );

    if ((int)($resp['status'] ?? 0) === 401) {
        return ['ok' => false, 'auth' => true, 'error' => 'unauthorized_401'];
    }

    if (!($resp['ok'] ?? false)) {

        $status = (int)($resp['status'] ?? 0);
        $body   = (string)($resp['body'] ?? '');

        $errCode = '';
        $errMsg  = '';
        $errObj  = null;

        $j = json_decode($body, true);
        if (is_array($j) && isset($j['error']) && is_array($j['error'])) {
            $errObj  = $j['error'];
            $errCode = (string)($j['error']['code'] ?? '');
            $errMsg  = (string)($j['error']['message'] ?? '');
        }

        $hint = '';
        if ($status === 403 && strtolower($errCode) === 'erroraccessdenied') {
            $hint = 'Graph AccessDenied: Prüfe App-Permission Mail.Send (Application) + Admin-Consent und ggf. Exchange Application Access Policy für die Mailbox.';
        }

        return [
            'ok'      => false,
            'error'   => 'graph_http_failed',
            'status'  => $status,
            'graph'   => [
                'code'    => $errCode,
                'message' => $errMsg,
            ],
            'hint'    => $hint,
            'body'    => $body,
            'user'    => $userKey,
            'upn'     => $upn,
            'url'     => $url,
        ];
    }

    return [
        'ok'     => true,
        'status' => (int)($resp['status'] ?? 0),
        'user'   => $userKey,
        'upn'    => $upn,
    ];
}


###########################################################################################################################################
/*
 * 0014 - FN_M365W_HandleCli
 * CLI Helper: send_mail
 */
function FN_M365W_HandleCli(array $cfg): array
{
    global $argv;

    $cmd = strtolower(trim((string)($argv[1] ?? '')));
    if ($cmd === '') {
        return ['ok' => true, 'hint' => 'php crm_m365_write.php send_mail <userKey> <to> <subject> <body>'];
    }

    if ($cmd === 'send_mail') {
        $user    = (string)($argv[2] ?? '');
        $to      = (string)($argv[3] ?? '');
        $subject = (string)($argv[4] ?? 'Test');
        $body    = (string)($argv[5] ?? 'Hello');

        $job = [
            'type' => 'send_mail',
            'user' => $user,
            'to' => [$to],
            'subject' => $subject,
            'body_text' => $body
        ];

        $token = FN_M365W_GetAccessToken($cfg, false);
        if ($token === false) {
            return ['ok' => false, 'error' => 'token_failed'];
        }

        return FN_M365W_JobSendMail($cfg, $token, $job);
    }

    return ['ok' => false, 'error' => 'unsupported_cmd', 'cmd' => $cmd];
}

###########################################################################################################################################
/*
 * 0015 - FN_M365W_HttpRequest
 */
function FN_M365W_HttpRequest(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeoutSec));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = (string)curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err];
    }

    $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    $respBody = substr((string)$raw, $headerSize);

    $ok = ($status >= 200 && $status < 300);

    return [
        'ok'     => $ok,
        'status' => $status,
        'body'   => $respBody,
        'error'  => $ok ? '' : ('HTTP ' . $status)
    ];
}

###########################################################################################################################################
/*
 * 0016 - FN_M365W_ReadJsonFile
 */
function FN_M365W_ReadJsonFile(string $file): array|false
{
    if ($file === '' || !is_file($file)) { return false; }

    $raw = @file_get_contents($file);
    if ($raw === false) { return false; }

    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : false;
}

###########################################################################################################################################
/*
 * 0017 - FN_M365W_WriteJsonFileAtomic
 */
function FN_M365W_WriteJsonFileAtomic(string $file, array $payload, bool $pretty): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $tmp = $file . '.tmp';

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($pretty) { $flags |= JSON_PRETTY_PRINT; }

    $json = json_encode($payload, $flags);
    if ($json === false) { return false; }

    if (@file_put_contents($tmp, $json . "\n") === false) { return false; }
    return @rename($tmp, $file);
}
