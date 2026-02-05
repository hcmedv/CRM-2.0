<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/login/logout.php
 * Zweck:
 * - Logout + Redirect zur Login-Seite
 * Wichtig:
 * - bootstrap liegt eine Ebene höher (../_inc/bootstrap.php)
 */

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

if (function_exists('CRM_Auth_Logout')) {
    CRM_Auth_Logout();
} else {
    // Fallback: Session kill (falls Auth-Lib keine Logout-Funktion hat)
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

header('Location: /login/');
exit;
