<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/crm/api_crm_events_commit.php
 *
 * Zweck:
 * - HTTP-Endpoint für UI/Browser: Draft-Patch + optional State-Change übernehmen
 * - Ruft den Core-Writer (/public_crm/_lib/events/crm_events_write.php) auf
 * - Auth/Session-Guard + CSRF
 *
 * Input (JSON):
 * {
 *   "event_source": "ui",
 *   "event_type":   "note",
 *   "patch": { ... }              // beliebiger Patch (snake_case)
 * }
 *
 * Response:
 * { ok:true, ... }
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$MOD = 'events';

/* =========================
 * Helper
 * ========================= */

function EVT_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function EVT_ReadJsonBody(): array
{
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function EVT_IsLoggedIn(): bool
{
    return isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user']);
}

function EVT_RequireLogin(): void
{
    if (!EVT_IsLoggedIn()) {
        EVT_Out(['ok' => false, 'error' => 'auth_required'], 401);
    }
}

function EVT_GetCsrfFromRequest(): string
{
    $h = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (trim($h) !== '') return trim($h);

    $q = (string)($_GET['csrf'] ?? '');
    if (trim($q) !== '') return trim($q);

    return '';
}

/*
 * Erwartung:
 * - Session speichert csrf token unter $_SESSION['csrf'] (oder $_SESSION['csrf_token'])
 * Falls bei dir anders: hier anpassen (nur hier).
 */
function EVT_RequireCsrf(): void
{
    $provided = EVT_GetCsrfFromRequest();
    $expected = (string)($_SESSION['csrf'] ?? $_SESSION['csrf_token'] ?? '');

    if ($provided === '' || $expected === '') {
        EVT_Out(['ok' => false, 'error' => 'csrf_missing'], 403);
    }

    if (function_exists('hash_equals')) {
        if (!hash_equals($expected, $provided)) {
            EVT_Out(['ok' => false, 'error' => 'csrf_invalid'], 403);
        }
        return;
    }

    if ($expected !== $provided) {
        EVT_Out(['ok' => false, 'error' => 'csrf_invalid'], 403);
    }
}

/* =========================
 * Guards
 * ========================= */

EVT_RequireLogin();
EVT_RequireCsrf();

/* =========================
 * Writer laden
 * ========================= */

$writerFile = CRM_ROOT . '/_lib/events/crm_events_write.php';
if (!is_file($writerFile)) {
    EVT_Out(['ok' => false, 'error' => 'writer_missing'], 500);
}
require_once $writerFile;

if (!class_exists('CRM_Events_Write')) {
    EVT_Out(['ok' => false, 'error' => 'writer_class_missing'], 500);
}

/* =========================
 * Input validieren
 * ========================= */

$in = EVT_ReadJsonBody();

$eventSource = trim((string)($in['event_source'] ?? 'ui'));
$eventType   = trim((string)($in['event_type'] ?? 'note'));
$patch       = $in['patch'] ?? [];

if (!is_array($patch)) {
    EVT_Out(['ok' => false, 'error' => 'patch_not_array'], 422);
}
if ($eventSource === '' || $eventType === '') {
    EVT_Out(['ok' => false, 'error' => 'missing_source_or_type'], 422);
}

/* =========================
 * Commit
 * ========================= */

try {
    $res = CRM_Events_Write::upsert($eventSource, $eventType, $patch);

    if (!is_array($res) || !(bool)($res['ok'] ?? false)) {
        EVT_Out([
            'ok' => false,
            'error' => (string)($res['error'] ?? 'write_failed'),
            'writer' => $res,
        ], 400);
    }

    EVT_Out([
        'ok' => true,
        'writer' => $res,
    ], 200);

} catch (Throwable $e) {
    EVT_Out([
        'ok' => false,
        'error' => 'exception',
        'msg' => $e->getMessage(),
    ], 500);
}
