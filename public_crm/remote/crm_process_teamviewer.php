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
 * - Upsert in Events-Store via Writer
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
        FILE_APPEND
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

/* ---------------- Data Path ---------------- */

$dataBaseDir = CRM_MOD_PATH($MOD, 'data');
if ($dataBaseDir === '') {
    TVP_Out(['ok' => false, 'error' => 'data_path_missing'], 500);
}

/* ---------------- Load RAW (fixed schema) ---------------- */

// Primär: In-Memory Payload aus api_teamviewer_read.php
$raw     = [];
$rawFile = null;

if (isset($GLOBALS['CRM_TV_POLL_PAYLOAD']) && is_array($GLOBALS['CRM_TV_POLL_PAYLOAD'])) {
    $raw = (array)$GLOBALS['CRM_TV_POLL_PAYLOAD'];
} else {
    // Fallback (Debug): Datei aus raw_store.* (nur wenn vorhanden)
    $rawStore = (array)CRM_MOD_CFG($MOD, 'raw_store', []);
    $fileName = (string)($rawStore['filename_current'] ?? 'teamviewer_raw_current.json');

    // CRM_MOD_PATH('teamviewer','data') ist bereits /data/teamviewer/
    // => kein data_dir nochmals anhängen
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

/* ---------------- Rules ---------------- */

$rulesFile = CRM_ROOT . '/_rules/teamviewer/rules_teamviewer.php';
if (!is_file($rulesFile)) {
    TVP_Log($MOD, 'rules_missing', ['file' => $rulesFile]);
    TVP_Out(['ok' => false, 'error' => 'rules_missing', 'file' => $rulesFile], 500);
}

require_once $rulesFile;

if (!function_exists('RULES_TEAMVIEWER_BuildPatch')) {
    TVP_Out(['ok' => false, 'error' => 'rules_missing_entrypoint'], 500);
}

TVP_Log($MOD, 'rules_loaded', ['file' => $rulesFile]);

/* ---------------- Writer ---------------- */

$writerLib = CRM_ROOT . '/_lib/events/crm_events_write.php';
if (!is_file($writerLib)) {
    TVP_Out(['ok' => false, 'error' => 'writer_missing', 'file' => $writerLib], 500);
}
require_once $writerLib;

if (!class_exists('CRM_EventGenerator') || !method_exists('CRM_EventGenerator', 'upsert')) {
    TVP_Out(['ok' => false, 'error' => 'writer_invalid'], 500);
}

TVP_Log($MOD, 'process_start', [
    'records'  => $records,
    'raw_file' => $rawFile,
]);

/* ---------------- Process ---------------- */

$upserts = 0;
$errors  = 0;
$previewFirstPatch = null;

foreach ($items as $row) {
    if (!is_array($row)) { continue; }

    try {
        $patch = RULES_TEAMVIEWER_BuildPatch($row);

        if ($previewFirstPatch === null) {
            $previewFirstPatch = $patch;
        }

        $res = CRM_EventGenerator::upsert('teamviewer', 'remote', $patch);
        if (is_array($res) && ($res['ok'] ?? false)) {
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
    'ok'                  => true,
    'records'             => $records,
    'upserts'             => $upserts,
    'errors'              => $errors,
    'preview_first_patch' => $previewFirstPatch,
]);
