<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/pbx/crm_process_pbx.php
 *
 * Zweck:
 * - Verarbeitet eingehende PBX-Events (z. B. Sipgate) in ein CRM-Patch
 * - Optional: Speichert Rohdaten (RAW Store) unter /data/pbx/... (settings_pbx.php: pbx.raw_store.*)
 * - Schreibt das Event über den zentralen Writer: CRM_EventGenerator::upsert()
 *
 * Export:
 * - function PBX_Process(array $pbx): array
 *   Rückgabe: ['ok'=>bool,'event_id'=>string,'written'=>bool,'created'=>bool,'error'=>string,'ctx'=>mixed]
 */

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/../../_inc/bootstrap.php';
}

require_once CRM_ROOT . '/_lib/events/crm_events_write.php';

function PBX_Process(array $pbx): array
{
    // -------------------------------------------------
    // 0) RAW Store (optional)
    // -------------------------------------------------
    FN_PBX_RawStoreAppend($pbx);

    // ---------------- basics / normalize ----------------
    $provider = strtolower(trim((string)($pbx['provider'] ?? $pbx['raw']['provider'] ?? 'sipgate')));

    // call id: sipgate liefert oft "callId" / "origCallId"
    $callId = trim((string)($pbx['call_id'] ?? $pbx['callId'] ?? $pbx['origCallId'] ?? ''));
    if ($callId === '' && isset($pbx['raw']) && is_array($pbx['raw'])) {
        $callId = trim((string)($pbx['raw']['call_id'] ?? $pbx['raw']['callId'] ?? $pbx['raw']['origCallId'] ?? ''));
    }

    $from      = trim((string)($pbx['from'] ?? ''));
    $to        = trim((string)($pbx['to'] ?? ''));
    $direction = strtolower(trim((string)($pbx['direction'] ?? '')));
    $state     = strtolower(trim((string)($pbx['state'] ?? $pbx['event'] ?? '')));

    $receivedAtIso = trim((string)($pbx['received_at'] ?? ''));
    $receivedAtTs  = FN_PBX_IsoToTs($receivedAtIso);

    $timing = $pbx['timing'] ?? [];
    if (!is_array($timing)) { $timing = []; }

    $startedAtIn = (int)($timing['started_at'] ?? 0);
    $endedAtIn   = (int)($timing['ended_at'] ?? 0);

    // ---------------- existing event timing (preserve) ----------------
    $existing = null;
    if ($callId !== '') {
        $existing = FN_PBX_FindEventByCallId($callId);
    }

    $startedAt = $startedAtIn;
    $endedAt   = $endedAtIn;

    // started_at: wenn Input leer -> aus bestehendem Event nehmen
    if ($startedAt <= 0) {
        $startedAt = (int)FN_PBX_ArrGet($existing, ['timing','started_at']);
    }

    // fallback started_at: received_at (newcall) oder jetzt
    if ($startedAt <= 0) {
        $startedAt = ($receivedAtTs > 0) ? $receivedAtTs : time();
    }

    // ended_at:
    // - wenn Input vorhanden -> nehmen
    // - bei hangup: received_at bevorzugen (oder existing)
    if ($endedAt <= 0) {
        if ($state === 'hangup') {
            $endedAt = ($receivedAtTs > 0) ? $receivedAtTs : (int)FN_PBX_ArrGet($existing, ['timing','ended_at']);
        } else {
            $endedAt = (int)FN_PBX_ArrGet($existing, ['timing','ended_at']);
        }
    }

    if ($endedAt <= 0) {
        // solange Call nicht beendet ist, minimal 1s Fenster
        $endedAt = $startedAt + 1;
    }

    if ($endedAt < $startedAt) {
        $endedAt = $startedAt + 1;
    }

    $duration = max(1, $endedAt - $startedAt);

    // ---------------- display ----------------
    $dirLabel = ($direction === 'in') ? 'In' : (($direction === 'out') ? 'Out' : 'Call');
    $peer     = ($direction === 'in') ? $from : (($direction === 'out') ? $to : ($from !== '' ? $from : $to));
    $peer     = ($peer !== '') ? $peer : 'unbekannt';

    $title = "PBX: {$dirLabel} {$peer}";

    $subtitleParts = [];
    if ($provider !== '') { $subtitleParts[] = strtoupper($provider); }
    if ($state !== '')    { $subtitleParts[] = $state; }
    $subtitle = implode(' · ', $subtitleParts);

    $tags = [];
    if ($provider !== '')  { $tags[] = $provider; }
    if ($direction !== '') { $tags[] = $direction; }
    if ($state !== '')     { $tags[] = $state; }

    // ---------------- patch ----------------
    $refId = ($callId !== '')
        ? $callId
        : sha1($provider . '|' . $from . '|' . $to . '|' . $startedAt);

    $patch = [
        'event_source' => 'pbx',
        'event_type'   => 'call',

        'display'      => [
            'title'    => $title,
            'subtitle' => $subtitle,
            'tags'     => array_values(array_unique(array_filter($tags))),
        ],

        'timing'       => [
            'started_at'   => $startedAt,
            'ended_at'     => $endedAt,
            'duration_sec' => $duration,
        ],

        'refs'         => [
            [
                'ns' => 'pbx',
                'id' => $refId,
            ],
        ],

        'meta'         => [
            'pbx' => [
                'provider'    => $provider,
                'call_id'     => $refId, // Pflichtfeld für Validator (settings_pbx.php)
                'state'       => $state,
                'received_at' => ($receivedAtIso !== '' ? $receivedAtIso : ($receivedAtTs > 0 ? date('c', $receivedAtTs) : date('c'))),
                'raw'         => $pbx,
            ],
        ],
    ];

    try {
        if (!class_exists('CRM_EventGenerator')) {
            return ['ok' => false, 'error' => 'writer_class_missing'];
        }

        $r = CRM_EventGenerator::upsert('pbx', 'call', $patch);

        if (!is_array($r) || ($r['ok'] ?? false) !== true) {
            return [
                'ok'    => false,
                'error' => (string)($r['error'] ?? 'writer_failed'),
                'ctx'   => $r,
            ];
        }

        $evt = $r['event'] ?? null;
        $eventId = is_array($evt) ? (string)($evt['event_id'] ?? '') : '';

        return [
            'ok'       => true,
            'event_id' => $eventId,
            'written'  => (bool)($r['written'] ?? true),
            'created'  => (bool)($r['is_new'] ?? false),
        ];

    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }
}

