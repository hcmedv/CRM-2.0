<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/teamviewer/api_teamviewer_read.php
 *
 * Zweck:
 * - TeamViewer Connections via API abrufen (RAW)
 * - Payload immer an Processor weiterreichen (In-Memory)
 * - OPTIONAL: Debug-RAW-Kopie gemäß raw_store.enabled schreiben (raw_store.filename_current)
 * - Danach Processor starten (Pfad aus Settings): teamviewer.api.process_path_file
 *
 * Zugriff:
 * - Job-Token (?token=... oder Header) ODER eingeloggter User
 *
 * Konvention:
 * - Modulname = 'teamviewer'
 * - Alle Pfade / Secrets / Settings ausschließlich über Bootstrap-Helper
 * - RAW Format ist verbindlich:
 *   { fetched_at, request:{...}, data:{ records:[...] } }
 */

define('CRM_IS_API', true);
define('CRM_AUTH_REQUIRED', false);

require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$MOD = 'teamviewer';

/* ---------------- Helper ---------------- */

function TV_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function TV_GetJobTokenFromRequest(): string
{
    $h = (string)($_SERVER['HTTP_X_CRM_JOB_TOKEN'] ?? $_SERVER['HTTP_X_JOB_TOKEN'] ?? '');
    if ($h !== '') { return trim($h); }

    $q = (string)($_GET['token'] ?? '');
    if ($q !== '') { return trim($q); }

    return '';
}

function TV_TimingSafeEquals(string $a, string $b): bool
{
    if ($a === '' || $b === '') { return false; }
    if (function_exists('hash_equals')) { return hash_equals($a, $b); }
    if (strlen($a) !== strlen($b)) { return false; }

    $r = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $r |= (ord($a[$i]) ^ ord($b[$i]));
    }
    return $r === 0;
}

function TV_RequireJobTokenOrLogin(string $mod): void
{
    $provided = TV_GetJobTokenFromRequest();
    $expected = (string)CRM_MOD_SECRET($mod, 'jobs_access_token', '');

    if ($provided !== '' && $expected !== '' && TV_TimingSafeEquals($provided, $expected)) { return; }
    if (isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user'])) { return; }

    TV_Out(['ok' => false, 'error' => 'forbidden'], 403);
}

function TV_IsDebug(string $mod): bool
{
    return (bool)CRM_MOD_CFG($mod, 'debug', false);
}

function TV_Log(string $mod, string $msg, array $ctx = []): void
{
    if (!TV_IsDebug($mod)) { return; }

    $dir = CRM_MOD_PATH($mod, 'log');
    if ($dir === '') { return; }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        if (!is_dir($dir)) { return; }
    }

    $file = rtrim($dir, '/') . '/read_' . date('Y-m-d') . '.log';
    $row  = [
        'ts'  => date('c'),
        'msg' => $msg,
        'ctx' => $ctx
    ];

    @file_put_contents(
        $file,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}


function TV_FileWriteAtomic(string $file, string $content): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $content);
    @chmod($tmp, 0664);

    if (!@rename($tmp, $file)) {
        $dst = fopen($file, 'wb');
        $src = fopen($tmp, 'rb');
        if ($dst && $src) { stream_copy_to_stream($src, $dst); }
        if ($src) { fclose($src); }
        if ($dst) { fclose($dst); }
        @unlink($tmp);
    }
}

function TV_Request(string $url, string $bearer): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
        ],
    ]);

    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    @curl_close($ch);

    $json = null;
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) { $json = $j; }
    }

    return [
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'json'      => $json,
        'curl_err'  => $err,
        'raw'       => $raw,
    ];
}

/*
 * TV_BuildConnectionsUrl
 * Baut die URL für /reports/connections anhand Modul-Settings:
 * - teamviewer.api.api_base
 * - teamviewer.api.connections_path
 * - teamviewer.api.sessions_limit
 * - teamviewer.api.sessions_range_days
 *
 * WICHTIG: TeamViewer erwartet from_date/to_date (nicht from/to).
 */
function TV_BuildConnectionsUrl(string $mod): array
{
    $api = (array)CRM_MOD_CFG($mod, 'api', []);

    $apiBase = (string)($api['api_base'] ?? 'https://webapi.teamviewer.com/api/v1');
    $path    = (string)($api['connections_path'] ?? '/reports/connections');

    $limit = (int)($api['sessions_limit'] ?? 50);
    if ($limit <= 0) { $limit = 50; }
    if ($limit > 1000) { $limit = 1000; }

    $rangeDays = (int)($api['sessions_range_days'] ?? 3);
    if ($rangeDays <= 0) { $rangeDays = 3; }

    $untilTs = time();
    $fromTs  = $untilTs - ($rangeDays * 86400);

    $fromIso = gmdate('Y-m-d\TH:i:s\Z', $fromTs);
    $toIso   = gmdate('Y-m-d\TH:i:s\Z', $untilTs);

    $params = [
        'from_date' => $fromIso,
        'to_date'   => $toIso,
        'limit'     => (string)$limit,
    ];

    $url =
        rtrim($apiBase, '/')
        . '/'
        . ltrim($path, '/')
        . '?'
        . http_build_query($params);

    return [
        'url'        => $url,
        'limit'      => $limit,
        'range_days' => $rangeDays,
        'from'       => $fromIso,
        'to'         => $toIso,
        'params'     => $params,
    ];
}

