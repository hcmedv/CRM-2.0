<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/remote/crm_process_teamviewer.php
 *
 * Zweck:
 * - Verarbeitet TeamViewer Poll-Daten (festes RAW-Format)
 * - Primär: Payload aus api_teamviewer_read.php (In-Memory)
 * - Fallback (Debug): raw_store Datei, falls vorhanden
 * - Baut Patches via rules_teamviewer.php
 * - Reicht an via rules_common.php + rules_enrich.php (wie PBX)
 * - Upsert in Events-Store via Writer
 *
 * WICHTIG (Touch-Problem):
 * - Abgeschlossene (ended_at) Sessions sollen NICHT bei jedem Poll erneut upsertet werden,
 *   weil sonst updated_at alter Events "hochgezogen" wird.
 *
 * Erkenntnis:
 * - CRM_Events_IndexByRef() existiert nicht (Call to undefined function).
 * - Deshalb KEIN vorab Index-Build, sondern direkte Reader-Abfrage pro Session:
 *     CRM_Events_GetByRef('teamviewer', <session_id>)
 *   Wenn Event existiert UND ended_at identisch ist -> SKIP.
 *
 * DIAG:
 * - Liefert IMMER session_outcomes (upserted/skipped/errored) mit session_id + ref_id + ended_at.
 */

require_once __DIR__ . '/../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$MOD = 'teamviewer';

/* ---------------- Helper ---------------- */

function TVP_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function TVP_IsDebug(string $mod): bool
{
    return (bool)CRM_MOD_CFG($mod, 'debug', false);
}

