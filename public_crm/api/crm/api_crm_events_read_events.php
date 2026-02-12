<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/crm/api_crm_events_read_events.php
 *
 * Zweck (Debug/Tooling):
 * - HTTP-Wrapper um den internen Reader (/public_crm/_lib/events/crm_events_read.php)
 * - NUR LESEND (Writer bleibt einzige Schreibstelle)
 * - Token-Guard (aus secrets_events.php)
 *
 * Modi:
 * - all:   alle Events
 * - id:    ein Event per event_id
 * - ref:   Events per refs{ns,id} (ref_ns + ref_id)
 * - query: Filter state/source/type (state,event_source,event_type,limit,offset,order)
 *
 * WICHTIG:
 * - Wenn ref/id NICHT gefunden -> events = [] (kein Fallback auf "all")
 */

define('CRM_IS_API', true);
define('CRM_AUTH_REQUIRED', false);

require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function EVT_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function EVT_Str($v): string
{
    return trim((string)($v ?? ''));
}

function EVT_Int($v, int $def = 0): int
{
    if ($v === null) { return $def; }
    if (is_int($v)) { return $v; }
    $s = trim((string)$v);
    if ($s === '' || !preg_match('/^-?\d+$/', $s)) { return $def; }
    return (int)$s;
}

function EVT_Bool($v, bool $def = false): bool
{
    if ($v === null) { return $def; }
    if (is_bool($v)) { return $v; }
    $s = strtolower(trim((string)$v));
    if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on') { return true; }
    if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off') { return false; }
    return $def;
}

/* ---------------- Token Guard ---------------- */

$token = EVT_Str($_GET['token'] ?? '');
#$secFile = CRM_ROOT . '/../config/events/secrets_events.php';
$secFile = dirname(CRM_ROOT) . '/config/events/secrets_events.php';
$SEC = is_file($secFile) ? (array)require $secFile : [];

$expected = '';
if (isset($SEC['events']) && is_array($SEC['events'])) {
    $expected = EVT_Str($SEC['events']['api_token'] ?? ($SEC['events']['token'] ?? ''));
} else {
    $expected = EVT_Str($SEC['api_token'] ?? ($SEC['token'] ?? ''));
}



if ($expected !== '') {
    if ($token === '' || !hash_equals($expected, $token)) {
        EVT_Out(['ok' => false, 'error' => 'forbidden'], 403);
    }
}

/* ---------------- Reader ---------------- */

$readerLib = CRM_ROOT . '/_lib/events/crm_events_read.php';
if (!is_file($readerLib)) {
    EVT_Out(['ok' => false, 'error' => 'reader_missing', 'file' => $readerLib], 500);
}
require_once $readerLib;

if (!function_exists('CRM_Events_ReadAll')) {
    EVT_Out(['ok' => false, 'error' => 'reader_invalid'], 500);
}

/* ---------------- Inputs ---------------- */

$eventId = EVT_Str($_GET['event_id'] ?? '');
$refNs   = EVT_Str($_GET['ref_ns'] ?? '');
$refId   = EVT_Str($_GET['ref_id'] ?? '');

// Legacy/kompatibel: einige Tests nutzten "res" als ref_id
if ($refId === '') {
    $refId = EVT_Str($_GET['res'] ?? '');
}

$mode = EVT_Str($_GET['mode'] ?? '');
if ($mode === '') {
    if ($eventId !== '') {
        $mode = 'id';
    } elseif ($refNs !== '' && $refId !== '') {
        $mode = 'ref';
    } elseif (
        isset($_GET['state']) ||
        isset($_GET['event_source']) ||
        isset($_GET['event_type']) ||
        isset($_GET['limit']) ||
        isset($_GET['offset']) ||
        isset($_GET['order'])
    ) {
        $mode = 'query';
    } else {
        $mode = 'all';
    }
}

/* ---------------- Execute ---------------- */

if ($mode === 'id') {
    if ($eventId === '') {
        EVT_Out(['ok' => false, 'error' => 'missing_event_id'], 400);
    }

    $e = CRM_Events_GetById($eventId);
    EVT_Out([
        'ok'    => true,
        'mode'  => 'id',
        'count' => $e ? 1 : 0,
        'events'=> $e ? [$e] : [],
    ]);
}

if ($mode === 'ref') {
    if ($refNs === '' || $refId === '') {
        EVT_Out(['ok' => false, 'error' => 'missing_ref', 'need' => ['ref_ns','ref_id']], 400);
    }

    // Mehrfachtreffer möglich -> alle zurück (deterministisch: latest first)
    if (function_exists('CRM_Events_GetByRefAll')) {
        $events = CRM_Events_GetByRefAll($refNs, $refId);
    } else {
        // Fallback: single
        $one = CRM_Events_GetByRef($refNs, $refId);
        $events = $one ? [$one] : [];
    }

    EVT_Out([
        'ok'     => true,
        'mode'   => 'ref',
        'ref_ns' => $refNs,
        'ref_id' => $refId,
        'count'  => count($events),
        'events' => $events,
    ]);
}

if ($mode === 'query') {
    $state  = $_GET['state'] ?? null;
    $src    = $_GET['event_source'] ?? null;
    $typ    = $_GET['event_type'] ?? null;
    $limit  = EVT_Int($_GET['limit'] ?? 0, 0);
    $offset = EVT_Int($_GET['offset'] ?? 0, 0);
    $order  = EVT_Str($_GET['order'] ?? 'desc');

    // Optional: CSV -> array
    $stateSet = null;
    if (is_string($state) && strpos($state, ',') !== false) {
        $stateSet = array_map('trim', explode(',', $state));
    } elseif ($state !== null) {
        $stateSet = $state;
    }

    $srcSet = null;
    if (is_string($src) && strpos($src, ',') !== false) {
        $srcSet = array_map('trim', explode(',', $src));
    } elseif ($src !== null) {
        $srcSet = $src;
    }

    $typSet = null;
    if (is_string($typ) && strpos($typ, ',') !== false) {
        $typSet = array_map('trim', explode(',', $typ));
    } elseif ($typ !== null) {
        $typSet = $typ;
    }

    $events = CRM_Events_Query([
        'state'        => $stateSet,
        'event_source' => $srcSet,
        'event_type'   => $typSet,
        'limit'        => $limit,
        'offset'       => $offset,
        'order'        => ($order !== '' ? $order : 'desc'),
    ]);

    EVT_Out([
        'ok'     => true,
        'mode'   => 'query',
        'count'  => count($events),
        'events' => $events,
    ]);
}

// mode=all (default)
$events = CRM_Events_ReadAll();
EVT_Out([
    'ok'     => true,
    'mode'   => 'all',
    'count'  => count($events),
    'events' => $events,
]);
