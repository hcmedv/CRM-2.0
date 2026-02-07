<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/remote/crm_process_teamviewer.php
 *
 * Zweck:
 * - Liest RAW TeamViewer Poll-Datei (festes RAW-Format)
 * - Baut Patches via rules_teamviewer.php
 * - Upsert in Events-Store via Writer
 *
 * FESTES RAW-Format:
 * {
 *   fetched_at: "...",
 *   request: {...},
 *   data: { records: [ ... ] }
 * }
 *
 * Keine Fallback-Formate.
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
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function TVP_ReadJson(string $file, array $default = []): array
{
    if (!is_file($file)) { return $default; }
    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') { return $default; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}

/* ---------------- Paths ---------------- */

$dataDir = CRM_MOD_PATH($MOD, 'data');
if ($dataDir === '') {
    TVP_Out(['ok' => false, 'error' => 'data_path_missing'], 500);
}

$api = (array)CRM_MOD_CFG($MOD, 'api', []);
$rawFilename = (string)($api['filename_raw'] ?? 'teamviewer_poll_raw.json');

$rawFile = rtrim($dataDir, '/') . '/' . $rawFilename;

/* ---------------- Load RAW (fixed schema) ---------------- */

$raw  = TVP_ReadJson($rawFile, []);
$data = (array)($raw['data'] ?? []);

if (!isset($data['records']) || !is_array($data['records'])) {
    TVP_Log($MOD, 'raw_format_invalid', ['raw_file' => $rawFile, 'keys' => array_keys($raw)]);
    TVP_Out([
        'ok'       => false,
        'error'    => 'raw_format_invalid',
        'raw_file' => $rawFile,
    ], 500);
}

$items   = $data['records'];
$records = count($items);

/* ---------------- Rules ---------------- */

$rulesFile = CRM_ROOT . '/_rules/teamviewer/rules_teamviewer.php';
if (is_file($rulesFile)) {
    require_once $rulesFile;
    TVP_Log($MOD, 'rules_loaded', ['file' => $rulesFile]);
} else {
    TVP_Log($MOD, 'rules_missing', ['file' => $rulesFile]);
    TVP_Out(['ok' => false, 'error' => 'rules_missing', 'file' => $rulesFile], 500);
}

if (!function_exists('RULES_TEAMVIEWER_BuildPatch')) {
    TVP_Out(['ok' => false, 'error' => 'rules_missing_entrypoint'], 500);
}

/* ---------------- Writer ---------------- */

$writerLib = CRM_ROOT . '/_lib/events/crm_events_write.php';
if (!is_file($writerLib)) {
    TVP_Out(['ok' => false, 'error' => 'writer_missing', 'file' => $writerLib], 500);
}
require_once $writerLib;

$writerAvailable = class_exists('CRM_EventGenerator') && method_exists('CRM_EventGenerator', 'upsert');
if (!$writerAvailable) {
    TVP_Out(['ok' => false, 'error' => 'writer_invalid'], 500);
}

TVP_Log($MOD, 'process_start', ['records' => $records, 'raw_file' => $rawFile]);

/* ---------------- Process ---------------- */

$upserts      = 0;
$errors       = 0;
$previewFirstPatch = null;

foreach ($items as $row) {
    if (!is_array($row)) { continue; }

    try {
        $patch = RULES_TEAMVIEWER_BuildPatch($row);

        if ($previewFirstPatch === null) { $previewFirstPatch = $patch; }

        $res = CRM_EventGenerator::upsert('teamviewer', 'remote', $patch);
        if (is_array($res) && (bool)($res['ok'] ?? false)) {
            $upserts++;
        } else {
            $errors++;
        }
    } catch (Throwable $e) {
        $errors++;
        continue;
    }
}

TVP_Log($MOD, 'process_done', [
    'records' => $records,
    'upserts' => $upserts,
    'errors'  => $errors,
]);

TVP_Out([
    'ok'                 => true,
    'writer_available'    => true,
    'records'             => $records,
    'upserts'             => $upserts,
    'errors'              => $errors,
    'preview_first_patch'  => $previewFirstPatch,
]);
