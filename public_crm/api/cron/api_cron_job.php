<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/cron/api_cron_job.php
 *
 * Zweck:
 * - HTTPS-Cron Endpoint (Hoster) -> triggert interne Runner (TeamViewer, M365, ...)
 * - Auth: Basic Auth (BN/PW) aus /config/cron/secrets_cron.php
 * - Settings: /config/cron/settings_cron.php
 * - TeamViewer: Übergang per HTTP Call auf /api/teamviewer/api_teamviewer_read.php (mit Token)
 * - M365:      Übergang per HTTP Call auf /api/m365/api_m365_read.php (mit Token)
 * - Liefert immer JSON (kein Redirect)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function CRON_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function CRON_Str($v): string
{
    return trim((string)($v ?? ''));
}

function CRON_HttpGet(string $url, int $timeoutSec = 120): array
{
    $t0 = microtime(true);

    // Redirects NICHT automatisch folgen
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeoutSec,
            'follow_location' => 0,
            'header'          => "Accept: application/json\r\nX-Requested-With: XMLHttpRequest\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);

    $hdrs = $http_response_header ?? [];

    $code = 0;
    foreach ($hdrs as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$h, $m)) { $code = (int)$m[1]; break; }
    }

    $location = '';
    $contentType = '';
    foreach ($hdrs as $h) {
        if ($location === '' && stripos((string)$h, 'Location:') === 0) {
            $location = trim(substr((string)$h, 9));
        }
        if ($contentType === '' && stripos((string)$h, 'Content-Type:') === 0) {
            $contentType = trim(substr((string)$h, 13));
        }
    }

    $bodyStr  = is_string($body) ? (string)$body : '';
    $bodyTrim = ltrim($bodyStr);

    $isJsonCT   = (stripos($contentType, 'application/json') !== false);
    $looksJson  = ($bodyTrim !== '' && ($bodyTrim[0] === '{' || $bodyTrim[0] === '['));
    $isRedirect = ($code >= 300 && $code < 400) || ($location !== '');

    $ok = ($body !== false && !$isRedirect && $code >= 200 && $code < 300 && ($isJsonCT || $looksJson));

    return [
        'ok'           => $ok,
        'http_code'    => $code,
        'duration_ms'  => (int)round((microtime(true) - $t0) * 1000),
        'content_type' => $contentType,
        'location'     => $location,
        'body'         => $bodyStr,
    ];
}

function CRON_DecodeJsonBody(string $body): ?array
{
    // BOM + Whitespaces weg
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $raw = (string)$raw;

    // harte Control-Chars entfernen (außer \t \n \r)
    $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw);

    $raw = ltrim($raw);

    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}


function CRON_SummaryTeamviewer(?array $j): ?array
{
    if (!is_array($j)) { return null; }

    return [
        'ok'      => (bool)($j['ok'] ?? false),
        'records' => (int)($j['records'] ?? 0),
        'upserts' => (int)($j['upserts'] ?? 0),
        'skips'   => (int)($j['skips'] ?? 0),
        'errors'  => (int)($j['errors'] ?? 0),
        'errored' => (is_array($j['session_outcomes']['errored'] ?? null) ? array_slice($j['session_outcomes']['errored'], 0, 3) : []),
    ];
}

function CRON_SummaryM365(?array $j): ?array
{
    if (!is_array($j)) { return null; }

    return [
        'ok'     => (bool)($j['ok'] ?? false),
        'reader' => (string)($j['reader'] ?? ''),
        'result' => (int)($j['result'] ?? 0),
    ];
}

/* ------------------------------------------------------------------------------------------------
   Config (ohne Bootstrap)
------------------------------------------------------------------------------------------------- */

$PUBLIC_CRM = dirname(__DIR__, 2);          // .../public_crm
$APP_ROOT   = dirname($PUBLIC_CRM);         // .../crm.hcmedv.de
$CFG_ROOT   = $APP_ROOT . '/config';

$secFile = $CFG_ROOT . '/cron/secrets_cron.php';
$setFile = $CFG_ROOT . '/cron/settings_cron.php';

$SEC = is_file($secFile) ? (array)require $secFile : [];
$SET = is_file($setFile) ? (array)require $setFile : [];

$SECm = (isset($SEC['cron']) && is_array($SEC['cron'])) ? $SEC['cron'] : [];
$SETm = (isset($SET['cron']) && is_array($SET['cron'])) ? $SET['cron'] : [];

