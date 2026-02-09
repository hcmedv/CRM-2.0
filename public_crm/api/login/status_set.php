<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/login/status_set.php
 *
 * Zweck:
 * - Setzt den manuellen Benutzerstatus (online|busy|away|auto)
 * - Single Write-Endpoint für die User-Status-UI
 * - Merge-safe: es werden nur übergebene Felder aktualisiert
 *
 * Erwartete Parameter (POST):
 * - manual_state: online | busy | away | auto
 *
 * Rückgabe:
 * {
 *   ok: true|false,
 *   row: { ... },
 *   effective_state: "online|busy|away|off"
 * }
 *
 * Besonderheiten:
 * - Keine Auth-Redirects (JS-sicher)
 * - Status-Quelle: crm_status.php (Single Source of Truth)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/login/crm_status.php';

header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------
   Auth: prüfen, aber niemals redirecten
   ------------------------------------------------- */
$user = $_SESSION['crm_user']['user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

/* -------------------------------------------------
   Input validieren
   ------------------------------------------------- */
$allowed = ['online', 'busy', 'away', 'auto'];
$state   = $_POST['manual_state'] ?? null;

if (!is_string($state) || !in_array($state, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_state']);
    exit;
}

/* -------------------------------------------------
   Status aktualisieren (merge-safe)
   ------------------------------------------------- */
$row = CRM_Status_UpdateUser($user, [
    'manual_state' => $state,
    'updated_at'   => date('c'),
]);

/* -------------------------------------------------
   JSON-Antwort
   ------------------------------------------------- */
echo json_encode([
    'ok'              => true,
    'row'             => $row,
    'effective_state' => CRM_Status_Effective($row),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;
