<?php
declare(strict_types=1);

/*
================================================================================
CRM – M365 READ (RAW + Core Files)
Datei: /public_crm/m365/crm_m365_read.php

Zielbild:
- Holt Daten aus Microsoft Graph (App-only via client_credentials)
- Schreibt RAW-Dumps + Status/LastError unter /data/m365/*
- Zusätzlich: schreibt Core-Files (/data/contacts.json, /data/calendar.json) nur bei Änderung (Hash)

Trigger:
- Cron ruft minütlich auf (HTTP oder CLI)
- Reader entscheidet pro Bereich (contacts/calendar/mail) anhand interval_sec, user-permissions

Security (HTTP):
- Token in /config/m365/secrets_m365.php:
  ['m365']['jobs_access_token']  (Query: ?token=...)

API-/Include-Verhalten:
- Wenn define('CRM_IS_API', true) gesetzt ist (via api_m365_read.php), erzeugt der Reader KEINE eigene HTTP-Ausgabe
  und gibt nur 1|0 zurück (für Wrapper JSON).

Force:
- HTTP: ?force=1
- CLI:  php crm_m365_read.php all --force

Logging:
- Zentral via bootstrap (CRM_LogInfo/CRM_LogError/CRM_LogDebug)
- Channel: LOG_CHANNEL = 'm365_read'
================================================================================
*/

$MOD = 'm365';
define('LOG_CHANNEL', 'm365_read');

require_once __DIR__ . '/../_inc/bootstrap.php';

/* Fallback-Logger, falls bootstrap (noch) keine CRM_Log* liefert */
if (!function_exists('CRM_LogInfo')) {
    function CRM_LogInfo(string $msg, array $ctx = []): void { ; }
}
if (!function_exists('CRM_LogError')) {
    function CRM_LogError(string $msg, array $ctx = []): void { ; }
}
if (!function_exists('CRM_LogDebug')) {
    function CRM_LogDebug(string $msg, array $ctx = []): void { ; }
}

$isCli  = (PHP_SAPI === 'cli');
$isApi  = (defined('CRM_IS_API') && CRM_IS_API === true);
$force  = FN_M365_GetForceFlag();

/* ---------------- HTTP Guard (nur wenn direkte HTTP-Ausführung; nicht im API-Wrapper) ---------------- */
if (!$isCli && !$isApi) {
    ini_set('display_errors', '0');

    $expected = (string)FN_M365_SecretsGet('jobs_access_token', '');
    $given    = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));

    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'forbidden';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

$startTs = microtime(true);

$cfg  = FN_M365_LoadSettings();
$mode = FN_M365_GetRunMode(); // contacts|calendar|mail|all

CRM_LogInfo('start', ['mode' => $mode, 'force' => $force, 'isApi' => $isApi]);

$run = [
    'contacts' => ['ok' => true, 'ran' => false, 'changed' => false, 'users' => []],
    'calendar' => ['ok' => true, 'ran' => false, 'changed' => false, 'users' => []],
    'mail'     => ['ok' => true, 'ran' => false, 'changed' => false, 'users' => []],
];

