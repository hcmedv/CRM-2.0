<?php
declare(strict_types=1);

/*
  Datei: /public_service/api/status_public.php
  Zweck:
  - Same-Origin Proxy für die Service-Webseite (kein CORS)
  - Holt PUBLIC Status von dev.hcmedv.de und gibt JSON zurück
  - Kein Login/keine Cookies weiterreichen
  - Liefert effective_state: online|busy|away|off
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('STATUS_REMOTE_URL', 'https://dev.hcmedv.de/api/user/api_user_status_public.php');
define('HTTP_TIMEOUT_SEC', 3);

function FN_JsonOut(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function FN_FetchJson(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) { return []; }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, HTTP_TIMEOUT_SEC);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, HTTP_TIMEOUT_SEC);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    // Wichtig: keine Cookies / keine Auth weiterreichen
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: service-status-proxy/1.0'
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // HTTP-Status hart prüfen (JSON mit 404/500 gilt als Fehler)
    if ($code < 200 || $code >= 300) {
        return [
            '_ok'   => false,
            '_http' => $code,
            '_err'  => 'bad_http_status',
            '_raw'  => (string)$raw
        ];
    }

    if ($raw === false || $raw === '') {
        return ['_ok' => false, '_http' => $code, '_err' => $err ?: 'empty_response', '_raw' => ''];
    }

    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['_ok' => false, '_http' => $code, '_err' => 'non_json', '_raw' => (string)$raw];
    }

    $j['_ok']   = true;
    $j['_http'] = $code;
    return $j;
}

/*
  effective_state ableiten:
  - 1) wenn Upstream already effective_state liefert -> übernehmen
  - 2) sonst: manual_state + pbx_busy (Overlay)
  - 3) sonst: state (alt)
*/
function FN_DeriveEffectiveState(array $res): string
{
    $eff = strtolower(trim((string)($res['effective_state'] ?? '')));
    if ($eff !== '') { return $eff; }

    $manual = strtolower(trim((string)($res['manual_state'] ?? '')));
    $pbx    = (bool)($res['pbx_busy'] ?? false);

    if ($manual === 'off') { return 'off'; }
    if ($manual === 'away') { return 'away'; }
    if ($manual === 'busy') { return 'busy'; }

    // online/auto/leer -> pbx overlay
    if ($manual === 'online' || $manual === 'auto' || $manual === '') {
        return $pbx ? 'busy' : 'online';
    }

    $st = strtolower(trim((string)($res['state'] ?? 'off')));
    if ($st === 'online') { return 'online'; }
    if ($st === 'away') { return 'away'; }
    return 'off';
}

// Nur GET zulassen
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    FN_JsonOut(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$res = FN_FetchJson(STATUS_REMOTE_URL);
if (!(bool)($res['_ok'] ?? false)) {
    FN_JsonOut(502, [
        'ok' => false,
        'error' => 'upstream_failed',
        'up_http' => (int)($res['_http'] ?? 0),
        'up_err' => (string)($res['_err'] ?? 'unknown')
    ]);
}

$ok = (bool)($res['ok'] ?? false);
if (!$ok) {
    FN_JsonOut(200, [
        'ok' => false,
        'effective_state' => 'off',
        'state' => 'off',
        'error' => (string)($res['error'] ?? 'upstream_not_ok')
    ]);
}

$effective = FN_DeriveEffectiveState($res);
$effective = strtolower(trim($effective));

$allowed = ['online','busy','away','off'];
if (!in_array($effective, $allowed, true)) { $effective = 'off'; }

// optional: legacy "state" beibehalten (ohne busy) für ältere Clients
$legacyState = ($effective === 'busy') ? 'online' : $effective;

FN_JsonOut(200, [
    'ok' => true,
    'effective_state' => $effective,
    'state' => $legacyState
]);
