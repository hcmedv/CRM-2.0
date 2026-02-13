<?php
declare(strict_types=1);

/*
  Datei: /public_crm/api/pbx/api_crm_pbx_sipgate.php
  (ALT: /public/api/system/api_pbx_sipgate.php)

  Zweck:
  - Nimmt Sipgate Webhook Events entgegen (POST)
  - Schreibt RAW append-only nach /data/pbx/events_raw.jsonl
  - Pflegt Call-State nach /data/pbx/calls_state.json
  - Triggert PBX-Processing (PBX -> Writer) via PBX_Process() (include-only)
  - Antwort:
      - newCall => XML subscribe
      - sonst   => JSON ok

  WICHTIG:
  - Dieser Webhook macht KEIN Enrichment/KEINE UI-Logik.
*/

define('LOG_CHANNEL', 'sipgate_webhook');

require_once __DIR__ . '/../../_inc/bootstrap.php';

/* ------------------------------------------------------------
 * Minimal Defaults (CFG() existiert hier nicht mehr)
 * ------------------------------------------------------------ */
$PROJECT_ROOT = defined('CRM_ROOT') ? dirname(CRM_ROOT) : dirname(__DIR__, 3); // Fallback

$pbxDir     = $PROJECT_ROOT . '/data/pbx';
$outFile    = $pbxDir . '/events_raw.jsonl';
$callsFile  = $pbxDir . '/calls_state.json';
$logFile    = $pbxDir . '/webhook.log';
$maxCalls   = 2000;

$webhookSelfUrl = 'https://crm.hcmedv.de/api/pbx/api_crm_pbx_sipgate.php';

// Processor im CRM V2
$processScript = CRM_ROOT . '/pbx/crm_process_pbx.php';

/* ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------ */
function FN_EnsureDirForFile(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
}

