<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/pbx/crm_process_pbx.php
 *
 * Zweck:
 * - Verarbeitet eingehende PBX-Events (z. B. Sipgate) in ein CRM-Patch
 * - Optional: Speichert Rohdaten (RAW Store) als Debug-Kopie
 * - Baut Patch via rules_pbx.php und reichert über rules_common.php + rules_enrich.php an
 * - Merged State-Patches (PBX) gegen bestehendes Event (timing)
 * - Schreibt das Event über den zentralen Writer
 *
 * Zusätzlich (NEU):
 * - Setzt User-Status pbx_busy (true/false) über crm_status.php
 *   Mapping:
 *   - Primär: angerufene Nummer (pbx.to) → User via mitarbeiter.json (pbx.to_numbers)
 *   - Fallback (alt): Extension (e0/e5/…) → User via mitarbeiter.json (cti.profiles.*.caller)
 */

/*
error_reporting(E_ALL);
ini_set('display_errors', '1');
*/

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/../../_inc/bootstrap.php';
}

/* ---------------- Writer ---------------- */
require_once CRM_ROOT . '/_lib/events/crm_events_write.php';

/* ---------------- Status (User Presence) ---------------- */
require_once CRM_ROOT . '/login/crm_status.php';

/* ---------------- Rules ---------------- */
$rulesPbx    = CRM_ROOT . '/_rules/pbx/rules_pbx.php';
$rulesCommon = CRM_ROOT . '/_rules/common/rules_common.php';
$rulesEnrich = CRM_ROOT . '/_rules/enrich/rules_enrich.php';

if (is_file($rulesPbx))    { require_once $rulesPbx; }
if (is_file($rulesCommon)) { require_once $rulesCommon; }
if (is_file($rulesEnrich)) { require_once $rulesEnrich; }

/* ===================================================================================================================== */

function PBX_Process(array $pbx): array
{
    // 0) RAW Store (optional)
    FN_PBX_RawStoreAppend($pbx);

    // 0.1) User aus PBX ableiten + Busy setzen (vor Writer)
    $pbx = PBX_AnnotatePbxWithAgent($pbx);
    PBX_UpdateUserBusyFromPbx($pbx);

    // 1) Patch bauen
    if (!function_exists('RULES_PBX_BuildPatch')) {
        return ['ok' => false, 'error' => 'rules_pbx_missing_entrypoint'];
    }

    $patch = RULES_PBX_BuildPatch($pbx);
    if (!is_array($patch)) {
        return ['ok' => false, 'error' => 'rules_pbx_invalid_patch'];
    }

    // 1.1) PBX-Agent in Patch (meta.pbx) spiegeln, damit es im Event landet
    $patch = PBX_ApplyAgentToPatch($patch, $pbx);

    // 2) Common Normalize
    if (function_exists('RULES_COMMON_Normalize')) {
        $p = RULES_COMMON_Normalize($patch);
        if (is_array($p)) { $patch = $p; }
    }

    // 3) Enrich
    if (function_exists('RULES_ENRICH_Apply')) {
        $p = RULES_ENRICH_Apply($patch);
        if (is_array($p)) { $patch = $p; }
    }

    // 3.1) Timing-Merge
    $patch = FN_PBX_MergeTimingAgainstExistingEvent($patch);

    // 4) Writer
// 4) Writer
try {
    if (!class_exists('CRM_EventGenerator')) {
        return ['ok' => false, 'error' => 'writer_class_missing'];
    }

    $r = CRM_EventGenerator::upsert('pbx', 'call', $patch);

    if (!is_array($r) || ($r['ok'] ?? false) !== true) {

        // Fail-Log (PBX) – damit Writer/Validator-Fehler sichtbar werden
        $logDir = rtrim((string)CRM_MOD_PATH('pbx', 'log'), '/');
        if ($logDir !== '') {
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }

            $file = $logDir . '/process_' . date('Y-m-d') . '.log';

            $callId = (string)($patch['meta']['pbx']['call_id'] ?? '');
            $callId = trim($callId);

            $row = [
                'ts'      => date('c'),
                'level'   => 'error',
                'msg'     => 'writer_failed',
                'error'   => (string)($r['error'] ?? 'writer_failed'),
                'call_id' => $callId,
                'ctx'     => $r,
                'patch'   => [
                    'event_source' => (string)($patch['event_source'] ?? ''),
                    'event_type'   => (string)($patch['event_type'] ?? ''),
                    'has_workflow' => (bool)(isset($patch['workflow']) && is_array($patch['workflow'])),
                    'workflow'     => (array)($patch['workflow'] ?? []),
                    'has_timing'   => (bool)(isset($patch['timing']) && is_array($patch['timing'])),
                    'timing'       => (array)($patch['timing'] ?? []),
                    'refs'         => (array)($patch['refs'] ?? []),
                ],
            ];

            @file_put_contents(
                $file,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        }

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

        // Exception-Log (PBX)
        $logDir = rtrim((string)CRM_MOD_PATH('pbx', 'log'), '/');
        if ($logDir !== '') {
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }

            $file = $logDir . '/process_' . date('Y-m-d') . '.log';

            $callId = (string)($patch['meta']['pbx']['call_id'] ?? '');
            $callId = trim($callId);

            $row = [
                'ts'      => date('c'),
                'level'   => 'error',
                'msg'     => 'writer_exception',
                'call_id' => $callId,
                'type'    => get_class($e),
                'message' => $e->getMessage(),
            ];

            @file_put_contents(
                $file,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        }

        return ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()];
    }

}

