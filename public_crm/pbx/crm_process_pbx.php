<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/pbx/crm_process_pbx.php
 *
 * Zweck:
 * - Verarbeitet eingehende PBX-Events (z. B. Sipgate) in ein CRM-Patch
 * - Optional: Speichert Rohdaten (RAW Store) als Debug-Kopie (settings_pbx.php: pbx.raw_store.*)
 * - Baut Patch via rules_pbx.php und reichert über rules_common.php + rules_enrich.php an
 * - Merged State-Patches (PBX) gegen bestehendes Event (timing) bevor upsert()
 * - Schreibt das Event über den zentralen Writer: CRM_EventGenerator::upsert()
 *
 * Wichtig:
 * - raw_store.enabled ist NUR Debug/Replay/Statistik (Kopie), kein Verarbeitungs-An/Aus.
 *
 * Export:
 * - function PBX_Process(array $pbx): array
 *   Rückgabe: ['ok'=>bool,'event_id'=>string,'written'=>bool,'created'=>bool,'error'=>string,'ctx'=>mixed]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/../../_inc/bootstrap.php';
}

/* ---------------- Writer ---------------- */

require_once CRM_ROOT . '/_lib/events/crm_events_write.php';

/* ---------------- Rules (PBX + Common + Enrich) ---------------- */

$rulesPbx       = CRM_ROOT . '/_rules/pbx/rules_pbx.php';
$rulesCommon    = CRM_ROOT . '/_rules/common/rules_common.php';
$rulesEnrich    = CRM_ROOT . '/_rules/enrich/rules_enrich.php';

if (is_file($rulesPbx)) { require_once $rulesPbx; }
if (is_file($rulesCommon)) { require_once $rulesCommon; }
if (is_file($rulesEnrich)) { require_once $rulesEnrich; }

/* ===================================================================================================================== */

function PBX_Process(array $pbx): array
{
    // -------------------------------------------------
    // 0) RAW Store (optional, Debug-Kopie)
    // -------------------------------------------------
    FN_PBX_RawStoreAppend($pbx);

    // -------------------------------------------------
    // 1) Patch bauen (source-spezifisch)
    // -------------------------------------------------
    if (!function_exists('RULES_PBX_BuildPatch')) {
        return ['ok' => false, 'error' => 'rules_pbx_missing_entrypoint'];
    }

    $patch = RULES_PBX_BuildPatch($pbx);
    if (!is_array($patch)) {
        return ['ok' => false, 'error' => 'rules_pbx_invalid_patch'];
    }

    // -------------------------------------------------
    // 2) Common Normalize (optional)
    // -------------------------------------------------
    if (function_exists('RULES_COMMON_Normalize')) {
        $p = RULES_COMMON_Normalize($patch);
        if (is_array($p)) { $patch = $p; }
    }

    // -------------------------------------------------
    // 3) Enrich (optional)
    // -------------------------------------------------
    if (function_exists('RULES_ENRICH_Apply')) {
        $p = RULES_ENRICH_Apply($patch);
        if (is_array($p)) { $patch = $p; }
    }

    // -------------------------------------------------
    // 3.1) PBX State-Merge: timing nie "weg überschreiben"
    //     - wenn Patch timing leer ist -> entfernen
    //     - wenn Patch timing nur teilweise ist -> mit bestehendem Event mergen
    // -------------------------------------------------
    $patch = FN_PBX_MergeTimingAgainstExistingEvent($patch);

    // -------------------------------------------------
    // 4) Writer (Commit/Validation passiert im Writer)
    // -------------------------------------------------
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

function FN_PBX_MergeTimingAgainstExistingEvent(array $patch): array
{
    $src = (string)($patch['event_source'] ?? '');
    if ($src !== 'pbx') { return $patch; }

    $callId = (string)($patch['meta']['pbx']['call_id'] ?? '');
    $callId = trim($callId);
    if ($callId === '') { return $patch; }

    // leeres timing niemals schreiben (sonst überschreibt es bestehendes timing)
    if (isset($patch['timing'])) {
        if (!is_array($patch['timing']) || count($patch['timing']) === 0) {
            unset($patch['timing']);
            return $patch;
        }
    }

    // wenn patch gar kein timing hat -> nichts zu mergen
    if (!isset($patch['timing']) || !is_array($patch['timing'])) {
        return $patch;
    }

    // existing event suchen
    $existing = FN_PBX_FindEventByCallId($callId);
    if (!is_array($existing)) { return $patch; }

    $base = $existing['timing'] ?? [];
    if (!is_array($base)) { $base = []; }

    $new = $patch['timing'];

    // started_at: nur ergänzen, wenn base fehlt
    if (isset($new['started_at']) && (int)$new['started_at'] > 0) {
        if (!isset($base['started_at']) || (int)$base['started_at'] <= 0) {
            $base['started_at'] = (int)$new['started_at'];
        }
    }

    // ended_at: übernehmen, wenn vorhanden
    if (isset($new['ended_at']) && (int)$new['ended_at'] > 0) {
        $base['ended_at'] = (int)$new['ended_at'];
    }

    // duration neu berechnen wenn möglich
    $sa = (int)($base['started_at'] ?? 0);
    $ea = (int)($base['ended_at'] ?? 0);
    if ($sa > 0 && $ea > 0 && $ea >= $sa) {
        $base['duration_sec'] = $ea - $sa;
    }

    // final: timing setzen oder entfernen
    if (count($base) > 0) {
        $patch['timing'] = $base;
    } else {
        unset($patch['timing']);
    }

    return $patch;
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
    return (array)CRM_MOD_CFG('pbx', 'raw_store', []);
}

function FN_PBX_RawStoreEnabled(): bool
{
    $cfg = FN_PBX_RawStoreCfg();
    return (bool)($cfg['enabled'] ?? false);
}

function FN_PBX_RawStorePath(): string
{
    $cfg = FN_PBX_RawStoreCfg();
    if (!(bool)($cfg['enabled'] ?? false)) {
        return '';
    }

    $baseDir = rtrim((string)CRM_MOD_PATH('pbx', 'data'), '/');
    if ($baseDir === '') {
        return '';
    }

    $subDir = FN_PBX_NormalizeRel((string)($cfg['data_dir'] ?? ''));
    $fn = trim((string)($cfg['filename_current'] ?? 'pbx_raw_current.jsonl'));
    if ($fn === '') { $fn = 'pbx_raw_current.jsonl'; }

    return $baseDir . '/' . ($subDir !== '' ? $subDir . '/' : '') . $fn;
}

function FN_PBX_RawStoreRotateIfTooLarge(string $path, int $maxBytes): void
{
    if ($maxBytes <= 0) { return; }
    if (!is_file($path)) { return; }

    $sz = @filesize($path);
    if (!is_int($sz) || $sz <= $maxBytes) { return; }

    $rot = $path . '.rot.' . date('Ymd_His');
    @rename($path, $rot);
}

function FN_PBX_RawStoreAppend(array $pbx): void
{
    if (!FN_PBX_RawStoreEnabled()) {
        return;
    }

    $cfg  = FN_PBX_RawStoreCfg();
    $path = FN_PBX_RawStorePath();
    if ($path === '') {
        return;
    }

    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if (!FN_PBX_EnsureDir($dir)) {
        return;
    }

    $maxBytes = (int)($cfg['max_bytes'] ?? 0);
    if ($maxBytes > 0) {
        FN_PBX_RawStoreRotateIfTooLarge($path, $maxBytes);
    }

    $line = json_encode($pbx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return;
    }
    $line .= "\n";

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
