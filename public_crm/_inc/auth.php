<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/auth.php
 * Zweck:
 * - Login/Logout
 * - Session-Check
 * - Rollenprüfung
 * - Datenquelle: /data/login/mitarbeiter.json
 * - Status-Persistenz: mitarbeiter_status.json (CRM_LoginPath('status'))
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

/*
 * Schreibt den Login-Status des Users in die zentrale Status-Datei.
 * - nutzt CRM_LoginPath('status') (kein Hardcode)
 * - busy wird als online persistiert (Overlay)
 */
function CRM_LoginStatus_SetManualState(string $userKey, string $manualState): void
{
    $userKey = trim($userKey);
    $manualState = trim($manualState);

    if ($userKey === '') { return; }

    $allowed = ['online', 'away', 'off', 'auto', 'busy'];
    if (!in_array($manualState, $allowed, true)) { return; }

    if ($manualState === 'busy') { $manualState = 'online'; }

    if (!function_exists('CRM_LoginPath')) { return; }

    $file = (string)CRM_LoginPath('status');
    if ($file === '') { return; }

    $dir = dirname($file);
    if (!is_dir($dir)) { return; }

    // Read
    $db = [];
    if (is_file($file)) {
        $raw = (string)@file_get_contents($file);
        $j = json_decode($raw, true);
        if (is_array($j)) { $db = $j; }
    }

    $row = is_array($db[$userKey] ?? null) ? (array)$db[$userKey] : [];

    $db[$userKey] = [
        'manual_state' => $manualState,
        'pbx_busy'     => (bool)($row['pbx_busy'] ?? false),
        'updated_at'   => date('c'),
    ];

    // Write (atomic best-effort)
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) { return; }

    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { return; }

    if (@rename($tmp, $file)) { return; }

    @unlink($tmp);
    @file_put_contents($file, $json, LOCK_EX);
}

function CRM_Auth_Login(string $user, string $pass): bool
{
    $user = trim($user);
    if ($user === '' || $pass === '') { return false; }

    if (!is_file(CRM_LOGIN_FILE)) { return false; }

    $data = json_decode((string)file_get_contents(CRM_LOGIN_FILE), true);
    if (!is_array($data)) { return false; }

    foreach ($data as $u) {
        $uUser = isset($u['user']) ? (string)$u['user'] : '';
        $uHash = isset($u['pass']) ? (string)$u['pass'] : '';

        if ($uUser === $user && $uHash !== '' && password_verify($pass, $uHash)) {
            $_SESSION['crm_user'] = [
                'user' => $uUser,
                'name' => (string)($u['name'] ?? $uUser),
                'role' => (string)($u['role'] ?? 'user'),
            ];

            // Beim Login: User als "anwesend" markieren (oder 'auto' wenn du willst)
            CRM_LoginStatus_SetManualState($uUser, 'online');

            return true;
        }
    }

    return false;
}

function CRM_Auth_Logout(): void
{
    // Vor Logout: User als "off" markieren (oder 'away' wenn du willst)
    $u = CRM_Auth_User();
    $userKey = (string)($u['user'] ?? '');
    if ($userKey !== '') {
        CRM_LoginStatus_SetManualState($userKey, 'off');
    }

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
    if ($idle <= 0) { return; }

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

function CRM_Auth_RequireLoginApi(): void
{
    if (!CRM_Auth_IsLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    CRM_Auth_TouchSession();
}