/*
 * TV_ApplyLimitToResponse
 * Schneidet die TeamViewer-Response lokal auf $limit, falls TV trotzdem Default liefert.
 * Erwartetes Format: ['records' => [...]]
 */
function TV_ApplyLimitToResponse(array $json, int $limit): array
{
    if ($limit <= 0) { return $json; }

    if (isset($json['records']) && is_array($json['records'])) {
        if (count($json['records']) > $limit) {
            $json['records'] = array_slice($json['records'], 0, $limit);

            if (array_key_exists('records_remaining', $json)) { $json['records_remaining'] = 0; }
            if (array_key_exists('next_offset', $json)) { unset($json['next_offset']); }
        }
    }

    return $json;
}

/* ---------------- Guard ---------------- */

TV_RequireJobTokenOrLogin($MOD);

/* ---------------- Config / Secrets ---------------- */

$apiToken = (string)CRM_MOD_SECRET($MOD, 'api_token', '');
if ($apiToken === '') {
    TV_Log($MOD, 'missing_api_token');
    TV_Out(['ok' => false, 'error' => 'missing_api_token'], 500);
}

$dataDir = CRM_MOD_PATH($MOD, 'data');
if ($dataDir === '') {
    TV_Out(['ok' => false, 'error' => 'data_path_missing'], 500);
}

$api = (array)CRM_MOD_CFG($MOD, 'api', []);

/* ---------------- URL Build ---------------- */

$u   = TV_BuildConnectionsUrl($MOD);
$url = (string)($u['url'] ?? '');

TV_Log($MOD, 'request_start', [
    'url'        => $url,
    'limit'      => $u['limit'] ?? null,
    'range_days' => $u['range_days'] ?? null,
    'from'       => $u['from'] ?? null,
    'to'         => $u['to'] ?? null,
]);

/* ---------------- Fetch ---------------- */

$res = TV_Request($url, $apiToken);
if (!($res['ok'] ?? false)) {
    TV_Log($MOD, 'api_failed', [
        'http_code' => $res['http_code'] ?? 0,
        'curl_err'  => $res['curl_err'] ?? '',
        'url'       => $url,
    ]);

    TV_Out([
        'ok'        => false,
        'error'     => 'teamviewer_api_failed',
        'http_code' => $res['http_code'] ?? 0,
        'curl_err'  => $res['curl_err'] ?? '',
    ], 502);
}

$tvJson = (array)($res['json'] ?? []);
$tvJson = TV_ApplyLimitToResponse($tvJson, (int)($u['limit'] ?? 0));

$payload = [
    'fetched_at' => date('c'),
    'request'    => [
        'url'        => (string)($u['url'] ?? ''),
        'limit'      => (int)($u['limit'] ?? 0),
        'range_days' => (int)($u['range_days'] ?? 0),
        'from'       => (string)($u['from'] ?? ''),
        'to'         => (string)($u['to'] ?? ''),
        'params'     => (array)($u['params'] ?? []),
    ],
    'data' => $tvJson,
];

/* ---------------- In-Memory Übergabe an Processor ---------------- */

$GLOBALS['CRM_TV_POLL_PAYLOAD'] = $payload;

/* ---------------- RAW Store (Debug-Kopie, optional) ---------------- */

$rawStore   = (array)CRM_MOD_CFG($MOD, 'raw_store', []);
$rawWritten = false;
$rawFile    = null;

if ((bool)($rawStore['enabled'] ?? false) === true) {

    // CRM_MOD_PATH('teamviewer','data') liefert:
    // <crm_data>/modules/teamviewer/
    // => nur Dateiname anhängen, kein Unterordner doppelt setzen
    $fileName = (string)($rawStore['filename_current'] ?? 'teamviewer_raw_current.json');
    $rawFile  = rtrim($dataDir, '/') . '/' . $fileName;

    TV_FileWriteAtomic(
        $rawFile,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $rawWritten = true;

    $recCount = null;
    if (isset($payload['data']['records']) && is_array($payload['data']['records'])) { $recCount = count($payload['data']['records']); }

    TV_Log($MOD, 'raw_written', [
        'file'    => $rawFile,
        'records' => $recCount,
    ]);
}

/* ---------------- Process ---------------- */

$procRel = (string)($api['process_path_file'] ?? '/remote/crm_process_teamviewer.php');
$procAbs = CRM_ROOT . '/' . ltrim($procRel, '/');

if (!is_file($procAbs)) {
    TV_Out([
        'ok'          => true,
        'fetched'     => true,
        'processed'   => false,
        'raw_written' => $rawWritten,
        'raw_file'    => $rawFile,
        'process'     => $procRel,
    ], 200);
}

ob_start();
require $procAbs;
$out = (string)ob_get_clean();

$j = json_decode($out, true);
if (is_array($j)) {
    TV_Out([
        'ok'          => true,
        'fetched'     => true,
        'processed'   => true,
        'raw_written' => $rawWritten,
        'raw_file'    => $rawFile,
        'process'     => $procRel,
        'processor'   => $j,
    ], 200);
}

TV_Out([
    'ok'            => true,
    'fetched'       => true,
    'processed'     => true,
    'raw_written'   => $rawWritten,
    'raw_file'      => $rawFile,
    'process'       => $procRel,
    'processor_raw' => $out,
], 200);