function TVP_Log(string $mod, string $msg, array $ctx = []): void
{
    if (!TVP_IsDebug($mod)) { return; }

    $dir = CRM_MOD_PATH($mod, 'log');
    if ($dir === '') { return; }
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $file = rtrim($dir, '/') . '/process_' . date('Y-m-d') . '.log';
    $row  = ['ts' => date('c'), 'msg' => $msg, 'ctx' => $ctx];
    @file_put_contents(
        $file,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function TVP_ReadJson(string $file, array $default = []): array
{
    if (!is_file($file)) { return $default; }
    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') { return $default; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}

function TVP_GetRefId(array $patch, string $ns): string
{
    $refs = (isset($patch['refs']) && is_array($patch['refs'])) ? $patch['refs'] : [];
    foreach ($refs as $r) {
        if (!is_array($r)) { continue; }
        if ((string)($r['ns'] ?? '') === $ns) {
            $id = trim((string)($r['id'] ?? ''));
            if ($id !== '') { return $id; }
        }
    }
    return '';
}

/* ---------------- Data Path ---------------- */

$dataBaseDir = CRM_MOD_PATH($MOD, 'data');
if ($dataBaseDir === '') {
    TVP_Out(['ok' => false, 'error' => 'data_path_missing'], 500);
}

/* ---------------- Load RAW (fixed schema) ---------------- */

$raw     = [];
$rawFile = null;

if (isset($GLOBALS['CRM_TV_POLL_PAYLOAD']) && is_array($GLOBALS['CRM_TV_POLL_PAYLOAD'])) {
    $raw = (array)$GLOBALS['CRM_TV_POLL_PAYLOAD'];
} else {
    $rawStore = (array)CRM_MOD_CFG($MOD, 'raw_store', []);
    $fileName = (string)($rawStore['filename_current'] ?? 'teamviewer_raw_current.json');

    $rawFile = rtrim($dataBaseDir, '/') . '/' . $fileName;
    $raw = TVP_ReadJson($rawFile, []);
}

$data = (array)($raw['data'] ?? []);

if (!isset($data['records']) || !is_array($data['records'])) {
    TVP_Log($MOD, 'raw_format_invalid', [
        'raw_file'    => $rawFile,
        'has_globals' => isset($GLOBALS['CRM_TV_POLL_PAYLOAD']),
        'keys'        => is_array($raw) ? array_keys($raw) : [],
        'data_keys'   => is_array($data) ? array_keys($data) : [],
    ]);

    TVP_Out([
        'ok'       => false,
        'error'    => 'raw_format_invalid',
        'raw_file' => $rawFile,
    ], 500);
}

$items   = $data['records'];
$records = count($items);

/* ---------------- Rules (TeamViewer + Common + Enrich) ---------------- */

$rulesTeamviewer = CRM_ROOT . '/_rules/teamviewer/rules_teamviewer.php';
$rulesCommon     = CRM_ROOT . '/_rules/common/rules_common.php';
$rulesEnrich     = CRM_ROOT . '/_rules/enrich/rules_enrich.php';

if (!is_file($rulesTeamviewer)) {
    TVP_Log($MOD, 'rules_missing', ['file' => $rulesTeamviewer]);
    TVP_Out(['ok' => false, 'error' => 'rules_missing', 'file' => $rulesTeamviewer], 500);
}

require_once $rulesTeamviewer;
if (is_file($rulesCommon)) { require_once $rulesCommon; }
if (is_file($rulesEnrich)) { require_once $rulesEnrich; }

if (!function_exists('RULES_TEAMVIEWER_BuildPatch')) {
    TVP_Out(['ok' => false, 'error' => 'rules_missing_entrypoint'], 500);
}

/* ---------------- Reader (Read-Only) ---------------- */

$readerLib = CRM_ROOT . '/_lib/events/crm_events_read.php';
if (!is_file($readerLib)) {
    TVP_Out(['ok' => false, 'error' => 'reader_missing', 'file' => $readerLib], 500);
}
require_once $readerLib;

if (!function_exists('CRM_Events_GetByRef')) {
    TVP_Out(['ok' => false, 'error' => 'reader_missing_getbyref'], 500);
}

/* ---------------- Writer ---------------- */

$writerLib = CRM_ROOT . '/_lib/events/crm_events_write.php';
if (!is_file($writerLib)) {
    TVP_Out(['ok' => false, 'error' => 'writer_missing', 'file' => $writerLib], 500);
}
require_once $writerLib;

if (!class_exists('CRM_EventGenerator') || !method_exists('CRM_EventGenerator', 'upsert')) {
    TVP_Out(['ok' => false, 'error' => 'writer_invalid'], 500);
}

/* ---------------- Process ---------------- */

$upserts = 0;
$errors  = 0;
$skips   = 0;

$previewFirstPatch = null;

$sessionOutcomes = [
    'upserted' => [],
    'skipped'  => [],
    'errored'  => [],
];

foreach ($items as $row) {
    if (!is_array($row)) { continue; }

    $rowId = (string)($row['id'] ?? '');

    try {
        $patch = RULES_TEAMVIEWER_BuildPatch($row);

        // Common Normalize (optional)
        if (function_exists('RULES_COMMON_Normalize')) {
            $p = RULES_COMMON_Normalize($patch);
            if (is_array($p)) { $patch = $p; }
        }

        // Enrich (optional)
        if (function_exists('RULES_ENRICH_Apply')) {
            $p = RULES_ENRICH_Apply($patch);
            if (is_array($p)) { $patch = $p; }
        }

        if ($previewFirstPatch === null) {
            $previewFirstPatch = $patch;
        }

        $sid      = TVP_GetRefId($patch, 'teamviewer');            // fachliche Session-ID (Ref)
        $endPatch = (int)($patch['timing']['ended_at'] ?? 0);

        // Skip-Logik: existiert + ended_at identisch (keine updated_at-Touches bei fertigen Sessions)
        if ($sid !== '' && $endPatch > 0) {
            $existingEvent = null;

            try {
                $existingEvent = CRM_Events_GetByRef('teamviewer', $sid);
            } catch (Throwable $e) {
                $existingEvent = null;
                TVP_Log($MOD, 'reader_getbyref_failed', ['sid' => $sid, 'message' => $e->getMessage()]);
            }

            if (is_array($existingEvent)) {
                $endExisting = 0;
                if (isset($existingEvent['timing']) && is_array($existingEvent['timing'])) {
                    $endExisting = (int)($existingEvent['timing']['ended_at'] ?? 0);
                }

                if ($endExisting > 0 && $endExisting === $endPatch) {
                    $skips++;
                    $sessionOutcomes['skipped'][] = [
                        'session_id' => $rowId,
                        'ref_id'     => $sid,
                        'ended_at'   => $endPatch,
                        'event_id'   => (string)($existingEvent['event_id'] ?? ''),
                        'reason'     => 'ended_at_unchanged',
                    ];
                    continue;
                }
            }
        }

        $res = CRM_EventGenerator::upsert('teamviewer', 'remote', $patch);

        if (is_array($res) && ($res['ok'] ?? false)) {
            $upserts++;
            $sessionOutcomes['upserted'][] = [
                'session_id' => $rowId,
                'ref_id'     => $sid,
                'ended_at'   => $endPatch,
                'event_id'   => (string)($res['event_id'] ?? ''),
                'written'    => (bool)($res['written'] ?? false),
                'is_new'     => (bool)($res['is_new'] ?? false),
            ];
        } else {
            $errors++;
            $sessionOutcomes['errored'][] = [
                'session_id' => $rowId,
                'ref_id'     => $sid,
                'ended_at'   => $endPatch,
                'error'      => is_array($res) ? (string)($res['error'] ?? 'writer_failed') : 'writer_failed',
                'message'    => is_array($res) ? (string)($res['message'] ?? '') : '',
            ];
        }

    } catch (Throwable $e) {
        $errors++;
        $sessionOutcomes['errored'][] = [
            'session_id' => $rowId,
            'ref_id'     => '',
            'ended_at'   => 0,
            'error'      => 'exception',
            'message'    => $e->getMessage(),
        ];
        continue;
    }
}

$out = [
    'ok'                  => true,
    'records'             => $records,
    'upserts'             => $upserts,
    'skips'               => $skips,
    'errors'              => $errors,
    'session_outcomes'    => $sessionOutcomes,
    'preview_first_patch' => $previewFirstPatch,
];

TVP_Out($out);
