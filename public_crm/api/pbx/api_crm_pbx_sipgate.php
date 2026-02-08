<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/*
 * Datei: /public_crm/api/pbx/api_crm_pbx_sipgate.php
 *
 * Zweck:
 * - Webhook/Forward-Endpoint für Sipgate PBX Events (HTTP)
 * - Normalisiert Payload -> $pbx_event (snake_case) und ruft PBX Processor auf:
 *     CRM_ROOT . '/pbx/crm_process_pbx.php'  (Funktion: PBX_Process(array $pbx_event): array)
 *
 * Security:
 * - Optionaler Shared-Token aus settings_pbx.php:
 *     CRM_MOD_CFG('pbx','webhook.token','')
 *   Wenn gesetzt, muss der Token geliefert werden über:
 *   - Header:  X-CRM-Token: <token>
 *   - ODER Query: ?token=<token>
 *   - ODER Authorization: Bearer <token>
 *
 * Input:
 * - JSON Body (application/json) ODER Form-POST.
 *
 * Output:
 * - application/json
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function PBX_RESP(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function PBX_STR($v): string
{
    return trim((string)($v ?? ''));
}

function PBX_NormLower(string $s): string
{
    $s = strtolower(trim($s));
    return $s;
}

function PBX_ReadBearerToken(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $h = trim($h);
    if ($h === '') { return ''; }
    if (stripos($h, 'bearer ') === 0) {
        return trim(substr($h, 7));
    }
    return '';
}

function PBX_CheckToken(): void
{
    // token kann fehlen => Endpoint offen (wie früher). Wenn gesetzt => Pflicht.
    $cfg = (array)CRM_MOD_CFG('pbx', 'webhook', []);
    $required = PBX_STR($cfg['token'] ?? '');
    if ($required === '') { return; }

    $got = '';
    if (isset($_SERVER['HTTP_X_CRM_TOKEN'])) { $got = PBX_STR($_SERVER['HTTP_X_CRM_TOKEN']); }
    if ($got === '' && isset($_GET['token'])) { $got = PBX_STR($_GET['token']); }
    if ($got === '') { $got = PBX_ReadBearerToken(); }

    if (!hash_equals($required, $got)) {
        PBX_RESP(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function PBX_ReadInput(): array
{
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) { return $j; }
        // Fall back: raw text as single field
        return ['_raw' => $raw];
    }

    if (!empty($_POST)) {
        return (array)$_POST;
    }

    return [];
}

function PBX_ToEpoch($v): ?int
{
    if ($v === null) { return null; }
    if (is_int($v)) { return ($v > 0) ? $v : null; }
    if (is_float($v)) { $i = (int)floor($v); return ($i > 0) ? $i : null; }

    $s = PBX_STR($v);
    if ($s === '') { return null; }

    if (ctype_digit($s)) {
        $i = (int)$s;
        return ($i > 0) ? $i : null;
    }

    try {
        $dt = new DateTimeImmutable($s);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

function PBX_IsoNowLocal(): string
{
    return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
}

function PBX_NormalizeSipgateEvent(array $in): array
{
    // akzeptiert:
    // - direkte sipgate payloads
    // - bereits normalisierte payloads (provider/call_id/.../timing)
    $provider = PBX_STR($in['provider'] ?? 'sipgate');
    $callId   = PBX_STR($in['call_id'] ?? ($in['callId'] ?? ($in['id'] ?? '')));
    $from     = PBX_STR($in['from'] ?? ($in['source'] ?? ''));
    $to       = PBX_STR($in['to'] ?? ($in['target'] ?? ''));
    $dir      = PBX_STR($in['direction'] ?? ($in['dir'] ?? ''));
    $state    = PBX_STR($in['state'] ?? ($in['event'] ?? ''));

    $receivedAt = PBX_STR($in['received_at'] ?? ($in['receivedAt'] ?? ($in['timestamp'] ?? '')));
    if ($receivedAt === '') { $receivedAt = PBX_IsoNowLocal(); }

    // timing
    $timing = [];
    if (isset($in['timing']) && is_array($in['timing'])) { $timing = $in['timing']; }

    $startedAt = PBX_ToEpoch($timing['started_at'] ?? ($in['started_at'] ?? null));
    $endedAt   = PBX_ToEpoch($timing['ended_at'] ?? ($in['ended_at'] ?? null));

    // sipgate hook liefert häufig start/end als ISO
    if ($startedAt === null) { $startedAt = PBX_ToEpoch($in['start_date'] ?? ($in['startDate'] ?? null)); }
    if ($endedAt === null)   { $endedAt   = PBX_ToEpoch($in['end_date'] ?? ($in['endDate'] ?? null)); }

    $duration = null;
    if ($startedAt !== null && $endedAt !== null && $endedAt > $startedAt) { $duration = $endedAt - $startedAt; }

    $pbx_event = [
        'provider'     => $provider !== '' ? $provider : 'sipgate',
        'call_id'      => $callId,
        'from'         => $from,
        'to'           => $to,
        'direction'    => PBX_NormLower($dir),
        'state'        => PBX_NormLower($state),
        'received_at'  => $receivedAt,
    ];

    $pbx_event['timing'] = [];
    if ($startedAt !== null) { $pbx_event['timing']['started_at'] = $startedAt; }
    if ($endedAt !== null)   { $pbx_event['timing']['ended_at']   = $endedAt; }
    if ($duration !== null)  { $pbx_event['timing']['duration_sec'] = $duration; }

    return $pbx_event;
}

function PBX_LogRaw(array $pbx_event, array $in): void
{
    $cfg   = (array)CRM_MOD_CFG('pbx', 'webhook', []);
    $debug = (bool)($cfg['debug'] ?? false);

    $files = (array)CRM_MOD_CFG('pbx', 'files', []);
    $fn    = PBX_STR($files['raw_events_jsonl'] ?? 'pbx_raw.jsonl');

    $dir = rtrim((string)CRM_MOD_PATH('pbx', 'log'), '/');
    if ($dir === '') { $dir = rtrim((string)CRM_MOD_PATH('pbx', 'data'), '/'); }
    if ($dir === '') { return; }

    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir)) { return; }

    $path = $dir . '/' . $fn;

    $row = [
        'ts'        => PBX_IsoNowLocal(),
        'remote_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'pbx_event'  => $pbx_event,
    ];
    if ($debug) { $row['in'] = $in; }

    @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

/* ---------------- main ---------------- */

PBX_CheckToken();

$in = PBX_ReadInput();
$pbx_event = PBX_NormalizeSipgateEvent($in);

// Minimal check
if (PBX_STR($pbx_event['call_id'] ?? '') === '' || PBX_STR($pbx_event['state'] ?? '') === '') {
    PBX_RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'call_id/state required'], 400);
}


// Processor laden
$processor = CRM_ROOT . '/pbx/crm_process_pbx.php';
if (!is_file($processor)) {
    PBX_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'processor_missing'], 500);
}

require_once $processor;

if (!function_exists('PBX_Process')) {
    PBX_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'PBX_Process missing'], 500);
}

try {
    $r = PBX_Process($pbx_event);
    if (!is_array($r)) {
        PBX_RESP(['ok' => false, 'error' => 'process_failed', 'message' => 'invalid_process_response'], 500);
    }
    if (($r['ok'] ?? false) !== true) {
        PBX_RESP(['ok' => false, 'error' => 'process_failed', 'ctx' => $r], 500);
    }

    PBX_RESP([
        'ok'        => true,
        'processed' => true,
        'writer'    => $r['writer'] ?? null,
        'event_id'  => $r['event_id'] ?? null,
    ]);
} catch (Throwable $e) {
    PBX_RESP(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
}
