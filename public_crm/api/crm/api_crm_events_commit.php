<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/*
 * Datei: /public/api/crm/api_crm_events_commit.php
 *
 * Zweck:
 * - UI Commit Endpoint (Create/Update)
 * - Auth + CSRF
 * - Validate/Normalize zentral via /_lib/commit/crm_commit_validate.php
 * - Write via CRM_EventGenerator::upsert()
 * - Optional PostWrite Hook aus Validator (z.B. camera finalize)
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (function_exists('CRM_CsrfCheckRequest')) {
    CRM_CsrfCheckRequest();
}

// Writer
require_once CRM_ROOT . '/_lib/events/crm_events_write.php';

// Validator
require_once CRM_ROOT . '/_lib/commit/crm_commit_validate.php';

function CRM_Commit_RESP(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// JSON genau EINMAL lesen
$raw = (string)file_get_contents('php://input');
$in  = [];
if (trim($raw) !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) { $in = $j; }
}

if (!function_exists('CRM_CommitValidate')) {
    CRM_Commit_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'validator_missing'], 500);
}

if (!class_exists('CRM_EventGenerator')) {
    CRM_Commit_RESP(['ok' => false, 'error' => 'server_error', 'message' => 'writer_missing'], 500);
}

try {

    $v = CRM_CommitValidate($in);

    if (!is_array($v) || ($v['ok'] ?? false) !== true) {
        $status  = (int)($v['status'] ?? 400);
        $error   = (string)($v['error'] ?? 'bad_request');
        $message = (string)($v['message'] ?? 'bad_request');
        CRM_Commit_RESP(['ok' => false, 'error' => $error, 'message' => $message], $status);
    }

    $eventSource = (string)($v['event_source'] ?? '');
    $eventType   = (string)($v['event_type'] ?? '');
    $patch       = (array)($v['patch'] ?? []);
    $ctx         = (array)($v['ctx'] ?? []);
    $postWrite   = $v['post_write'] ?? null;

    $isCreate = ((string)($v['event_id'] ?? '') === '');

    // 1) upsert
    $r = CRM_EventGenerator::upsert($eventSource, $eventType, $patch);
    if (!is_array($r) || ($r['ok'] ?? false) !== true) {
        CRM_Commit_RESP(['ok' => false, 'error' => 'writer_failed', 'message' => (string)($r['error'] ?? 'writer_failed'), 'ctx' => $r], 500);
    }

    $evt = $r['event'] ?? null;
    $outEventId = is_array($evt) ? (string)($evt['event_id'] ?? '') : '';
    if (trim($outEventId) === '') {
        $outEventId = $isCreate ? '' : (string)($v['event_id'] ?? '');
    }
    if (trim($outEventId) === '') {
        CRM_Commit_RESP(['ok' => false, 'error' => 'writer_failed', 'message' => 'writer did not return event_id'], 500);
    }

    $created = (bool)($r['is_new'] ?? $isCreate);

    // 2) optional post_write hook (z.B. camera finalize)
    if (is_string($postWrite) && $postWrite !== '' && function_exists($postWrite)) {
        $pw = $postWrite($outEventId, $eventSource, $eventType, $ctx);
        if (is_array($pw) && ($pw['ok'] ?? true) !== true) {
            CRM_Commit_RESP(['ok' => false, 'error' => (string)($pw['error'] ?? 'post_write_failed'), 'message' => (string)($pw['message'] ?? 'post_write_failed'), 'ctx' => $pw], 500);
        }
    }

    CRM_Commit_RESP([
        'ok'       => true,
        'event_id' => $outEventId,
        'written'  => true,
        'created'  => $created,
    ], 200);

} catch (Throwable $e) {
    CRM_Commit_RESP(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
}
?>