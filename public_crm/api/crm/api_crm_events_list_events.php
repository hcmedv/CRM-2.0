<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/crm/api_crm_events_list_events.php
 *
 * Zweck:
 * - Listet Events aus events.json nach workflow.state
 * - Liefert Lanes: open/work/done/archiv/deleted
 * - Liest NICHT direkt events.json, sondern nutzt zentralen Reader:
 *     /public_crm/_lib/events/crm_events_read.php
 *
 * Query (optional):
 * - states=open,work
 * - limit=200            (default 200, max 1000)
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_lib/events/crm_events_read.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function FN_JsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function FN_NormalizeState(string $state): string
{
    $s = strtolower(trim($state));
    if ($s === '') { return 'open'; }

    if ($s === 'in_work') { $s = 'work'; }
    if ($s === 'archive') { $s = 'archiv'; }

    $allowed = ['open','work','done','archiv','deleted'];
    if (!in_array($s, $allowed, true)) { return 'open'; }

    return $s;
}

function FN_ClampInt($v, int $min, int $max, int $def): int
{
    if (is_string($v) && $v !== '' && ctype_digit($v)) { $v = (int)$v; }
    if (!is_int($v)) { return $def; }
    if ($v < $min) { return $min; }
    if ($v > $max) { return $max; }
    return $v;
}

function FN_EventTitle(array $e): string
{
    $disp = $e['display'] ?? null;
    if (is_array($disp)) {
        $t = (string)($disp['title'] ?? '');
        if ($t !== '') { return $t; }
    }

    $t2 = (string)($e['title'] ?? '');
    if ($t2 !== '') { return $t2; }

    $src  = (string)($e['event_source'] ?? '');
    $type = (string)($e['event_type'] ?? '');
    $txt = trim(($src !== '' ? strtoupper($src) . ': ' : '') . ($type !== '' ? $type : 'Event'));

    return $txt !== '' ? $txt : 'Event';
}

function FN_EventSubtitle(array $e): string
{
    $disp = $e['display'] ?? null;
    if (is_array($disp)) {
        $s = (string)($disp['subtitle'] ?? '');
        if ($s !== '') { return $s; }
    }
    return '';
}

function FN_EventCustomerNumber(array $e): string
{
    $disp = $e['display'] ?? null;
    if (!is_array($disp)) { return ''; }

    $cust = $disp['customer'] ?? null;
    if (!is_array($cust)) { return ''; }

    return (string)($cust['number'] ?? '');
}

function FN_EventTs(array $e): int
{
    $tim = (isset($e['timing']) && is_array($e['timing'])) ? $e['timing'] : [];

    return
        (int)($tim['started_at'] ?? 0) ?:
        (int)($tim['ended_at'] ?? 0) ?:
        (int)($e['updated_at'] ?? 0) ?:
        (int)($e['created_at'] ?? 0);
}

/* ---------------- input ---------------- */
$statesParam = isset($_GET['states']) ? (string)$_GET['states'] : '';
$limit = FN_ClampInt($_GET['limit'] ?? null, 1, 1000, 200);

$want = null;
if (trim($statesParam) !== '') {
    $want = [];
    foreach (explode(',', $statesParam) as $s) {
        $s = FN_NormalizeState((string)$s);
        $want[$s] = true;
    }
}

/* ---------------- read via reader ---------------- */
try
{
    // Reader liefert bereits sortiert nach _sort_ts (siehe CRM_Events_Query)
    $events = CRM_Events_Query([
        'state' => $want ? array_keys($want) : null,
    ]);
}
catch (Throwable $e)
{
    FN_JsonOut([
        'ok'    => false,
        'error' => 'read_failed',
        'msg'   => $e->getMessage(),
    ], 500);
}

/* ---------------- build lanes ---------------- */
$lanes = [
    'open'    => [],
    'work'    => [],
    'done'    => [],
    'archiv'  => [],
    'deleted' => [],
];

$totalInStore = 0;

foreach ($events as $e) {
    if (!is_array($e)) { continue; }

    $totalInStore++;

    $id = (string)($e['event_id'] ?? '');
    if (trim($id) === '') { continue; }

    $st = FN_NormalizeState((string)($e['workflow']['state'] ?? 'open'));
    if (!isset($lanes[$st])) { $st = 'open'; }

    $timing = (isset($e['timing']) && is_array($e['timing'])) ? $e['timing'] : [];
    $refs   = (isset($e['refs'])   && is_array($e['refs']))   ? $e['refs']   : [];

    $lanes[$st][] = [
        'event_id'        => $id,
        'state'           => $st,
        'event_source'    => (string)($e['event_source'] ?? ''),
        'event_type'      => (string)($e['event_type'] ?? ''),
        'title'           => FN_EventTitle($e),
        'subtitle'        => FN_EventSubtitle($e),
        'customer_number' => FN_EventCustomerNumber($e),
        'created_at'      => (int)($e['created_at'] ?? 0),
        'updated_at'      => (int)($e['updated_at'] ?? 0),

        // NEU: timing + refs
        'timing' => [
            'started_at'   => array_key_exists('started_at', $timing)   ? (is_null($timing['started_at']) ? null : (int)$timing['started_at']) : null,
            'ended_at'     => array_key_exists('ended_at', $timing)     ? (is_null($timing['ended_at']) ? null : (int)$timing['ended_at']) : null,
            'duration_sec' => array_key_exists('duration_sec', $timing) ? (is_null($timing['duration_sec']) ? null : (int)$timing['duration_sec']) : null,
        ],
        'refs' => $refs,

        '_sort_ts' => FN_EventTs($e),
    ];
}

/* ---------------- sort + limit per lane ---------------- */
foreach ($lanes as $key => $items) {
    usort($items, static function(array $a, array $b): int {
        return ($b['_sort_ts'] ?? 0) <=> ($a['_sort_ts'] ?? 0);
    });

    if (count($items) > $limit) { $items = array_slice($items, 0, $limit); }

    foreach ($items as &$it) { unset($it['_sort_ts']); }
    unset($it);

    $lanes[$key] = $items;
}

FN_JsonOut([
    'ok' => true,
    'src' => [
        'reader' => CRM_ROOT . '/_lib/events/crm_events_read.php',
    ],
    'counts' => [
        'store_total' => $totalInStore,
        'open'        => count($lanes['open']),
        'work'        => count($lanes['work']),
        'done'        => count($lanes['done']),
        'archiv'      => count($lanes['archiv']),
        'deleted'     => count($lanes['deleted']),
    ],
    'lanes' => $lanes,
]);
