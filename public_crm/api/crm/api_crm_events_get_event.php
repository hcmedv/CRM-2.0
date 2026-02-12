<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/crm/api_crm_events_get_event.php
 *
 * Zweck:
 * - Liefert EIN Event (full) anhand event_id
 * - Nutzt zentralen Reader (_lib/events/crm_events_read.php)
 *
 * Request:
 * - GET ?event_id=<id>
 */

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_lib/events/crm_events_read.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function FN_Out(array $j, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$eventId = trim((string)($_GET['event_id'] ?? ''));
if ($eventId === '') {
    FN_Out(['ok' => false, 'error' => 'missing_event_id'], 400);
}

try
{
    $event = CRM_Events_GetById($eventId);
}
catch (Throwable $e)
{
    FN_Out([
        'ok'    => false,
        'error' => 'read_failed',
        'msg'   => $e->getMessage(),
    ], 500);
}

if (!$event) {
    FN_Out([
        'ok'       => false,
        'error'    => 'not_found',
        'event_id' => $eventId,
    ], 404);
}

FN_Out([
    'ok'  => true,
    'src' => [
        'reader' => CRM_ROOT . '/_lib/events/crm_events_read.php',
    ],
    'event' => $event,
]);
