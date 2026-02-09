<?php
declare(strict_types=1);

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
 * Hinweis:
 * - raw_store.enabled ist NUR Debug/Replay/Statistik (Kopie), kein Verarbeitungs-An/Aus.
 */



require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$MOD = 'pbx';

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
    return strtolower(trim($s));
}

function PBX_ReadBearerToken(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $h = trim($h);
    if ($h === '') { return ''; }
    if (stripos($h, 'bearer ') === 0) { return trim(substr($h, 7)); }
    return '';
}

function PBX_CheckToken(): void
{
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
        return ['_raw' => $raw];
    }

    if (!empty($_POST)) { return (array)$_POST; }
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
    $provider = PBX_STR($in['provider'] ?? 'sipgate');
    $callId   = PBX_STR($in['call_id'] ?? ($in['callId'] ?? ($in['id'] ?? '')));
    $from     = PBX_STR($in['from'] ?? ($in['source'] ?? ''));
    $to       = PBX_STR($in['to'] ?? ($in['target'] ?? ''));
    $dir      = PBX_STR($in['direction'] ?? ($in['dir'] ?? ''));
    $state    = PBX_STR($in['state'] ?? ($in['event'] ?? ''));

    $receivedAt = PBX_STR($in['received_at'] ?? ($in['receivedAt'] ?? ($in['timestamp'] ?? '')));
    if ($receivedAt === '') { $receivedAt = PBX_IsoNowLocal(); }

    $timing = [];
    if (isset($in['timing']) && is_array($in['timing'])) { $timing = $in['timing']; }

    $startedAt = PBX_ToEpoch($timing['started_at'] ?? ($in['started_at'] ?? null));
    $endedAt   = PBX_ToEpoch($timing['ended_at'] ?? ($in['ended_at'] ?? null));

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

/* ---------------- RAW Store (Debug-Kopie) ---------------- */

function PBX_RawEnsureDir(string $dir): bool
{
    if ($dir === '') { return false; }
    if (is_dir($dir)) { return true; }
    @mkdir($dir, 0775, true);
    return is_dir($dir);
}

function PBX_RawRotateIfTooLarge(string $file, int $maxBytes): void
{
    if ($maxBytes <= 0) { return; }
    if (!is_file($file)) { return; }

    $sz = @filesize($file);
    if (!is_int($sz) || $sz <= $maxBytes) { return; }

    $rot = $file . '.rot.' . date('Ymd_His');
    @rename($file, $rot);
}

function PBX_RawAppendLocked(string $file, string $line): bool
{
    $fp = @fopen($file, 'ab');
    if ($fp === false) { return false; }

    try {
        @flock($fp, LOCK_EX);
        @fwrite($fp, $line);
        @fflush($fp);
        @flock($fp, LOCK_UN);
    } finally {
        @fclose($fp);
    }

    @chmod($file, 0664);
    return true;
}

