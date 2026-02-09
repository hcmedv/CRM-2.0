<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/login/status_get.php
 *
 * Zweck:
 * - Liefert den aktuellen Benutzerstatus als JSON
 * - Single Read-Endpoint für die User-Status-UI (Top-Navigation)
 * - Gibt immer JSON zurück (auch bei Fehlern), niemals Redirects
 *
 * Rückgabe:
 * {
 *   ok: true|false,
 *   user: string,
 *   row: {
 *     manual_state: "auto|online|busy|away|off",
 *     pbx_busy: bool,
 *     logged_in: bool,
 *     updated_at: ISO-8601|null
 *   },
 *   effective_state: "online|busy|away|off"
 * }
 *
 * Besonderheiten:
 * - Keine Auth-Redirects (JS-sicher)
 * - Lazy-Fix: Wenn Session nicht mehr gültig ist,
 *   wird logged_in serverseitig auf false korrigiert
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
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized'
    ]);
    exit;
}

/* -------------------------------------------------
   Status laden
   ------------------------------------------------- */
$row = CRM_Status_GetUser($user);

/* Lazy-Fix: Session weg → logged_in=false */
if (empty($_SESSION['crm_user']) && !empty($row['logged_in'])) {
    $row = CRM_Status_UpdateUser($user, [
        'logged_in'   => false,
        'updated_at' => date('c'),
    ]);
}

/* -------------------------------------------------
   JSON-Antwort
   ------------------------------------------------- */
echo json_encode([
    'ok'              => true,
    'user'            => $user,
    'row'             => $row,
    'effective_state' => CRM_Status_Effective($row),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;