function FN_Log(string $file, string $msg): void
{
    if ($file === '') { return; }
    FN_EnsureDirForFile($file);
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

function FN_AppendJsonl(string $file, array $row): bool
{
    if ($file === '') { return false; }
    FN_EnsureDirForFile($file);

    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) { return false; }

    $fp = @fopen($file, 'ab');
    if ($fp === false) { return false; }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    $ok = (fwrite($fp, $json . PHP_EOL) !== false);

    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function FN_ReadJsonFile(string $file): array
{
    if ($file === '' || !is_file($file)) { return []; }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') { return []; }

    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function FN_WriteJsonFileAtomic(string $file, array $data): bool
{
    if (trim($file) === '') { return false; }

    FN_EnsureDirForFile($file);

    $dir = dirname($file);
    if (!is_dir($dir)) { return false; }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') { return false; }

    try
    {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
    }
    catch (Throwable $e)
    {
        $tmp = $file . '.tmp.' . (string)mt_rand(100000, 999999);
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (@rename($tmp, $file) === false) {
        @unlink($tmp);
        return false;
    }

    return true;
}


function FN_IsoNow(): string
{
    return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
}

function FN_IsoToTs(?string $iso): int
{
    $iso = trim((string)$iso);
    if ($iso === '') { return 0; }
    $t = strtotime($iso);
    return ($t === false) ? 0 : (int)$t;
}

function FN_NormalizeState(string $s): string
{
    $s = strtolower(trim((string)$s));
    if ($s === '') { return ''; }
    if (in_array($s, ['newcall','new_call','new-call'], true)) { return 'newcall'; }
    if (in_array($s, ['answer','answered'], true)) { return 'answer'; }
    if (in_array($s, ['hangup','hang_up','hang-up','ended','end'], true)) { return 'hangup'; }
    $s = preg_replace('/[^a-z0-9_\-]+/', '', $s) ?? '';
    return $s;
}

function FN_MapSipgateEventToState(string $ev): string
{
    $ev = strtolower(trim((string)$ev));
    if ($ev === '') { return ''; }
    if ($ev === 'newcall') { return 'newcall'; }
    if ($ev === 'answer' || $ev === 'answered') { return 'answer'; }
    if ($ev === 'hangup') { return 'hangup'; }
    return FN_NormalizeState($ev);
}

function FN_NormalizeSipgate(array $p): array
{
    $event = (string)($p['event'] ?? 'unknown');

    return [
        'received_at'      => FN_IsoNow(),
        'source_ip'        => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'provider'         => 'sipgate',

        'event'            => $event,
        'state'            => FN_MapSipgateEventToState($event),

        'callId'           => (string)($p['callId'] ?? ''),
        'origCallId'       => (string)($p['origCallId'] ?? ''),

        'from'             => (string)($p['from'] ?? ''),
        'to'               => (string)($p['to'] ?? ''),
        'direction'        => (string)($p['direction'] ?? ''),
        'diversion'        => (string)($p['diversion'] ?? ''),

        'user'             => $p['user'] ?? ($p['user[]'] ?? null),
        'userId'           => $p['userId'] ?? ($p['userId[]'] ?? null),
        'fullUserId'       => $p['fullUserId'] ?? ($p['fullUserId[]'] ?? null),

        'answeringNumber'  => (string)($p['answeringNumber'] ?? ''),
        'cause'            => (string)($p['cause'] ?? ''),

        'raw'              => $p
    ];
}

function FN_PruneCallState(array $calls, int $maxCalls): array
{
    if ($maxCalls <= 0) { return []; }
    if (count($calls) <= $maxCalls) { return $calls; }

    $items = [];
    foreach ($calls as $callId => $row) {
        if (!is_array($row)) { continue; }

        $ts = '';
        if (isset($row['ts_hangup']) && is_string($row['ts_hangup']) && ($row['ts_hangup'] !== '')) { $ts = $row['ts_hangup']; }
        elseif (isset($row['ts_answer']) && is_string($row['ts_answer']) && ($row['ts_answer'] !== '')) { $ts = $row['ts_answer']; }
        elseif (isset($row['ts_newcall']) && is_string($row['ts_newcall']) && ($row['ts_newcall'] !== '')) { $ts = $row['ts_newcall']; }
        else { $ts = '1970-01-01T00:00:00+00:00'; }

        $t = strtotime($ts);
        if ($t === false) { $t = 0; }

        $items[] = [
            'callId' => (string)$callId,
            't'      => (int)$t,
            'row'    => $row
        ];
    }

    usort($items, function ($a, $b) {
        if ($a['t'] === $b['t']) { return 0; }
        return ($a['t'] > $b['t']) ? -1 : 1;
    });

    $keep = array_slice($items, 0, $maxCalls);
    $out  = [];

    foreach ($keep as $it) {
        $out[$it['callId']] = $it['row'];
    }

    return $out;
}

function FN_UpdateCallState(string $callsFile, array $norm, int $maxCalls): array
{
    $callId = (string)($norm['callId'] ?? '');
    if ($callId === '') {
        return [
            'callId' => '',
            'state' => (string)($norm['state'] ?? ''),
            'hangup_without_start' => false,
            'startedIso' => null,
            'endedIso' => null,
            'durationSec' => null
        ];
    }

    $calls = FN_ReadJsonFile($callsFile);

    $now   = (string)($norm['received_at'] ?? FN_IsoNow());
    $state = (string)($norm['state'] ?? '');

    if (!isset($calls[$callId]) || !is_array($calls[$callId])) {
        $calls[$callId] = [
            'callId'          => $callId,
            'from'            => (string)($norm['from'] ?? ''),
            'to'              => (string)($norm['to'] ?? ''),
            'direction'       => (string)($norm['direction'] ?? ''),
            'ts_newcall'      => null,
            'ts_answer'       => null,
            'ts_hangup'       => null,
            'duration_sec'    => null,
            'cause'           => '',
            'answeringNumber' => '',
            'last_state'      => ''
        ];
    }

    $hangupWithoutStart = false;

    if ($state === 'newcall') {
        if ($calls[$callId]['ts_newcall'] === null) { $calls[$callId]['ts_newcall'] = $now; }

    } elseif ($state === 'answer') {

        if ($calls[$callId]['ts_answer'] === null) { $calls[$callId]['ts_answer'] = $now; }
        if ((string)($norm['answeringNumber'] ?? '') !== '') { $calls[$callId]['answeringNumber'] = (string)$norm['answeringNumber']; }

    } elseif ($state === 'hangup') {

        if ($calls[$callId]['ts_hangup'] === null) {

            $calls[$callId]['ts_hangup'] = $now;

            $hasStart = false;
            if (is_string($calls[$callId]['ts_answer'] ?? null) && (string)$calls[$callId]['ts_answer'] !== '') { $hasStart = true; }
            if (is_string($calls[$callId]['ts_newcall'] ?? null) && (string)$calls[$callId]['ts_newcall'] !== '') { $hasStart = true; }
            if (!$hasStart) { $hangupWithoutStart = true; }
        }

        if ((string)($norm['cause'] ?? '') !== '') { $calls[$callId]['cause'] = (string)$norm['cause']; }
        if ((string)($norm['answeringNumber'] ?? '') !== '') { $calls[$callId]['answeringNumber'] = (string)$norm['answeringNumber']; }

        $tsStart = $calls[$callId]['ts_answer'] ?? null;
        if ($tsStart === null) { $tsStart = $calls[$callId]['ts_newcall'] ?? null; }

        if (is_string($tsStart) && $tsStart !== '') {
            $t0 = strtotime($tsStart);
            $t1 = strtotime($now);
            if (($t0 !== false) && ($t1 !== false) && ($t1 >= $t0)) {
                $calls[$callId]['duration_sec'] = (int)($t1 - $t0);
            }
        }
    }

    $calls[$callId]['last_state'] = $state;

    $calls = FN_PruneCallState($calls, $maxCalls);
    FN_WriteJsonFileAtomic($callsFile, $calls);

    $startedIso = null;
    $endedIso = null;
    $durationSec = null;

    $row = $calls[$callId] ?? null;
    if (is_array($row)) {

        $startedIso = (string)($row['ts_answer'] ?? '');
        if ($startedIso === '') { $startedIso = (string)($row['ts_newcall'] ?? ''); }
        if ($startedIso === '') { $startedIso = null; }

        $endedIso = (string)($row['ts_hangup'] ?? '');
        if ($endedIso === '') { $endedIso = null; }

        $durationSec = isset($row['duration_sec']) ? (int)$row['duration_sec'] : null;
    }

    return [
        'callId' => $callId,
        'state' => $state,
        'hangup_without_start' => (bool)$hangupWithoutStart,
        'startedIso' => $startedIso,
        'endedIso' => $endedIso,
        'durationSec' => $durationSec
    ];
}

function FN_OutputJson(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function FN_OutputSipgateXmlSubscribe(string $urlAnswer, string $urlHangup): void
{
    http_response_code(200);
    header('Content-Type: application/xml; charset=utf-8');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Response onAnswer="' . htmlspecialchars($urlAnswer, ENT_QUOTES, 'UTF-8') . '" onHangup="' . htmlspecialchars($urlHangup, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
}

/* ------------------------------------------------------------
 * 1) POST lesen
 * ------------------------------------------------------------ */
$payload = $_POST;
if (!is_array($payload) || !$payload) {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    $payload = is_array($j) ? $j : ['_raw' => (string)$raw];
}

/* ------------------------------------------------------------
 * 2) Normalisieren
 * ------------------------------------------------------------ */
$norm = FN_NormalizeSipgate($payload);

if (($norm['event'] === 'unknown') || ((string)$norm['callId'] === '')) {
    FN_Log($logFile, 'WARN invalid payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ------------------------------------------------------------
 * 3) RAW JSONL append
 * ------------------------------------------------------------ */
if (!FN_AppendJsonl($outFile, $norm)) {
    FN_Log($logFile, 'ERROR write_failed event=' . (string)$norm['event'] . ' callId=' . (string)$norm['callId']);
    FN_OutputJson(['ok' => false, 'error' => 'write_failed'], 500);
    exit;
}

/* ------------------------------------------------------------
 * 4) Call-State aktualisieren
 * ------------------------------------------------------------ */
$stateInfo = FN_UpdateCallState($callsFile, $norm, $maxCalls);

FN_Log(
    $logFile,
    'OK event=' . (string)$norm['event'] .
    ' state=' . (string)$norm['state'] .
    ' callId=' . (string)$norm['callId'] .
    ' from=' . (string)$norm['from'] .
    ' to=' . (string)$norm['to'] .
    (((string)$norm['answeringNumber'] !== '') ? ' answeringNumber=' . (string)$norm['answeringNumber'] : '') .
    (((string)$norm['cause'] !== '') ? ' cause=' . (string)$norm['cause'] : '') .
    ($stateInfo['hangup_without_start'] ? ' hangup_without_start=1' : '')
);

/* ------------------------------------------------------------
 * 5) PBX Processing -> Writer
 * ------------------------------------------------------------ */
if (is_file($processScript)) {

    require_once $processScript;

    if (function_exists('PBX_Process')) {

        $pbxEvent = [
            'provider'    => 'sipgate',
            'call_id'     => (string)$norm['callId'],
            'from'        => (string)$norm['from'],
            'to'          => (string)$norm['to'],
            'direction'   => (string)$norm['direction'],
            'state'       => (string)$norm['state'],
            'received_at' => (string)$norm['received_at'],

            'timing' => [
                'started_at' => FN_IsoToTs($stateInfo['startedIso'] ?? null),
                'ended_at'   => ($stateInfo['endedIso'] ?? null) ? FN_IsoToTs((string)$stateInfo['endedIso']) : null,
            ],
        ];

        try {

            $res = PBX_Process($pbxEvent);

            if (empty($res['ok'])) {
                FN_Log($logFile, 'ERROR pbx_process failed: ' . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                FN_Log($logFile, 'PBX->Writer ok event_id=' . (string)($res['event_id'] ?? '') . ' is_new=' . (string)($res['is_new'] ?? ''));
            }

        } catch (Throwable $e) {

            FN_Log($logFile, 'ERROR pbx_process exception: ' . $e->getMessage());
        }

    } else {

        FN_Log($logFile, 'WARN pbx_process loaded but PBX_Process() missing');
    }
} else {
    FN_Log($logFile, 'WARN pbx_process script missing: ' . $processScript);
}

/* ------------------------------------------------------------
 * 6) Antwort newCall => XML subscribe
 * ------------------------------------------------------------ */
if ((string)$norm['state'] === 'newcall') {
    FN_OutputSipgateXmlSubscribe($webhookSelfUrl, $webhookSelfUrl);
    exit;
}

/* ------------------------------------------------------------
 * 7) sonst JSON OK
 * ------------------------------------------------------------ */
FN_OutputJson(['ok' => true]);