try {

    $users = (array)($cfg['users'] ?? []);
    foreach ($users as $userKey => $u) {

        $userKey = (string)$userKey;
        if ($userKey === '') { continue; }

        $upn = (string)($u['upn'] ?? '');
        if ($upn === '') { continue; }

        // contacts
        if (($mode === 'contacts' || $mode === 'all') && FN_M365_UserAllows($u, 'read', 'contacts')) {
            if ($force || FN_M365_ShouldRunArea($cfg, 'contacts', $userKey)) {
                $run['contacts']['ran'] = true;
                $res = FN_M365_SyncContactsRaw($cfg, $userKey, $upn);
                $ok = (bool)($res['ok'] ?? false);
                $ch = (bool)($res['changed'] ?? false);
                $run['contacts']['ok'] = ($run['contacts']['ok'] && $ok);
                $run['contacts']['changed'] = ($run['contacts']['changed'] || $ch);
                $run['contacts']['users'][$userKey] = $ok ? 'ok' : 'fail';
                FN_M365_MarkAreaRan($cfg, 'contacts', $userKey, $ok);
            }
        }

        // calendar
        if (($mode === 'calendar' || $mode === 'all') && FN_M365_UserAllows($u, 'read', 'calendar')) {
            if ($force || FN_M365_ShouldRunArea($cfg, 'calendar', $userKey)) {
                $run['calendar']['ran'] = true;
                $res = FN_M365_SyncCalendarRaw($cfg, $userKey, $upn);
                $ok = (bool)($res['ok'] ?? false);
                $ch = (bool)($res['changed'] ?? false);
                $run['calendar']['ok'] = ($run['calendar']['ok'] && $ok);
                $run['calendar']['changed'] = ($run['calendar']['changed'] || $ch);
                $run['calendar']['users'][$userKey] = $ok ? 'ok' : 'fail';
                FN_M365_MarkAreaRan($cfg, 'calendar', $userKey, $ok);
            }
        }

        // mail (RAW only) – optional / später ausbauen
        if (($mode === 'mail' || $mode === 'all') && FN_M365_UserAllows($u, 'read', 'mail')) {
            if ($force || FN_M365_ShouldRunArea($cfg, 'mail', $userKey)) {
                $run['mail']['ran'] = true;
                $ok = FN_M365_SyncMailRaw($cfg, $userKey, $upn);
                $run['mail']['ok'] = ($run['mail']['ok'] && $ok);
                $run['mail']['users'][$userKey] = $ok ? 'ok' : 'fail';
                FN_M365_MarkAreaRan($cfg, 'mail', $userKey, $ok);
            }
        }
    }

} catch (Throwable $e) {

    CRM_LogError('unhandled_exception', [
        'msg'  => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    $run['contacts']['ok'] = false;
    $run['calendar']['ok'] = false;
    $run['mail']['ok']     = false;
}

$allOk = ($run['contacts']['ok'] && $run['calendar']['ok'] && $run['mail']['ok']);

FN_M365_WriteStatusReadJson($mode, $run, $startTs, $cfg);

if (!$allOk) {
    FN_M365_WriteLastErrorJson(
        'read',
        'mode=' . $mode,
        'M365 READ fehlgeschlagen (Details siehe Log/Status).',
        500
    );
}

CRM_LogInfo('end', [
    'ok'    => (bool)$allOk,
    'mode'  => $mode,
    'force' => $force,
]);

/* Output:
 * - API-Wrapper: nur return 1|0
 * - Direkte HTTP: JSON + exit
 * - CLI: return 1|0 (kein echo)
 */
if (!$isCli && !$isApi) {
    if (!$allOk) { http_response_code(500); }

    echo json_encode(
        [
            'ok'    => (bool)$allOk,
            'mode'  => $mode,
            'force' => $force,
            'run'   => $run,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n";
    exit;
}

return $allOk ? 1 : 0;

###########################################################################################################################################
/*
 * 0001 - FN_M365_LoadSettings
 * Lädt Modul-Settings (/config/m365/settings_m365.php) und gibt den Block ['m365'] zurück.
 */
function FN_M365_LoadSettings(): array
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
 * 0002 - FN_M365_LoadSecrets
 * Lädt Modul-Secrets (/config/m365/secrets_m365.php) und gibt den Block ['m365'] zurück.
 */
function FN_M365_LoadSecrets(): array
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
 * 0003 - FN_M365_SecretsGet
 * Liest einen Key aus den Secrets (top-level in ['m365']).
 */
function FN_M365_SecretsGet(string $key, $default = '')
{
    static $sec = null;
    if ($sec === null) { $sec = FN_M365_LoadSecrets(); }

    if (array_key_exists($key, $sec)) { return $sec[$key]; }
    return $default;
}

###########################################################################################################################################
/*
 * 0004 - FN_M365_DataPath
 * Baut absolute Pfade unterhalb /data anhand eines relativen Teilpfads (z.B. 'm365/raw/x.json').
 */
function FN_M365_DataPath(string $rel): string
{
    $root = dirname(__DIR__, 2);
    $rel  = ltrim($rel, '/');
    return $root . '/data/' . $rel;
}

###########################################################################################################################################
/*
 * 0005 - FN_M365_GetRunMode
 * Ermittelt den Run-Mode (contacts|calendar|mail|all) aus HTTP oder CLI.
 */
function FN_M365_GetRunMode(): string
{
    $mode = 'all';

    if (PHP_SAPI === 'cli') {
        global $argv;
        $mode = (string)($argv[1] ?? 'all');
    } else {
        $mode = (string)($_GET['mode'] ?? ($_POST['mode'] ?? 'all'));
    }

    $mode = strtolower(trim($mode));

    if (!in_array($mode, ['contacts', 'calendar', 'mail', 'all'], true)) { $mode = 'all'; }
    return $mode;
}

###########################################################################################################################################
/*
 * 0006 - FN_M365_GetForceFlag
 * Ermittelt Force-Flag (HTTP: ?force=1, CLI: --force).
 */
function FN_M365_GetForceFlag(): bool
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ((array)$argv as $a) {
            if ((string)$a === '--force') { return true; }
        }
        return false;
    }

    $v = (string)($_GET['force'] ?? ($_POST['force'] ?? ''));
    $v = strtolower(trim($v));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

###########################################################################################################################################
/*
 * 0007 - FN_M365_UserAllows
 * Prüft User-Berechtigung read/write pro Bereich.
 */
function FN_M365_UserAllows(array $userCfg, string $dir, string $area): bool
{
    $block = (array)($userCfg[$dir] ?? []);
    return (bool)($block[$area] ?? false);
}

###########################################################################################################################################
/*
 * 0008 - FN_M365_StatusReadFile
 * Liefert absoluten Pfad zur status_read.json aus Settings.
 */
function FN_M365_StatusReadFile(array $cfg): string
{
    $dir  = (string)(($cfg['state']['dir'] ?? 'm365'));
    $file = (string)(($cfg['state']['files']['status_read'] ?? 'status_read.json'));
    return FN_M365_DataPath(rtrim($dir, '/') . '/' . ltrim($file, '/'));
}

###########################################################################################################################################
/*
 * 0009 - FN_M365_ShouldRunArea
 * Interval-Guard pro Bereich und UserKey.
 */
function FN_M365_ShouldRunArea(array $cfg, string $area, string $userKey): bool
{
    $interval = (int)(($cfg['read'][$area]['interval_sec'] ?? 300));
    if ($interval < 1) { $interval = 300; }

    $statusFile = FN_M365_StatusReadFile($cfg);
    $status     = FN_M365_ReadJsonFile($statusFile);
    if (!is_array($status)) { $status = []; }

    $last = (int)($status['areas'][$area]['users'][$userKey]['last_run_ts'] ?? 0);

    if ($last <= 0) { return true; }
    return ((time() - $last) >= $interval);
}

###########################################################################################################################################
/*
 * 0010 - FN_M365_MarkAreaRan
 * Schreibt last_run_ts (+ last_ok_ts) pro Bereich/User in status_read.json.
 */
function FN_M365_MarkAreaRan(array $cfg, string $area, string $userKey, bool $ok): void
{
    $statusFile = FN_M365_StatusReadFile($cfg);
    $status     = FN_M365_ReadJsonFile($statusFile);
    if (!is_array($status)) { $status = []; }

    if (!isset($status['areas'])) { $status['areas'] = []; }
    if (!isset($status['areas'][$area])) { $status['areas'][$area] = []; }
    if (!isset($status['areas'][$area]['users'])) { $status['areas'][$area]['users'] = []; }
    if (!isset($status['areas'][$area]['users'][$userKey])) { $status['areas'][$area]['users'][$userKey] = []; }

    $status['areas'][$area]['users'][$userKey]['last_run_ts'] = time();
    $status['areas'][$area]['users'][$userKey]['last_run']    = date('c');

    if ($ok) {
        $status['areas'][$area]['users'][$userKey]['last_ok_ts'] = time();
        $status['areas'][$area]['users'][$userKey]['last_ok']    = date('c');
    } else {
        $status['areas'][$area]['users'][$userKey]['last_err_ts'] = time();
        $status['areas'][$area]['users'][$userKey]['last_err']    = date('c');
    }

    FN_M365_WriteJsonFileAtomic($statusFile, $status, true);
}

###########################################################################################################################################
/*
 * 0011 - FN_M365_TokenCacheFile
 * Liefert absoluten Pfad zur token.json aus Settings.
 */
function FN_M365_TokenCacheFile(array $cfg): string
{
    $dir  = (string)(($cfg['state']['dir'] ?? 'm365'));
    $file = (string)(($cfg['state']['files']['token'] ?? 'token.json'));
    return FN_M365_DataPath(rtrim($dir, '/') . '/' . ltrim($file, '/'));
}

###########################################################################################################################################
/*
 * 0012 - FN_M365_GetAccessToken
 * Holt Graph Access Token (client_credentials) und cached in token.json:
 * - oauth_access_token
 * - oauth_expires_at
 */
function FN_M365_GetAccessToken(array $cfg): string|false
{
    $tenantId     = (string)FN_M365_SecretsGet('tenant_id', '');
    $clientId     = (string)FN_M365_SecretsGet('client_id', '');
    $clientSecret = (string)FN_M365_SecretsGet('client_secret', '');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        CRM_LogError('secrets_missing_required');
        return false;
    }

    $cacheFile = FN_M365_TokenCacheFile($cfg);
    $skew      = 120;

    $cached = FN_M365_ReadJsonFile($cacheFile);
    if (is_array($cached)) {
        $token     = (string)($cached['oauth_access_token'] ?? '');
        $expiresAt = (int)($cached['oauth_expires_at'] ?? 0);

        if ($token !== '' && $expiresAt > 0 && (($expiresAt - $skew) > time())) {
            CRM_LogDebug('token_cache_hit');
            return $token;
        }
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

    $postData = http_build_query(
        [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default'
        ]
    );

    $timeout = 20;

    $resp = FN_M365_HttpRequest(
        'POST',
        $tokenUrl,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        $postData,
        $timeout
    );

    if (($resp['ok'] ?? false) !== true) {
        CRM_LogError('token_http_failed', ['status' => (int)($resp['status'] ?? 0), 'error' => (string)($resp['error'] ?? '')]);
        return false;
    }

    $json = json_decode((string)($resp['body'] ?? ''), true);
    if (!is_array($json) || empty($json['access_token'])) {
        CRM_LogError('token_invalid_response');
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

    FN_M365_WriteJsonFileAtomic($cacheFile, $cachePayload, true);

    return $accessToken;
}

###########################################################################################################################################
/*
 * 0013 - FN_M365_GraphBase
 * Liefert Graph Base URL.
 */
function FN_M365_GraphBase(): string
{
    return 'https://graph.microsoft.com/v1.0';
}

###########################################################################################################################################
/*
 * 0014 - FN_M365_BuildRawFile
 * Baut RAW-Dateiname pro Bereich/UserKey anhand Settings (base filename + _<userKey>).
 */
function FN_M365_BuildRawFile(array $cfg, string $area, string $userKey): string
{
    $dir = (string)(($cfg['raw_store']['dir'] ?? 'm365/raw'));
    $fn  = (string)(($cfg['raw_store']['files'][$area] ?? ($area . '_raw.json')));

    $pi = pathinfo($fn);
    $name = (string)($pi['filename'] ?? $fn);
    $ext  = (string)($pi['extension'] ?? 'json');

    $fnUser = $name . '_' . $userKey . '.' . $ext;

    return FN_M365_DataPath(rtrim($dir, '/') . '/' . $fnUser);
}

###########################################################################################################################################
/*
 * 0015 - FN_M365_SyncContactsRaw
 * Holt Kontakte für UPN und schreibt RAW Wrapper.
 * Zusätzlich: baut Core-Items und schreibt /data/contacts.json nur bei Änderung.
 *
 * Return: ['ok'=>bool,'changed'=>bool]
 */
function FN_M365_SyncContactsRaw(array $cfg, string $userKey, string $upn): array
{
    CRM_LogInfo('contacts_sync_start', ['user' => $userKey, 'upn' => $upn]);

    $all = FN_M365_GetAllContacts($cfg, $upn);
    if ($all === false) {
        CRM_LogError('contacts_sync_failed', ['user' => $userKey]);
        return ['ok' => false, 'changed' => false];
    }

    $rawFile = FN_M365_BuildRawFile($cfg, 'contacts', $userKey);

    $rawWrapper = [
        'version'  => 1,
        'user'     => $userKey,
        'upn'      => $upn,
        'lastSync' => date('c'),
        'count'    => count($all),
        'value'    => $all
    ];

    FN_M365_WriteJsonFileAtomic($rawFile, $rawWrapper, true);

    $items      = FN_M365_NormalizeContactsToCoreItems($all);
    $targetFile = FN_M365_DataPath('contacts.json');

    $changed = FN_M365_WriteCoreJsonIfChanged($items, $targetFile, 'contacts');

    return ['ok' => true, 'changed' => $changed];
}

###########################################################################################################################################
/*
 * 0016 - FN_M365_GetAllContacts
 * Liest alle Kontakte via /users/{upn}/contacts mit Paging.
 */
function FN_M365_GetAllContacts(array $cfg, string $upn)
{
    $token = FN_M365_GetAccessToken($cfg);
    if ($token === false) { return false; }

    $graphBase = FN_M365_GraphBase();

    $select  = 'id,displayName,givenName,surname,companyName,emailAddresses,businessPhones,homePhones,mobilePhone';
    $baseUrl = $graphBase . '/users/' . rawurlencode($upn) . '/contacts';
    $nextUrl = $baseUrl . '?$top=50&$select=' . rawurlencode($select);

    $contacts = [];

    while ($nextUrl !== null) {
        $json = FN_M365_RequestGraph($nextUrl, $token, $cfg, null);
        if ($json === false) { return false; }

        if (isset($json['value']) && is_array($json['value'])) {
            $contacts = array_merge($contacts, $json['value']);
        }

        if (isset($json['@odata.nextLink'])) { $nextUrl = (string)$json['@odata.nextLink']; }
        else { $nextUrl = null; }
    }

    return $contacts;
}

###########################################################################################################################################
/*
 * 0017 - FN_M365_SyncCalendarRaw
 * Holt Calendar Events Range und schreibt RAW Wrapper.
 * Zusätzlich: baut Core-Items (stabil, ohne volatile Felder) und schreibt /data/calendar.json nur bei Änderung.
 *
 * Return: ['ok'=>bool,'changed'=>bool]
 */
function FN_M365_SyncCalendarRaw(array $cfg, string $userKey, string $upn): array
{
    CRM_LogInfo('calendar_sync_start', ['user' => $userKey, 'upn' => $upn]);

    $rangeDays = (int)(($cfg['read']['calendar']['range_days'] ?? 30));
    if ($rangeDays < 1) { $rangeDays = 30; }

    $from = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $to   = $from->modify('+' . $rangeDays . ' days');

    $all = FN_M365_GetEventsRange($cfg, $upn, $from, $to);
    if ($all === false) {
        CRM_LogError('calendar_sync_failed', ['user' => $userKey]);
        return ['ok' => false, 'changed' => false];
    }

    $rawFile = FN_M365_BuildRawFile($cfg, 'calendar', $userKey);

    $rawWrapper = [
        'version'  => 1,
        'user'     => $userKey,
        'upn'      => $upn,
        'from'     => $from->format(DateTimeInterface::ATOM),
        'to'       => $to->format(DateTimeInterface::ATOM),
        'lastSync' => date('c'),
        'count'    => count($all),
        'value'    => $all
    ];

    FN_M365_WriteJsonFileAtomic($rawFile, $rawWrapper, true);

    $items      = FN_M365_NormalizeCalendarToCoreItems($all, $userKey, $upn);
    $targetFile = FN_M365_DataPath('calendar.json');

    $changed = FN_M365_WriteCoreJsonIfChanged($items, $targetFile, 'calendar');

    return ['ok' => true, 'changed' => $changed];
}

###########################################################################################################################################
/*
 * 0018 - FN_M365_GetEventsRange
 * Liest Kalender-Events via /users/{upn}/calendarView?startDateTime&endDateTime mit Paging.
 */
function FN_M365_GetEventsRange(array $cfg, string $upn, DateTimeImmutable $from, DateTimeImmutable $to)
{
    $token = FN_M365_GetAccessToken($cfg);
    if ($token === false) { return false; }

    $graphBase = FN_M365_GraphBase();

    $select  = 'id,subject,organizer,start,end,location,attendees,categories,isAllDay,isCancelled,showAs,importance,webLink';
    $baseUrl = $graphBase . '/users/' . rawurlencode($upn) . '/calendarView';

    $qs = http_build_query(
        [
            'startDateTime' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'endDateTime'   => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            '$top'          => 50,
            '$select'       => $select,
            '$orderby'      => 'start/dateTime'
        ]
    );

    $nextUrl = $baseUrl . '?' . $qs;
    $events  = [];

    while ($nextUrl !== null) {
        $json = FN_M365_RequestGraph($nextUrl, $token, $cfg, null);
        if ($json === false) { return false; }

        if (isset($json['value']) && is_array($json['value'])) {
            $events = array_merge($events, $json['value']);
        }

        if (isset($json['@odata.nextLink'])) { $nextUrl = (string)$json['@odata.nextLink']; }
        else { $nextUrl = null; }
    }

    return $events;
}

###########################################################################################################################################
/*
 * 0019 - FN_M365_SyncMailRaw
 * Platzhalter: Mail RAW (aktuell minimal: disabled by default, schreibt leeres Ergebnis)
 */
function FN_M365_SyncMailRaw(array $cfg, string $userKey, string $upn): bool
{
    $rawFile = FN_M365_BuildRawFile($cfg, 'mail', $userKey);

    $rawWrapper = [
        'version'  => 1,
        'user'     => $userKey,
        'upn'      => $upn,
        'lastSync' => date('c'),
        'count'    => 0,
        'value'    => []
    ];

    FN_M365_WriteJsonFileAtomic($rawFile, $rawWrapper, true);

    return true;
}

###########################################################################################################################################
/*
 * 0020 - FN_M365_WriteStatusReadJson
 * Schreibt Statusdatei für Reader (inkl. area/user run info).
 */
function FN_M365_WriteStatusReadJson(string $mode, array $run, float $startTs, array $cfg): void
{
    $file = FN_M365_StatusReadFile($cfg);

    $msFloat    = (microtime(true) - $startTs) * 1000;
    $durationMs = (int)max(1, ceil($msFloat));

    $allOk = (
        (bool)($run['contacts']['ok'] ?? false) &&
        (bool)($run['calendar']['ok'] ?? false) &&
        (bool)($run['mail']['ok'] ?? false)
    );

    $payload = FN_M365_ReadJsonFile($file);
    if (!is_array($payload)) { $payload = []; }

    $payload['component']     = 'read';
    $payload['mode']          = $mode;
    $payload['lastRun']       = date('c');
    $payload['duration_ms']   = $durationMs;
    $payload['duration_ms_f'] = round($msFloat, 3);
    $payload['ok']            = $allOk;

    $payload['run'] = [
        'contacts' => $run['contacts'],
        'calendar' => $run['calendar'],
        'mail'     => $run['mail'],
    ];

    if ($allOk) { $payload['lastOk'] = date('c'); }
    else { $payload['lastErrorAt'] = date('c'); }

    FN_M365_WriteJsonFileAtomic($file, $payload, true);
}

###########################################################################################################################################
/*
 * 0021 - FN_M365_WriteLastErrorJson
 */
function FN_M365_WriteLastErrorJson(string $component, string $code, string $message, int $httpStatus): void
{
    $cfg  = FN_M365_LoadSettings();
    $dir  = (string)(($cfg['state']['dir'] ?? 'm365'));
    $file = (string)(($cfg['state']['files']['last_error'] ?? 'last_error.json'));
    $path = FN_M365_DataPath(rtrim($dir, '/') . '/' . ltrim($file, '/'));

    $payload = [
        'ts'         => date('c'),
        'component'  => $component,
        'code'       => $code,
        'message'    => $message,
        'httpStatus' => $httpStatus
    ];

    FN_M365_WriteJsonFileAtomic($path, $payload, true);
}

###########################################################################################################################################
/*
 * 0022 - FN_M365_RequestGraph
 */
function FN_M365_RequestGraph(string $url, string $token, array $cfg, ?string $method = null)
{
    $timeout = 20;

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $resp = FN_M365_HttpRequest(($method ?: 'GET'), $url, $headers, null, $timeout);

    if (($resp['ok'] ?? false) !== true) {
        CRM_LogError('graph_http_failed', ['status' => (int)($resp['status'] ?? 0), 'url' => $url, 'error' => (string)($resp['error'] ?? '')]);
        return false;
    }

    $json = json_decode((string)($resp['body'] ?? ''), true);
    if (!is_array($json)) {
        CRM_LogError('graph_json_invalid', ['url' => $url]);
        return false;
    }

    return $json;
}

###########################################################################################################################################
/*
 * 0023 - FN_M365_HttpRequest
 */
function FN_M365_HttpRequest(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
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

    $respHeaders = substr((string)$raw, 0, $headerSize);
    $respBody    = substr((string)$raw, $headerSize);

    $ok = ($status >= 200 && $status < 300);

    return [
        'ok'      => $ok,
        'status'  => $status,
        'headers' => $respHeaders,
        'body'    => $respBody,
        'error'   => $ok ? '' : ('HTTP ' . $status)
    ];
}

###########################################################################################################################################
/*
 * 0024 - FN_M365_ReadJsonFile
 */
function FN_M365_ReadJsonFile(string $file)
{
    if ($file === '' || !is_file($file)) { return false; }

    $raw = @file_get_contents($file);
    if ($raw === false) { return false; }

    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : false;
}

###########################################################################################################################################
/*
 * 0025 - FN_M365_WriteJsonFileAtomic
 */
function FN_M365_WriteJsonFileAtomic(string $file, array $payload, bool $pretty): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $tmp = $file . '.tmp';

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($pretty) { $flags |= JSON_PRETTY_PRINT; }

    $json = json_encode($payload, $flags);
    if ($json === false) { return false; }

    if (@file_put_contents($tmp, $json . "\n") === false) { return false; }

    return @rename($tmp, $file);
}

###########################################################################################################################################
/*
 * 0026 - FN_M365_NormalizeContactsToCoreItems
 * Normalisiert Graph-Contacts zu Core-Schema (items[]) wie in /data/contacts.json.
 */
function FN_M365_NormalizeContactsToCoreItems(array $all): array
{
    $items = [];

    foreach ($all as $c) {

        if (!is_array($c)) { continue; }

        $id          = trim((string)($c['id'] ?? ''));
        if ($id === '') { continue; }

        $displayName = trim((string)($c['displayName'] ?? ''));
        $givenName   = trim((string)($c['givenName'] ?? ''));
        $surname     = trim((string)($c['surname'] ?? ''));
        $companyName = trim((string)($c['companyName'] ?? ''));

        $email = '';
        if (isset($c['emailAddresses']) && is_array($c['emailAddresses']) && count($c['emailAddresses']) > 0) {
            $ea = $c['emailAddresses'][0];
            if (is_array($ea)) { $email = trim((string)($ea['address'] ?? '')); }
        }

        $phones = [];

        if (isset($c['businessPhones']) && is_array($c['businessPhones'])) {
            foreach ($c['businessPhones'] as $p) {
                $p = trim((string)$p);
                if ($p !== '') { $phones[] = $p; }
            }
        }

        if (isset($c['homePhones']) && is_array($c['homePhones'])) {
            foreach ($c['homePhones'] as $p) {
                $p = trim((string)$p);
                if ($p !== '') { $phones[] = $p; }
            }
        }

        $mobile = trim((string)($c['mobilePhone'] ?? ''));
        if ($mobile !== '') { $phones[] = $mobile; }

        $phones = array_values(array_unique($phones));

        $items[] = [
            'id'          => $id,
            'displayName' => $displayName,
            'givenName'   => $givenName,
            'surname'     => $surname,
            'companyName' => $companyName,
            'email'       => $email,
            'phones'      => $phones,
            'source'      => 'm365'
        ];
    }

    usort($items, static function(array $a, array $b) {
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    return $items;
}

###########################################################################################################################################
/*
 * 0027 - FN_M365_NormalizeCalendarToCoreItems
 * Normalisiert Graph-Events zu stabilem Core-Schema (ohne volatile Felder).
 * Ziel: /data/calendar.json soll NICHT ständig "changed" werden, wenn keine Termin-Änderung passiert.
 */
function FN_M365_NormalizeCalendarToCoreItems(array $all, string $userKey, string $upn): array
{
    $items = [];

    foreach ($all as $e) {

        if (!is_array($e)) { continue; }

        $id = trim((string)($e['id'] ?? ''));
        if ($id === '') { continue; }

        $subject   = trim((string)($e['subject'] ?? ''));
        $isAllDay  = (bool)($e['isAllDay'] ?? false);
        $isCancel  = (bool)($e['isCancelled'] ?? false);
        $showAs    = trim((string)($e['showAs'] ?? ''));
        $importance = trim((string)($e['importance'] ?? ''));
        $webLink   = trim((string)($e['webLink'] ?? ''));

        $start = (array)($e['start'] ?? []);
        $end   = (array)($e['end'] ?? []);

        $startDt = trim((string)($start['dateTime'] ?? ''));
        $startTz = trim((string)($start['timeZone'] ?? ''));
        $endDt   = trim((string)($end['dateTime'] ?? ''));
        $endTz   = trim((string)($end['timeZone'] ?? ''));

        $loc = (array)($e['location'] ?? []);
        $location = trim((string)($loc['displayName'] ?? ''));

        $org = (array)($e['organizer'] ?? []);
        $orgEmail = '';
        if (isset($org['emailAddress']) && is_array($org['emailAddress'])) {
            $orgEmail = trim((string)($org['emailAddress']['address'] ?? ''));
        }

        $cats = [];
        if (isset($e['categories']) && is_array($e['categories'])) {
            foreach ($e['categories'] as $c) {
                $c = trim((string)$c);
                if ($c !== '') { $cats[] = $c; }
            }
        }
        sort($cats);

        $att = [];
        if (isset($e['attendees']) && is_array($e['attendees'])) {
            foreach ($e['attendees'] as $a) {
                if (!is_array($a)) { continue; }
                $ea = (array)($a['emailAddress'] ?? []);
                $addr = trim((string)($ea['address'] ?? ''));
                if ($addr !== '') { $att[] = $addr; }
            }
        }
        $att = array_values(array_unique($att));
        sort($att);

        $items[] = [
            'id'         => $id,
            'user'       => $userKey,
            'upn'        => $upn,
            'subject'    => $subject,
            'start'      => ['dateTime' => $startDt, 'timeZone' => $startTz],
            'end'        => ['dateTime' => $endDt, 'timeZone' => $endTz],
            'isAllDay'   => $isAllDay,
            'isCanceled' => $isCancel,
            'location'   => $location,
            'organizer'  => $orgEmail,
            'attendees'  => $att,
            'categories' => $cats,
            'showAs'     => $showAs,
            'importance' => $importance,
            'webLink'    => $webLink,
            'source'     => 'm365',
        ];
    }

    usort($items, static function(array $a, array $b) {
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    return $items;
}

###########################################################################################################################################
/*
 * 0028 - FN_M365_BuildStableHash
 * Baut stabilen Hash über Daten.
 */
function FN_M365_BuildStableHash(mixed $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { $json = ''; }
    return hash('sha256', $json);
}

###########################################################################################################################################
/*
 * 0029 - FN_M365_WriteCoreJsonIfChanged
 * Schreibt /data/{contacts|calendar}.json nur, wenn sich items[] geändert haben.
 * Return: true=geschrieben, false=unverändert.
 */
function FN_M365_WriteCoreJsonIfChanged(array $items, string $targetFile, string $type): bool
{
    $newHash = FN_M365_BuildStableHash($items);

    $old = FN_M365_ReadJsonFile($targetFile);
    if (is_array($old) && isset($old['items']) && is_array($old['items'])) {

        $oldItems = $old['items'];

        usort($oldItems, static function(array $a, array $b) {
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        $oldHash = FN_M365_BuildStableHash($oldItems);

        if (hash_equals($oldHash, $newHash)) {
            return false;
        }
    }

    $payload = [
        'version'    => 1,
        'type'       => $type,
        'updated_at' => date('c'),
        'count'      => count($items),
        'items'      => $items,
    ];

    return FN_M365_WriteJsonFileAtomic($targetFile, $payload, true);
}
