<?php
declare(strict_types=1);

/*
  Datei: /public_crm/api/user/api_user_status_public.php
  Zweck:
  - Public Status Endpoint (ohne Login), z. B. für service.hcmedv.de
  - Liest <crm_data>/core/user_status.json (über CRM_LoginPath('status'))
  - Liefert NUR den effektiven Status: online | busy | away | off

  Logik:
  - manual_state = away/off        -> away/off
  - manual_state = busy OR pbx_busy=true -> busy
  - sonst                          -> online
*/


define('CRM_MODULE', 'login');
require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* Optional: Wenn du den Proxy später weg lassen willst */
// header('Access-Control-Allow-Origin: *');

define('PUBLIC_STATUS_DEFAULT_USER', 'admin'); // Fallback, wenn kein ?user=... übergeben wird

function FN_JsonOut(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function FN_EffectiveState(string $manual, bool $pbxBusy): string
{
    $manual = trim($manual);

    if ($manual === 'off')  { return 'off'; }
    if ($manual === 'away') { return 'away'; }

    if ($manual === 'busy' || $pbxBusy) { return 'busy'; }

    // online/auto/leer => online
    return 'online';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    FN_JsonOut(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$statusFile = CRM_LoginPath('status');
if ($statusFile === '') {
    FN_JsonOut(500, ['ok' => false, 'error' => 'login_status_path_missing']);
}

$db = CRM_LoadJsonFile($statusFile, []);

/* optional: ?user=dennis */
$user = trim((string)($_GET['user'] ?? ''));
if ($user === '') { $user = PUBLIC_STATUS_DEFAULT_USER; }

$row = (isset($db[$user]) && is_array($db[$user])) ? (array)$db[$user] : [];

$manual = (string)($row['manual_state'] ?? 'off');
$pbxBusy = (bool)($row['pbx_busy'] ?? false);

$state = FN_EffectiveState($manual, $pbxBusy);

FN_JsonOut(200, [
    'ok'              => true,
    'state'           => $state,          // alias (Service)
    'effective_state' => $state,          // explizit
    'user'            => $user,
    'updated_at'      => $row['updated_at'] ?? null,
]);