/* ===================================================================================================================== */
/* ============================== USER STATUS (PBX → pbx_busy) ======================================================== */

function PBX_ReadMitarbeiter(): array
{
    $file = CRM_BASE . '/data/login/mitarbeiter.json';
    if (!is_file($file)) { return []; }
    $j = json_decode((string)file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

function PBX_NormPhone(string $s): string
{
    $s = trim((string)$s);
    if ($s === '') { return ''; }

    // keep digits only
    $d = preg_replace('/\D+/', '', $s);
    $d = is_string($d) ? $d : '';

    // normalize leading 00 -> (empty), + is already removed above
    if (strpos($d, '00') === 0) { $d = substr($d, 2); }

    return $d;
}

function PBX_UserMatchesToNumber(array $u, string $num): bool
{
    $num = PBX_NormPhone($num);
    if ($num === '') { return false; }

    $pbx = (array)($u['pbx'] ?? []);
    $toNumbers = (array)($pbx['to_numbers'] ?? []);

    foreach ($toNumbers as $n) {
        if (PBX_NormPhone((string)$n) === $num) { return true; }
    }
    return false;
}

function PBX_FindUserByToNumber(string $num): ?array
{
    $num = PBX_NormPhone($num);
    if ($num === '') { return null; }

    $j = PBX_ReadMitarbeiter();
    foreach ($j as $u) {
        if (!is_array($u)) { continue; }
        $user = (string)($u['user'] ?? '');
        if ($user === '') { continue; }

        if (PBX_UserMatchesToNumber($u, $num)) { return $u; }
    }

    return null;
}

/**
 * (Alt) Ermittelt den betroffenen User anhand der PBX-Extension (e0/e5/…).
 * Quelle: /data/login/mitarbeiter.json → cti.profiles.*.caller
 */
function PBX_MapExtensionToUser(string $ext): ?string
{
    $ext = trim($ext);
    if ($ext === '') { return null; }

    $j = PBX_ReadMitarbeiter();
    if (!is_array($j)) { return null; }

    foreach ($j as $u) {
        if (!is_array($u)) { continue; }
        $user = (string)($u['user'] ?? '');
        if ($user === '') { continue; }

        $profiles = (array)($u['cti']['profiles'] ?? []);
        foreach ($profiles as $p) {
            if (!is_array($p)) { continue; }
            if (trim((string)($p['caller'] ?? '')) === $ext) {
                return $user;
            }
        }
    }

    return null;
}

/**
 * Ermittelt User/Agent aus PBX-Event.
 * Priorität:
 * 1) pbx.to match gegen mitarbeiter.pbx.to_numbers
 * 2) (optional) bei outbound: pbx.from match gegen mitarbeiter.pbx.to_numbers
 * 3) Fallback: Extension (e0/e5/…) gegen cti.profiles.*.caller
 */
function PBX_MapPbxToUser(array $pbx): ?array
{
    $to   = (string)($pbx['to'] ?? '');
    $from = (string)($pbx['from'] ?? '');

    $u = PBX_FindUserByToNumber($to);
    if (is_array($u)) { return $u; }

    // outbound: wenn jemand von einer bekannten Nummer rauswählt
    $u = PBX_FindUserByToNumber($from);
    if (is_array($u)) { return $u; }

    // fallback: extension mapping (alt)
    $ext = (string)(
        $pbx['device'] ??
        $pbx['extension'] ??
        $pbx['caller'] ??
        ''
    );
    $ext = trim($ext);
    if ($ext !== '') {
        $user = PBX_MapExtensionToUser($ext);
        if ($user !== null) {
            $j = PBX_ReadMitarbeiter();
            foreach ($j as $uu) {
                if (!is_array($uu)) { continue; }
                if ((string)($uu['user'] ?? '') === $user) { return $uu; }
            }
        }
    }

    return null;
}

function PBX_AnnotatePbxWithAgent(array $pbx): array
{
    $u = PBX_MapPbxToUser($pbx);
    if (!is_array($u)) { return $pbx; }

    $pbx['agent_user'] = (string)($u['user'] ?? '');
    $pbx['agent_name'] = (string)($u['name'] ?? '');

    // optional: answeringNumber nur als Zusatzinfo (wenn vorhanden)
    $ans = (string)($pbx['answering_number'] ?? ($pbx['answeringNumber'] ?? ''));
    if (trim($ans) !== '') { $pbx['answered_via_number'] = $ans; }

    return $pbx;
}

/**
 * Spiegel Agent-Infos in Patch (meta.pbx.*), ohne RULES_PBX ändern zu müssen.
 */
function PBX_ApplyAgentToPatch(array $patch, array $pbx): array
{
    $agentUser = trim((string)($pbx['agent_user'] ?? ''));
    $agentName = trim((string)($pbx['agent_name'] ?? ''));
    $ansVia    = trim((string)($pbx['answered_via_number'] ?? ''));

    if (!isset($patch['meta']) || !is_array($patch['meta'])) { $patch['meta'] = []; }
    if (!isset($patch['meta']['pbx']) || !is_array($patch['meta']['pbx'])) { $patch['meta']['pbx'] = []; }

    if ($agentUser !== '') { $patch['meta']['pbx']['agent_user'] = $agentUser; }
    if ($agentName !== '') { $patch['meta']['pbx']['agent_name'] = $agentName; }
    if ($ansVia !== '')    { $patch['meta']['pbx']['answered_via_number'] = $ansVia; }

    // auch die Zielnummer als Grundlage dokumentieren (für spätere Auswertung)
    $to = trim((string)($pbx['to'] ?? ''));
    if ($to !== '') { $patch['meta']['pbx']['to_number'] = $to; }

    return $patch;
}

/**
 * Setzt pbx_busy abhängig vom Call-State.
 * - newcall/ringing/answer/answered/active → true
 * - hangup/ended/call_end/completed        → false
 *
 * Mapping-Quelle:
 * - Primär: pbx.to_numbers (mitarbeiter.json)
 * - Fallback: Extension cti.profiles.*.caller
 */
function PBX_UpdateUserBusyFromPbx(array $pbx): void
{
    $user = trim((string)($pbx['agent_user'] ?? ''));
    if ($user === '') { return; }

    $state = strtolower((string)($pbx['state'] ?? $pbx['event'] ?? ''));
    $state = trim($state);
    if ($state === '') { return; }

    $busyTrue  = ['start','ringing','answered','answer','active','call_start','newcall','new_call','new-call'];
    $busyFalse = ['hangup','ended','call_end','completed'];

    if (in_array($state, $busyTrue, true)) {
        CRM_Status_UpdateUser($user, [
            'pbx_busy'   => true,
            'updated_at' => date('c'),
        ]);
        return;
    }

    if (in_array($state, $busyFalse, true)) {
        CRM_Status_UpdateUser($user, [
            'pbx_busy'   => false,
            'updated_at' => date('c'),
        ]);
        return;
    }
}

/* ===================================================================================================================== */
/* ============================== TIMING / RAW / HELPERS =============================================================== */

function FN_PBX_MergeTimingAgainstExistingEvent(array $patch): array
{
    $src = (string)($patch['event_source'] ?? '');
    if ($src !== 'pbx') { return $patch; }

    $callId = (string)($patch['meta']['pbx']['call_id'] ?? '');
    $callId = trim($callId);
    if ($callId === '') { return $patch; }

    if (isset($patch['timing'])) {
        if (!is_array($patch['timing']) || count($patch['timing']) === 0) {
            unset($patch['timing']);
            return $patch;
        }
    }

    if (!isset($patch['timing']) || !is_array($patch['timing'])) {
        return $patch;
    }

    $existing = FN_PBX_FindEventByCallId($callId);
    if (!is_array($existing)) { return $patch; }

    $base = is_array($existing['timing'] ?? null) ? $existing['timing'] : [];
    $new  = $patch['timing'];

    if (isset($new['started_at']) && (int)$new['started_at'] > 0) {
        if (!isset($base['started_at']) || (int)$base['started_at'] <= 0) {
            $base['started_at'] = (int)$new['started_at'];
        }
    }

    if (isset($new['ended_at']) && (int)$new['ended_at'] > 0) {
        $base['ended_at'] = (int)$new['ended_at'];
    }

    $sa = (int)($base['started_at'] ?? 0);
    $ea = (int)($base['ended_at'] ?? 0);
    if ($sa > 0 && $ea > 0 && $ea >= $sa) {
        $base['duration_sec'] = $ea - $sa;
    }

    if (count($base) > 0) {
        $patch['timing'] = $base;
    } else {
        unset($patch['timing']);
    }

    return $patch;
}

/* ---- RAW Store & Finder (unverändert) ---- */

function FN_PBX_NormalizeRel(string $s): string
{
    $s = trim(str_replace('\\', '/', (string)$s));
    return trim($s, '/');
}

function FN_PBX_EnsureDir(string $dir): bool
{
    if ($dir === '') { return false; }
    if (is_dir($dir)) { return true; }
    @mkdir($dir, 0775, true);
    return is_dir($dir);
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
    if (!(bool)($cfg['enabled'] ?? false)) { return ''; }

    $baseDir = rtrim((string)CRM_MOD_PATH('pbx', 'data'), '/');
    if ($baseDir === '') { return ''; }

    $subDir = FN_PBX_NormalizeRel((string)($cfg['data_dir'] ?? ''));
    $fn = trim((string)($cfg['filename_current'] ?? 'pbx_raw_current.jsonl'));
    if ($fn === '') { $fn = 'pbx_raw_current.jsonl'; }

    return $baseDir . '/' . ($subDir !== '' ? $subDir . '/' : '') . $fn;
}

function FN_PBX_RawStoreRotateIfTooLarge(string $path, int $maxBytes): void
{
    if ($maxBytes <= 0 || !is_file($path)) { return; }
    $sz = @filesize($path);
    if (!is_int($sz) || $sz <= $maxBytes) { return; }
    @rename($path, $path . '.rot.' . date('Ymd_His'));
}

function FN_PBX_RawStoreAppend(array $pbx): void
{
    if (!FN_PBX_RawStoreEnabled()) { return; }

    $cfg  = FN_PBX_RawStoreCfg();
    $path = FN_PBX_RawStorePath();
    if ($path === '') { return; }

    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if (!FN_PBX_EnsureDir($dir)) { return; }

    $maxBytes = (int)($cfg['max_bytes'] ?? 0);
    if ($maxBytes > 0) {
        FN_PBX_RawStoreRotateIfTooLarge($path, $maxBytes);
    }

    $line = json_encode($pbx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') { return; }
    $line .= "\n";

    $fp = @fopen($path, 'ab');
    if ($fp === false) { return; }
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
    $fn = trim((string)($files['filename_store'] ?? 'events.json'));
    if ($fn === '') { $fn = 'events.json'; }

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
        foreach ((array)($e['refs'] ?? []) as $r) {
            if (!is_array($r)) { continue; }
            if ((string)($r['ns'] ?? '') === 'pbx' && (string)($r['id'] ?? '') === $callId) {
                return $e;
            }
        }
    }
    return null;
}

function PBX_MapSipgateUserToCrmUser(?string $sipgateUserId, ?string $sipgateUserName): ?string
{
    $sipgateUserId   = trim((string)$sipgateUserId);
    $sipgateUserName = trim((string)$sipgateUserName);

    $file = CRM_BASE . '/data/login/mitarbeiter.json';
    if (!is_file($file)) { return null; }

    $j = json_decode((string)file_get_contents($file), true);
    if (!is_array($j)) { return null; }

    foreach ($j as $u) {
        if (!is_array($u)) { continue; }
        $user = trim((string)($u['user'] ?? ''));
        if ($user === '') { continue; }

        // optional: wenn du später pbx.user_ids pflegst (best practice)
        $ids = $u['pbx']['user_ids'] ?? null;
        if ($sipgateUserId !== '' && is_array($ids)) {
            foreach ($ids as $id) {
                if (trim((string)$id) === $sipgateUserId) { return $user; }
            }
        }

        // Fallback: Name match
        if ($sipgateUserName !== '') {
            $name = trim((string)($u['name'] ?? ''));
            if ($name !== '' && strcasecmp($name, $sipgateUserName) === 0) {
                return $user;
            }
        }
    }

    return null;
}
