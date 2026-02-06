<?php
declare(strict_types=1);

/*
 * Datei: /public/_inc/bootstrap.php
 * Zweck:
 * - Settings laden (config/settings_crm.php -> return array)
 * - Module Settings Auto-Import: /config/<modul>/settings_<modul>.php (wenn modules[modul] === true)
 * - Module Secrets Auto-Import:  /config/<modul>/secrets_<modul>.php  (wenn modules[modul] === true)
 * - Secrets werden separat gehalten (__CRM_SECRETS), Zugriff über CRM_SECRET()
 * - Debug/Error Handling (pro Script eigenes php_*.log)
 * - Session starten + Session-Guard (Idle/Max/IP-Profil)
 * - Basis-Helper (CRM_CFG, CFG_FILE_REQ, CRM_LoadJsonFile)
 *
 * Konvention:
 * - Secrets-Keys sind lowercase (z.B. 'sipgate_cti') und Tokens ebenso (token_id, token_secret, ...)
 */

######## BASIS PFADE ####################################################################################################################

$PUBLIC = dirname(__DIR__);          // /public (z.B. /public_crm)
$BASE   = dirname($PUBLIC);          // Projekt-Root

define('CRM_ROOT', $PUBLIC);
define('CRM_BASE', $BASE);

######## SETTINGS LADEN #################################################################################################################

$settingsFile = CRM_BASE . '/config/settings_crm.php';
if (!is_file($settingsFile)) {
    http_response_code(500);
    echo 'CRM bootstrap error: settings_crm.php not found';
    exit;
}

$__CRM_CONFIG = require $settingsFile;
if (!is_array($__CRM_CONFIG)) {
    http_response_code(500);
    echo 'CRM bootstrap error: settings_crm.php must return array';
    exit;
}

######## MODULE SETTINGS + SECRETS AUTO-IMPORT ###########################################################################################

$__CRM_SECRETS   = [];
$__CRM_BOOT_WARN = [];

/*
 * 0001 - CRM_ArrayMergeRecursiveDistinct
 * Rekursives Merge zweier Arrays:
 * - Keys aus $b überschreiben $a
 * - Arrays werden rekursiv gemerged
 * - Numerische Arrays werden ersetzt (kein Merge), um Dubletten zu vermeiden
 */