function PBX_RawStoreWrite(string $mod, array $pbx_event, array $in): array
{
    $rawStore = (array)CRM_MOD_CFG($mod, 'raw_store', []);
    if (($rawStore['enabled'] ?? false) !== true) {
        return [
            'enabled' => false,
            'written' => false,
            'reason'  => 'raw_store_disabled',
            'file'    => null,
        ];
    }

    $dataBaseDir = (string)CRM_MOD_PATH($mod, 'data'); // => .../data/pbx
    if (trim($dataBaseDir) === '') {
        return [
            'enabled' => true,
            'written' => false,
            'reason'  => 'data_path_missing',
            'file'    => null,
        ];
    }

    $subDir   = trim((string)($rawStore['data_dir'] ?? ''), '/'); // meistens ''
    $fileName = trim((string)($rawStore['filename_current'] ?? 'pbx_raw_current.jsonl'));
    $mode     = strtolower(trim((string)($rawStore['mode'] ?? 'jsonl')));

    if ($fileName === '') { $fileName = 'pbx_raw_current.jsonl'; }
    if ($mode !== 'json' && $mode !== 'jsonl') { $mode = 'jsonl'; }

    $file =
        rtrim($dataBaseDir, '/')
        . '/'
        . ($subDir !== '' ? $subDir . '/' : '')
        . $fileName;

    // Ensure dir exists
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir)) {
        return [
            'enabled' => true,
            'written' => false,
            'reason'  => 'mkdir_failed',
            'file'    => $file,
        ];
    }

    // Rotation (optional)
    $maxBytes = (int)($rawStore['max_bytes'] ?? 0);
    if ($maxBytes > 0 && is_file($file)) {
        $sz = @filesize($file);
        if (is_int($sz) && $sz > $maxBytes) {
            $rot = $file . '.rot.' . date('Ymd_His');
            @rename($file, $rot);
        }
    }

    $debug = (bool)CRM_MOD_CFG($mod, 'debug', false);

    $row = [
        'ts'        => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'remote_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'pbx_event' => $pbx_event,
    ];
    if ($debug) { $row['in'] = $in; }

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($mode === 'json') { $flags |= JSON_PRETTY_PRINT; }

    $line = json_encode($row, $flags);
    if (!is_string($line) || $line === '') {
        return [
            'enabled' => true,
            'written' => false,
            'reason'  => 'json_encode_failed',
            'file'    => $file,
            'mode'    => $mode,
        ];
    }
    $line .= "\n";

    // Append (mit Lock)
    $fp = @fopen($file, 'ab');
    if ($fp === false) {
        return [
            'enabled' => true,
            'written' => false,
            'reason'  => 'fopen_failed',
            'file'    => $file,
            'mode'    => $mode,
        ];
    }

    $ok = false;
    try {
        @flock($fp, LOCK_EX);
        $w = @fwrite($fp, $line);
        @fflush($fp);
        @flock($fp, LOCK_UN);
        $ok = (is_int($w) && $w > 0);
    } finally {
        @fclose($fp);
    }

    if ($ok) { @chmod($file, 0664); }

    return [
        'enabled' => true,
        'written' => $ok,
        'reason'  => $ok ? 'ok' : 'write_failed',
        'file'    => $file,
        'mode'    => $mode,
    ];
}




/* ---------------- main ---------------- */

PBX_CheckToken();

$in = PBX_ReadInput();
$pbx_event = PBX_NormalizeSipgateEvent($in);

if (PBX_STR($pbx_event['call_id'] ?? '') === '' || PBX_STR($pbx_event['state'] ?? '') === '') {
    PBX_RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'call_id/state required'], 400);
}

// Debug-Kopie (schaltet Verarbeitung NICHT ab)
$rawInfo = PBX_RawStoreWrite('pbx', $pbx_event, $in);

// Processor laden
$processor = CRM_ROOT . '/pbx/crm_process_pbx.php';
if (!is_file($processor)) {
    PBX_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'processor_missing', 'raw_store' => $rawInfo], 500);
}

require_once $processor;

if (!function_exists('PBX_Process')) {
    PBX_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'PBX_Process missing', 'raw_store' => $rawInfo], 500);
}

try {
    $r = PBX_Process($pbx_event);
    if (!is_array($r)) {
        PBX_RESP(['ok' => false, 'error' => 'process_failed', 'message' => 'invalid_process_response', 'raw_store' => $rawInfo], 500);
    }
    if (($r['ok'] ?? false) !== true) {
        PBX_RESP(['ok' => false, 'error' => 'process_failed', 'ctx' => $r, 'raw_store' => $rawInfo], 500);
    }

    PBX_RESP([
        'ok'        => true,
        'processed' => true,
        'writer'    => $r['writer'] ?? null,
        'event_id'  => $r['event_id'] ?? null,
        'raw_store' => $rawInfo,
    ]);
} catch (Throwable $e) {
    PBX_RESP(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'raw_store' => $rawInfo], 500);
}
