<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_lib/events/crm_events_read.php
 *
 * Zentrale Lesebibliothek für events.json
 *
 * Regeln:
 * - KEIN Schreiben
 * - Lock via events.json.lock (LOCK_SH)
 * - Kein Fallback auf "irgendein Event"
 * - Referenzsuche deterministisch
 */


/* ================================================================================================= */
/* ====================================== PUBLIC API ============================================== */
/* ================================================================================================= */

function CRM_Events_ReadAll(): array
{
    $file = CRM_Events_StoreFile();
    return CRM_Events_ReadStoreWithLock($file);
}

function CRM_Events_GetById(string $eventId): ?array
{
    $eventId = trim($eventId);
    if ($eventId === '') { return null; }

    foreach (CRM_Events_ReadAll() as $e) {
        if (!is_array($e)) { continue; }
        if ((string)($e['event_id'] ?? '') === $eventId) {
            return $e;
        }
    }

    return null;
}


/* ------------------------------------------------------------------------------------------------- */
/* -------------------------------------- REFERENZ SUCHE ------------------------------------------ */
/* ------------------------------------------------------------------------------------------------- */

function CRM_Events_GetByRef(string $ns, string $id): ?array
{
    $all = CRM_Events_GetByRefAll($ns, $id);

    if (count($all) === 0) { return null; }

    if (count($all) === 1) {
        return $all[0];
    }

    // Mehrfachtreffer → deterministisch wählen (neuester updated_at)
    usort($all, static function($a, $b) {
        $ta = (int)($a['updated_at'] ?? 0);
        $tb = (int)($b['updated_at'] ?? 0);
        return $tb <=> $ta;
    });

    return $all[0];
}

function CRM_Events_GetByRefAll(string $ns, string $id): array
{
    $ns = trim($ns);
    $id = trim($id);

    if ($ns === '' || $id === '') { return []; }

    $out = [];

    foreach (CRM_Events_ReadAll() as $e) {
        if (!is_array($e)) { continue; }

        $refs = (isset($e['refs']) && is_array($e['refs'])) ? $e['refs'] : [];
        foreach ($refs as $r) {
            if (!is_array($r)) { continue; }

            if (
                (string)($r['ns'] ?? '') === $ns &&
                (string)($r['id'] ?? '') === $id
            ) {
                $out[] = $e;
                break;
            }
        }
    }

    return $out;
}


/* ------------------------------------------------------------------------------------------------- */
/* ------------------------------------------ QUERY ------------------------------------------------ */
/* ------------------------------------------------------------------------------------------------- */

function CRM_Events_Query(array $opts = []): array
{
    $all = CRM_Events_ReadAll();
    if (!$all) { return []; }

    $states  = CRM_Events_ToSet($opts['state'] ?? null);
    $sources = CRM_Events_ToSet($opts['event_source'] ?? null);
    $types   = CRM_Events_ToSet($opts['event_type'] ?? null);

    $out = [];

    foreach ($all as $e) {
        if (!is_array($e)) { continue; }

        $st  = strtolower((string)($e['workflow']['state'] ?? 'open'));
        $src = (string)($e['event_source'] ?? '');
        $typ = (string)($e['event_type'] ?? '');

        if ($states  && !isset($states[$st]))  { continue; }
        if ($sources && !isset($sources[$src])) { continue; }
        if ($types   && !isset($types[$typ]))   { continue; }

        $e['_sort_ts'] = CRM_Events_SortTs($e);
        $out[] = $e;
    }

    usort($out, static function($a,$b){
        return ($b['_sort_ts'] ?? 0) <=> ($a['_sort_ts'] ?? 0);
    });

    foreach ($out as &$r) { unset($r['_sort_ts']); }

    return $out;
}


/* ================================================================================================= */
/* ====================================== INTERNAL ================================================= */
/* ================================================================================================= */

function CRM_Events_StoreFile(): string
{
    $dir = rtrim((string)CRM_MOD_PATH('events','data'), '/');
    $files = (array)CRM_MOD_CFG('events','files',[]);
    $fn = trim((string)($files['filename_store'] ?? 'events.json'));

    return $dir . '/' . ($fn ?: 'events.json');
}

function CRM_Events_ReadStoreWithLock(string $file): array
{
    if (!is_file($file)) { return []; }

    $lock = @fopen($file . '.lock', 'c');
    if ($lock) { @flock($lock, LOCK_SH); }

    try {
        $raw = (string)@file_get_contents($file);
        if ($raw === '') { return []; }

        $json = json_decode($raw, true);
        if (!is_array($json)) { return []; }

        if (CRM_Events_IsList($json)) {
            return $json;
        }

        if (isset($json['events']) && is_array($json['events'])) {
            return $json['events'];
        }

        return [];

    } finally {
        if ($lock) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

function CRM_Events_IsList(array $a): bool
{
    return array_keys($a) === range(0, count($a)-1);
}

function CRM_Events_SortTs(array $e): int
{
    $tim = (isset($e['timing']) && is_array($e['timing'])) ? $e['timing'] : [];

    return
        (int)($tim['started_at'] ?? 0) ?:
        (int)($tim['ended_at'] ?? 0) ?:
        (int)($e['updated_at'] ?? 0) ?:
        (int)($e['created_at'] ?? 0);
}

function CRM_Events_ToSet($v): ?array
{
    if ($v === null) { return null; }

    if (is_string($v)) {
        $v = trim($v);
        return $v !== '' ? [$v => true] : null;
    }

    if (is_array($v)) {
        $s = [];
        foreach ($v as $x) {
            $x = trim((string)$x);
            if ($x !== '') { $s[$x] = true; }
        }
        return $s ?: null;
    }

    return null;
}
