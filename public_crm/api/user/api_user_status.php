<?php
declare(strict_types=1);

/*
  Datei: /public_service/api/user/api_user_status.php
  Zweck:
  - Public (read-only): Liefert effektiven Status für Service-Seite
  - Quelle: /data/login/mitarbeiter_status.json (shared data)
  - Effektiv:
    away/off => off
    busy OR pbx_busy => away   (Service: "Unterwegs/Telefon" = away)
    online/auto => online
*/

header('Content-Type: application/json; charset=utf-8');

$BASE = dirname(__DIR__, 4); // /public_service/api/user -> Projekt-Root
$statusFile = $BASE . '/data/login/mitarbeiter_status.json';

// TODO: später dynamisch (z.B. "primary_user" aus config)
$userKey = 'admin';

function FN_LoadJson(string $file): array
{
    if (!is_file($file)) { return []; }
    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') { return []; }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function FN_EffectiveServiceState(string $manual, bool $pbxBusy): string
{
    $manual = trim($manual);

    if ($manual === 'off')  { return 'off'; }
    if ($manual === 'away') { return 'away'; }

    // busy ist "gelb" im CRM, Service zeigt dafür typischerweise "Unterwegs/Telefon"
    if ($manual === 'busy' || $pbxBusy) { return 'away'; }

    // online/auto => online
    return 'online';
}

$db = FN_LoadJson($statusFile);
$row = is_array($db[$userKey] ?? null) ? (array)$db[$userKey] : [];

$manual = (string)($row['manual_state'] ?? 'auto');
$busy   = (bool)($row['pbx_busy'] ?? false);

echo json_encode([
    'ok'           => true,
    'user'         => $userKey,
    'manual_state' => $manual,
    'pbx_busy'     => $busy,
    'state'        => FN_EffectiveServiceState($manual, $busy),
    'updated_at'   => $row['updated_at'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
