<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/login/logout.php
 * Zweck:
 * - Logout + Redirect zur Login-Seite
 * - Setzt beim Logout den User-Status serverseitig auf "off" (mitarbeiter_status.json)
 */

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

header('Content-Type: text/html; charset=utf-8');

/* -------------------------------------------------
   Helper: Status beim Logout persistieren
   ------------------------------------------------- */
function FN_WriteJsonFileAtomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) { return false; }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) { return false; }

    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }

    if (@rename($tmp, $path)) { return true; }
    @unlink($tmp);

    return (file_put_contents($path, $json, LOCK_EX) !== false);
}

/* -------------------------------------------------
   Status -> off (nur wenn User bekannt + Status-Datei konfiguriert)
   ------------------------------------------------- */
try {
    $u = (function_exists('CRM_Auth_User') ? (array)CRM_Auth_User() : []);
    $userKey = trim((string)($u['user'] ?? ''));

    $statusFile = (function_exists('CRM_LoginPath') ? CRM_LoginPath('status') : '');
    if ($userKey !== '' && $statusFile !== '' && is_file($statusFile)) {

        $db = CRM_LoadJsonFile($statusFile, []);
        if (!is_array($db)) { $db = []; }

        $prev = (isset($db[$userKey]) && is_array($db[$userKey])) ? (array)$db[$userKey] : [];

        $db[$userKey] = [
            'manual_state' => 'off',
            'pbx_busy'     => false,                       // beim Logout immer "frei"
            'updated_at'   => date('c'),
        ] + $prev; // falls später weitere Felder dazukommen, bleiben sie erhalten

        FN_WriteJsonFileAtomic($statusFile, $db);
    }
} catch (Throwable $e) {
    // Logout darf nie scheitern
}

/* -------------------------------------------------
   Logout
   ------------------------------------------------- */
if (function_exists('CRM_Auth_Logout')) {
    CRM_Auth_Logout(); // macht selbst Redirect/Exit
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: /login/');
exit;