/* ===================================================================================================================== */

function FN_PBX_IsoToTs(string $iso): int
{
    $iso = trim($iso);
    if ($iso === '') { return 0; }
    $ts = strtotime($iso);
    return ($ts === false) ? 0 : (int)$ts;
}

function FN_PBX_ArrGet($a, array $path)
{
    if (!is_array($a)) { return null; }
    $ref = $a;
    foreach ($path as $k) {
        if (!is_array($ref) || !array_key_exists($k, $ref)) { return null; }
        $ref = $ref[$k];
    }
    return $ref;
}

function FN_PBX_EnsureDir(string $dir): bool
{
    if ($dir === '') { return false; }
    if (is_dir($dir)) { return true; }
    @mkdir($dir, 0775, true);
    return is_dir($dir);
}

function FN_PBX_NormalizeRel(string $s): string
{
    $s = trim(str_replace('\\', '/', (string)$s));
    $s = trim($s, '/');
    return $s;
}

function FN_PBX_RawStoreCfg(): array
{
    $cfg = (array)CRM_MOD_CFG('pbx', 'raw_store', []);
    return $cfg;
}

function FN_PBX_RawStoreEnabled(): bool
{
    $cfg = FN_PBX_RawStoreCfg();
    return (bool)($cfg['enabled'] ?? false);
}

function FN_PBX_RawStorePath(): string
{
    $cfg = (array)CRM_MOD_CFG('pbx', 'raw_store', []);
    if (!(bool)($cfg['enabled'] ?? false)) {
        return '';
    }

    // KORREKT: Modulpfad ist bereits /data/pbx
    $baseDir = rtrim((string)CRM_MOD_PATH('pbx', 'data'), '/');
    if ($baseDir === '') {
        return '';
    }

    $fn = trim((string)($cfg['filename_current'] ?? 'pbx_raw_current.jsonl'));
    if ($fn === '') {
        $fn = 'pbx_raw_current.jsonl';
    }

    return $baseDir . '/' . $fn;
}


function FN_PBX_RawStoreAppend(array $pbx): void
{
    if (!FN_PBX_RawStoreEnabled()) {
        return;
    }

    $path = FN_PBX_RawStorePath();
    if ($path === '') {
        return;
    }

    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if (!FN_PBX_EnsureDir($dir)) {
        return;
    }

    // JSONL line (eine Zeile pro Event) – robust & append-only
    $line = json_encode($pbx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return;
    }
    $line .= "\n";

    // Append mit LOCK
    $fp = @fopen($path, 'ab');
    if ($fp === false) {
        return;
    }

    try {
        @flock($fp, LOCK_EX);
        @fwrite($fp, $line);
        @fflush($fp);
        @flock($fp, LOCK_UN);
    } finally {
        @fclose($fp);
    }
}

function FN_PBX_LoadEventsStoreFile(): string
{
    $dir = rtrim((string)CRM_MOD_PATH('events', 'data'), '/');
    if ($dir === '') { return ''; }

    $files = (array)CRM_MOD_CFG('events', 'files', []);
    $fn = (string)($files['filename_store'] ?? 'events.json');
    $fn = trim($fn) !== '' ? $fn : 'events.json';

    return $dir . '/' . $fn;
}

function FN_PBX_FindEventByCallId(string $callId): ?array
{
    $callId = trim($callId);
    if ($callId === '') { return null; }

    $path = FN_PBX_LoadEventsStoreFile();
    if ($path === '' || !is_file($path)) { return null; }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') { return null; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return null; }

    foreach ($j as $e) {
        if (!is_array($e)) { continue; }
        $refs = $e['refs'] ?? null;
        if (!is_array($refs)) { continue; }

        foreach ($refs as $r) {
            if (!is_array($r)) { continue; }
            if ((string)($r['ns'] ?? '') === 'pbx' && (string)($r['id'] ?? '') === $callId) {
                return $e;
            }
        }
    }

    return null;
}
