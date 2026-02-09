<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/login/logout.php
 *
 * Zweck:
 * - Logout + Redirect zur Login-Seite
 * - Setzt den Benutzerstatus ausschließlich über crm_status.php:
 *   - logged_in  = false
 *   - pbx_busy  = false
 *   - updated_at = now
 * - manual_state bleibt UNVERÄNDERT (auto/online/busy/away)
 *
 * Hinweise:
 * - Keine direkte JSON-/Dateilogik mehr in dieser Datei
 * - Status-DB wird ausschließlich über crm_status.php gepflegt
 * - Logout darf niemals scheitern
 */

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
require_once CRM_ROOT . '/login/crm_status.php';

header('Content-Type: text/html; charset=utf-8');

/* -------------------------------------------------
   Status setzen (best effort)
   ------------------------------------------------- */
try {
    $u = function_exists('CRM_Auth_User') ? (array)CRM_Auth_User() : [];
    $userKey = trim((string)($u['user'] ?? ''));

    // Fallback: manche Implementierungen liefern "username"
    if ($userKey === '') {
        $userKey = trim((string)($u['username'] ?? ''));
    }

    if ($userKey !== '') {
        CRM_Status_UpdateUser($userKey, [
            'logged_in'  => false,
            'pbx_busy'   => false,
            'updated_at'=> date('c'),
            // manual_state bewusst NICHT ändern
        ]);
    }
} catch (Throwable $e) {
    // Logout darf nie scheitern
}

/* -------------------------------------------------
   Logout / Session beenden
   ------------------------------------------------- */
if (function_exists('CRM_Auth_Logout')) {
    CRM_Auth_Logout(); // kann selbst redirect/exit machen
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: /login/');
exit;