function CRM_ArrayMergeRecursiveDistinct(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_int($k)) { $a[$k] = $v; continue; }

        if (isset($a[$k]) && is_array($a[$k]) && is_array($v)) {
            $a[$k] = CRM_ArrayMergeRecursiveDistinct($a[$k], $v);
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

/*
 * 0002 - CRM_LoadModuleSettingsAndSecrets
 * Lädt für jedes aktivierte Modul (modules[modul] === true) best-effort:
 * - CRM_BASE . '/config/<modul>/settings_<modul>.php'
 * - CRM_BASE . '/config/<modul>/secrets_<modul>.php'
 *
 * Verhalten:
 * - Fehlende Dateien sind NICHT fatal (nur Warnung)
 * - Exceptions werden abgefangen (CRM bleibt lauffähig)
 * - Settings werden in $__CRM_CONFIG gemerged
 * - Secrets werden pro Modul in $__CRM_SECRETS[<modul>] gespeichert
 */
function CRM_LoadModuleSettingsAndSecrets(array $baseConfig): array
{
    global $__CRM_SECRETS, $__CRM_BOOT_WARN;

    if (!defined('CRM_BASE')) return $baseConfig;

    $mods = $baseConfig['modules'] ?? [];
    if (!is_array($mods)) return $baseConfig;

    foreach ($mods as $module => $enabled) {
        if (!(bool)$enabled) continue;

        $module = trim((string)$module);
        if ($module === '') continue;

        $dir = CRM_BASE . '/config/' . $module;

        // settings_<modul>.php
        $settingsFile = $dir . '/settings_' . $module . '.php';
        if (is_file($settingsFile)) {
            try {
                $add = require $settingsFile;
                if (is_array($add)) {
                    $baseConfig = CRM_ArrayMergeRecursiveDistinct($baseConfig, $add);
                } else {
                    $__CRM_BOOT_WARN[] = '[CRM] settings invalid return (not array): ' . $settingsFile;
                }
            } catch (Throwable $e) {
                $__CRM_BOOT_WARN[] = '[CRM] settings exception: ' . $settingsFile . ' :: ' . $e->getMessage();
            }
        } else {
            $__CRM_BOOT_WARN[] = '[CRM] settings missing (ok): ' . $settingsFile;
        }

        // secrets_<modul>.php
        $secretsFile = $dir . '/secrets_' . $module . '.php';
        if (is_file($secretsFile)) {
            try {
                $sec = require $secretsFile;
                if (is_array($sec)) {
                    $__CRM_SECRETS[$module] = $sec;
                } else {
                    $__CRM_BOOT_WARN[] = '[CRM] secrets invalid return (not array): ' . $secretsFile;
                }
            } catch (Throwable $e) {
                $__CRM_BOOT_WARN[] = '[CRM] secrets exception: ' . $secretsFile . ' :: ' . $e->getMessage();
            }
        } else {
            $__CRM_BOOT_WARN[] = '[CRM] secrets missing (ok): ' . $secretsFile;
        }
    }

    return $baseConfig;
}

$__CRM_CONFIG = CRM_LoadModuleSettingsAndSecrets($__CRM_CONFIG);

/*
 * 0003 - CRM_SECRET
 * Zugriff auf modulare Secrets.
 *
 * Pfadnotation:
 * - "cti.sipgate_cti.token_id"
 *   ^mod ^key-in-secrets ^token-key
 *
 * Rückgabe:
 * - default, wenn nicht vorhanden
 */
function CRM_SECRET(string $path, mixed $default = null): mixed
{
    global $__CRM_SECRETS;

    $path = trim($path);
    if ($path === '') return $default;

    $parts = explode('.', $path);
    if (count($parts) < 2) return $default;

    $mod = trim((string)array_shift($parts));
    if ($mod === '' || !isset($__CRM_SECRETS[$mod]) || !is_array($__CRM_SECRETS[$mod])) return $default;

    $cur = $__CRM_SECRETS[$mod];
    foreach ($parts as $p) {
        $k = trim((string)$p);
        if ($k === '' || !is_array($cur) || !array_key_exists($k, $cur)) return $default;
        $cur = $cur[$k];
    }

    return $cur;
}

######## CONFIG HELPER ##################################################################################################################
function CRM_CFG(string $key, mixed $default = null): mixed
{
    global $__CRM_CONFIG;
    if (!is_array($__CRM_CONFIG)) return $default;
    return array_key_exists($key, $__CRM_CONFIG) ? $__CRM_CONFIG[$key] : $default;
}

######## CORE FLAGS #####################################################################################################################

define('CRM_DEBUG', (bool)CRM_CFG('debug', false));
define('CRM_ENV', (string)CRM_CFG('env', 'prod'));

######## SETTINGS FILES #################################################################################################################
function CFG_FILE(string $key, ?string $default = null): ?string
{
    $files = (array)CRM_CFG('files', []);
    $v = $files[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function CFG_FILE_REQ(string $key): string
{
    $v = CFG_FILE($key);
    if (!$v) {
        http_response_code(500);
        echo "CRM config error: file '$key' not defined";
        exit;
    }
    return $v;
}

######## DEBUG / ERROR HANDLING #########################################################################################################

$entry = basename($_SERVER['SCRIPT_NAME'] ?? 'unknown.php');
$entry = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $entry);

$phpErrorLog = CRM_BASE . '/log/php_' . $entry . '.log';

ini_set('log_errors', '1');
ini_set('error_log', $phpErrorLog);

if (CRM_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

// Boot-Warnungen jetzt loggen (erst hier ist error_log sauber gesetzt)
if (isset($__CRM_BOOT_WARN) && is_array($__CRM_BOOT_WARN) && !empty($__CRM_BOOT_WARN)) {
    foreach ($__CRM_BOOT_WARN as $w) { error_log($w); }
}

set_exception_handler(function (Throwable $e): void {
    error_log('[EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
});

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

######## SESSION TIME LOGOUT ############################################################################################################

$ttl = (int)CRM_CFG('session_ttl_sec', 0);
if ($ttl > 0) {
    ini_set('session.gc_maxlifetime', (string)$ttl);
    ini_set('session.cookie_lifetime', '0'); // Browser-Session-Cookie (Logout wird über Guard erzwungen)
}

######## SESSION START ##################################################################################################################

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CRMSESSID');
    session_start();
}

######## SESSION GUARD (IDLE + MAX LIFETIME + IP PROFIL) ###############################################################################

function CRM_GetRemoteIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return trim($ip);
}

function CRM_IpIsWhitelisted(string $ip): bool
{
    $wl = (array)CRM_CFG('session_ip_whitelist', []);
    foreach ($wl as $allowed) {
        if (!is_string($allowed)) { continue; }
        if (trim($allowed) === $ip) { return true; }
    }
    return false;
}

function CRM_IsApiRequest(): bool
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/api/') !== false) { return true; }

    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if (stripos($accept, 'application/json') !== false) { return true; }

    return false;
}

function CRM_SessionDestroyAndExit(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    if (CRM_IsApiRequest()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'auth_required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /login/login.php');
    exit;
}

function CRM_SessionGuard(): void
{
    $now = time();

    $ip = CRM_GetRemoteIp();
    $isOffice = CRM_IpIsWhitelisted($ip);

    $idleOffice   = (int)CRM_CFG('session_idle_timeout_office_sec', 0);
    $idleRemote   = (int)CRM_CFG('session_idle_timeout_remote_sec', 0);
    $idleFallback = (int)CRM_CFG('session_idle_timeout_sec', 0);

    $idle = 0;
    if ($isOffice && $idleOffice > 0) { $idle = $idleOffice; }
    elseif (!$isOffice && $idleRemote > 0) { $idle = $idleRemote; }
    else { $idle = $idleFallback; }

    $max = (int)CRM_CFG('session_max_lifetime_sec', 0);

    if (!isset($_SESSION['login_at']) || !is_int($_SESSION['login_at'])) {
        $_SESSION['login_at'] = $now;
    }
    if (!isset($_SESSION['last_activity']) || !is_int($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $now;
    }

    if ($max > 0) {
        $loginAt = (int)$_SESSION['login_at'];
        if (($now - $loginAt) > $max) {
            CRM_SessionDestroyAndExit();
        }
    }

    if ($idle > 0) {
        $last = (int)$_SESSION['last_activity'];
        if (($now - $last) > $idle) {
            CRM_SessionDestroyAndExit();
        }
    }

    $_SESSION['last_activity']   = $now;
    $_SESSION['session_profile'] = $isOffice ? 'office' : 'remote';
}

CRM_SessionGuard();

######## JSON Datei einlesen ############################################################################################################

function CRM_LoadJsonFile(string $file, array $default = []): array
{
    if (!is_file($file)) return $default;

    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') return $default;

    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}