/* IP Whitelist (optional) */
$ipwl = $SETm['ip_whitelist'] ?? [];
if (is_array($ipwl) && count($ipwl) > 0) {
    $ip = CRON_Str($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '' || !in_array($ip, $ipwl, true)) {
        CRON_Out(['ok' => false, 'error' => 'ip_forbidden'], 403);
    }
}

/* ------------------------------------------------------------------------------------------------
   Basic Auth Guard (Hoster Cron)
------------------------------------------------------------------------------------------------- */

$expU = CRON_Str($SECm['basic_user'] ?? '');
$expP = CRON_Str($SECm['basic_pass'] ?? '');

$u = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
$p = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

if ($expU === '' || $expP === '' || $u === '' || $p === '' || !hash_equals($expU, $u) || !hash_equals($expP, $p)) {
    header('WWW-Authenticate: Basic realm="CRM Cron"');
    CRON_Out(['ok' => false, 'error' => 'unauthorized'], 401);
}

/* ------------------------------------------------------------------------------------------------
   Optional: Bootstrap (ok für später)
------------------------------------------------------------------------------------------------- */

define('CRM_MODULE', 'cron');
define('CRM_IS_API', true);
define('CRM_AUTH_REQUIRED', false);

require_once $PUBLIC_CRM . '/_inc/bootstrap.php';

/* ------------------------------------------------------------------------------------------------
   Runner Config
------------------------------------------------------------------------------------------------- */

$run = (isset($SETm['run']) && is_array($SETm['run'])) ? $SETm['run'] : ['teamviewer' => true, 'm365' => true];

$tvToken   = CRON_Str($SECm['teamviewer_http_token'] ?? '');
$m365Token = CRON_Str($SECm['m365_http_token'] ?? '');

$tvUrl   = 'https://crm.hcmedv.de/api/teamviewer/api_teamviewer_read.php?process=1&token=' . rawurlencode($tvToken);
$m365Url = 'https://crm.hcmedv.de/api/m365/api_m365_read.php?mode=all&force=1&token=' . rawurlencode($m365Token);

$res = [
    'ok'      => true,
    'ts'      => date('c'),
    'results' => [],
    'dbg'     => [
        'teamviewerUrl' => preg_replace('/token=[^&]+/', 'token=***', $tvUrl),
        'm365Url'       => preg_replace('/token=[^&]+/', 'token=***', $m365Url),
    ],
];

/* ------------------------------------------------------------------------------------------------
   Execute
------------------------------------------------------------------------------------------------- */

// TeamViewer
if (!empty($run['teamviewer'])) {
    if ($tvToken === '') {
        $res['results']['teamviewer'] = ['ok' => false, 'error' => 'teamviewer_token_missing'];
        $res['ok'] = false;
    } else {
        $tvHttp = CRON_HttpGet($tvUrl, 120);

        $j = CRON_DecodeJsonBody((string)($tvHttp['body'] ?? ''));
        $sum = CRON_SummaryTeamviewer($j);

        if (is_array($sum)) {
            $tvHttp['summary'] = $sum;
            unset($tvHttp['body']);
        } else {
            $tvHttp['body'] = mb_substr((string)($tvHttp['body'] ?? ''), 0, 400);
            $tvHttp['decode_error'] = json_last_error_msg();
        }

        $res['results']['teamviewer'] = $tvHttp;
        if (empty($tvHttp['ok'])) { $res['ok'] = false; }
    }
}

// M365
if (!empty($run['m365'])) {
    if ($m365Token === '') {
        $res['results']['m365'] = ['ok' => false, 'error' => 'm365_token_missing'];
        $res['ok'] = false;
    } else {
        $m365Http = CRON_HttpGet($m365Url, 120);

        $j = CRON_DecodeJsonBody((string)($m365Http['body'] ?? ''));
        $sum = CRON_SummaryM365($j);

        if (is_array($sum)) {
            $m365Http['summary'] = $sum;
            unset($m365Http['body']);
        } else {
            $m365Http['body'] = mb_substr((string)($m365Http['body'] ?? ''), 0, 400);
            $m365Http['decode_error'] = json_last_error_msg();
        }

        $res['results']['m365'] = $m365Http;
        if (empty($m365Http['ok'])) { $res['ok'] = false; }
    }
}

CRON_Out($res, 200);
