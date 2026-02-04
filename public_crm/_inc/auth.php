<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/auth.php
 * Zweck:
 * - Login/Logout
 * - Session-Check
 * - Rollenprüfung
 * - Datenquelle: /data/login/mitarbeiter.json
 */

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/bootstrap.php';
}

define('CRM_LOGIN_FILE', CRM_BASE . '/data/login/mitarbeiter.json');

function CRM_Auth_IsLoggedIn(): bool
{
    return isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user']);
}

function CRM_Auth_RequireLogin(): void
{
    if (!CRM_Auth_IsLoggedIn()) {
        header('Location: /login');
        exit;
    }

    CRM_Auth_TouchSession();
}

function CRM_Auth_Login(string $user, string $pass): bool
{
    $user = trim($user);
    if ($user === '' || $pass === '') return false;

    if (!is_file(CRM_LOGIN_FILE)) return false;

    $data = json_decode((string)file_get_contents(CRM_LOGIN_FILE), true);
    if (!is_array($data)) return false;

    foreach ($data as $u) {
        $uUser = isset($u['user']) ? (string)$u['user'] : '';
        $uHash = isset($u['pass']) ? (string)$u['pass'] : '';

        if ($uUser === $user && $uHash !== '' && password_verify($pass, $uHash)) {
            $_SESSION['crm_user'] = [
                'user' => $uUser,
                'name' => (string)($u['name'] ?? $uUser),
                'role' => (string)($u['role'] ?? 'user'),
            ];
            return true;
        }
    }

    return false;
}

function CRM_Auth_Logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    header('Location: /login');
    exit;
}

function CRM_Auth_User(): array
{
    return CRM_Auth_IsLoggedIn() ? (array)$_SESSION['crm_user'] : [];
}

function CRM_Auth_HasRole(string $role): bool
{
    $u = CRM_Auth_User();
    return ($u['role'] ?? '') === $role;
}

function CRM_Auth_TouchSession(): void
{
    $idle = (int)CRM_CFG('session_idle_timeout_sec', 0);
    if ($idle <= 0) return;

    $now  = time();
    $last = (int)($_SESSION['crm_last_activity'] ?? 0);

    if ($last > 0 && ($now - $last) > $idle) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: /login');
        exit;
    }

    $_SESSION['crm_last_activity'] = $now;
}


