<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/auth.php
 * Zweck:
 * - Login/Logout
 * - Session-Check (Idle + MaxLifetime)
 * - Rollenprüfung
 * - Datenquelle: /data/login/mitarbeiter.json
 */

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/bootstrap.php';
}

/*
 * Include-Guard (verhindert "Cannot redeclare ..." bei doppeltem require_once-Mix)
 */
if (defined('CRM_AUTH_INCLUDED')) {
    return;
}
define('CRM_AUTH_INCLUDED', true);

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

    CRM_Auth_TouchSession(false);
}

function CRM_Auth_RequireLoginApi(): void
{
    if (!CRM_Auth_IsLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    CRM_Auth_TouchSession(true);
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

            $now = time();
            $_SESSION['crm_login_at']      = $now;
            $_SESSION['crm_last_activity'] = $now;

            // Profil einmalig setzen (für Countdown/Logging konsistent)
            $_SESSION['session_profile'] = CRM_Auth_SessionProfile();

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

/*
 * Ermittelt Client-IP (best effort).
 */
function CRM_Auth_ClientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return trim($ip);
}

/*
 * Profil-Erkennung für Session-Timeout:
 * - office: Client-IP ist in settings_crm.php session_ip_whitelist
 * - remote: sonst
 */
function CRM_Auth_SessionProfile(): string
{
    $ip = CRM_Auth_ClientIp();
    $wl = (array)CRM_CFG('session_ip_whitelist', []);
    $wl = array_values(array_filter(array_map('trim', array_map('strval', $wl))));

    if ($ip !== '' && in_array($ip, $wl, true)) {
        return 'office';
    }
    return 'remote';
}

/*
 * Liefert den Idle-Timeout (Sekunden) passend zum Profil.
 * Fallback: session_idle_timeout_sec
 */
function CRM_Auth_GetIdleTimeoutSec(): int
{
    $fallback = (int)CRM_CFG('session_idle_timeout_sec', 0);

    // bevorzugt aus Session (damit UI/Countdown konsistent ist)
    $profile = (string)($_SESSION['session_profile'] ?? '');
    if ($profile === '') {
        $profile = CRM_Auth_SessionProfile();
        $_SESSION['session_profile'] = $profile;
    }

    if ($profile === 'office') {
        $v = (int)CRM_CFG('session_idle_timeout_office_sec', 0);
        if ($v > 0) { return $v; }
        return $fallback;
    }

    $v = (int)CRM_CFG('session_idle_timeout_remote_sec', 0);
    if ($v > 0) { return $v; }

    return $fallback;
}

/*
 * Timeout-Handler (Page vs API)
 */
function CRM_Auth_HandleExpired(bool $isApi, string $reason): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized', 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /login');
    exit;
}

/*
 * Touch/Check Session:
 * - Idle Timeout (profilabhängig)
 * - Max Lifetime
 *
 * $isApi=true => niemals Redirect/HTML, sondern 401 JSON
 */
function CRM_Auth_TouchSession(bool $isApi): void
{
    $now = time();

    // Max Lifetime
    $maxLife = (int)CRM_CFG('session_max_lifetime_sec', 0);
    $loginAt = (int)($_SESSION['crm_login_at'] ?? 0);
    if ($maxLife > 0 && $loginAt > 0) {
        if (($now - $loginAt) > $maxLife) {
            CRM_Auth_HandleExpired($isApi, 'max_lifetime');
        }
    }

    // Idle Timeout (profilabhängig)
    $idle = CRM_Auth_GetIdleTimeoutSec();
    if ($idle > 0) {
        $last = (int)($_SESSION['crm_last_activity'] ?? 0);
        if ($last > 0 && ($now - $last) > $idle) {
            CRM_Auth_HandleExpired($isApi, 'idle_timeout');
        }
    }

    $_SESSION['crm_last_activity'] = $now;
}
