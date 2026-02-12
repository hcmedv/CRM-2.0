<?php
declare(strict_types=1);

/*
 * Datei: /public/_inc/bootstrap.php
 * Zweck:
 * - Settings laden (config/settings_crm.php -> return array)
 * - Module Settings Auto-Import: /config/<modul>/settings_<modul>.php (wenn modules[modul] === true)
 * - Module Secrets Auto-Import:  /config/<modul>/secrets_<modul>.php  (wenn modules[modul] === true)
 * - Secrets werden separat gehalten (__CRM_SECRET), Zugriff über CRM_SECRET() / CRM_MOD_SECRET()
 * - Debug/Error Handling (pro Script eigenes php_*.log)
 * - Session starten + Session-Guard (Idle/Max/IP-Profil)
 * - Basis-Helper (CRM_CFG, CFG_FILE_REQ, CRM_LoadJsonFile)
 *
 * Konvention:
 * - settings_<modul>.php liefert: return ['<modul>' => [ ... ]];
 * - secrets_<modul>.php  liefert: return ['<modul>' => [ ... ]];
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

$__CRM_SECRET    = [];
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
 * - Secrets werden pro Modul in $__CRM_SECRET[<modul>] gespeichert
 *
 * WICHTIG (Einheitliche Secrets-Struktur):
 * - secrets_<modul>.php liefert: return ['<modul>' => ['api_token'=>...]]
 * - Im Bootstrap wird automatisch die innere Ebene ['<modul>'] extrahiert,
 *   damit CRM_SECRET('<modul>.api_token') funktioniert.
 */
function CRM_LoadModuleSettingsAndSecrets(array $baseConfig): array
{
    global $__CRM_SECRET, $__CRM_BOOT_WARN;

    if (!defined('CRM_BASE')) { return $baseConfig; }

    $mods = $baseConfig['modules'] ?? [];
    if (!is_array($mods)) { return $baseConfig; }

    foreach ($mods as $module => $enabled) {
        if (!(bool)$enabled) { continue; }

        $module = trim((string)$module);
        if ($module === '') { continue; }

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
                    // Einheitlich: outer key == modulname
                    if (isset($sec[$module]) && is_array($sec[$module])) {
                        $__CRM_SECRET[$module] = $sec[$module];
                    } else {
                        // Fallback: akzeptiere "flache" Secrets, aber logge Warnung (für Migration)
                        $__CRM_SECRET[$module] = $sec;
                        $__CRM_BOOT_WARN[] = '[CRM] secrets not wrapped by module key (fallback used): ' . $secretsFile;
                    }
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
 * 0003 - CRM_CFG
 * Zugriff auf globale Config (settings_crm.php + gemergte modul settings_*).
 */
function CRM_CFG(string $key, mixed $default = null): mixed
{
    global $__CRM_CONFIG;

    if (!is_array($__CRM_CONFIG)) { return $default; }
    return array_key_exists($key, $__CRM_CONFIG) ? $__CRM_CONFIG[$key] : $default;
}

/*
 * 0004 - CRM_SECRET
 * Zugriff auf modulare Secrets.
 *
 * Pfadnotation:
 * - "<modul>.<key>" oder "<modul>.<nested>.<key>"
 * Beispiel:
 * - "teamviewer.api_token"
 */
function CRM_SECRET(string $path, mixed $default = null): mixed
{
    global $__CRM_SECRET;

    $path = trim($path);
    if ($path === '') { return $default; }

    $parts = explode('.', $path);
    if (count($parts) < 2) { return $default; }

    $mod = trim((string)array_shift($parts));
    if ($mod === '' || !isset($__CRM_SECRET[$mod]) || !is_array($__CRM_SECRET[$mod])) { return $default; }

    $cur = $__CRM_SECRET[$mod];
    foreach ($parts as $p) {
        $k = trim((string)$p);
        if ($k === '' || !is_array($cur) || !array_key_exists($k, $cur)) { return $default; }
        $cur = $cur[$k];
    }

    return $cur;
}

######## MODULE HELPER ##################################################################################################################

/*
 * 0101 - CRM_MOD_CFG
 * Liefert Modul-Settings aus settings_<modul>.php.
 *
 * Beispiel:
 *   CRM_MOD_CFG('teamviewer', 'api_base', 'https://...')
 */
function CRM_MOD_CFG(string $mod, string $key, mixed $default = null): mixed
{
    $mod = trim($mod);
    $key = trim($key);

    if ($mod === '' || $key === '') { return $default; }

    $cfg = CRM_CFG($mod, null);
    if (!is_array($cfg)) { return $default; }

    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/*
 * 0102 - CRM_MOD_SECRET
 * Liefert Modul-Secrets aus secrets_<modul>.php.
 *
 * Beispiel:
 *   CRM_MOD_SECRET('teamviewer', 'api_token', '')
 */
function CRM_MOD_SECRET(string $mod, string $key, mixed $default = null): mixed
{
    $mod = trim($mod);
    $key = trim($key);

    if ($mod === '' || $key === '') { return $default; }

    return CRM_SECRET($mod . '.' . $key, $default);
}

/*
 * 0103 - CRM_MOD_PATH
 * Liefert standardisierte Modulpfade (unterhalb paths.*):
 * - data, log, tmp, config
 *
 * Beispiel:
 *   CRM_MOD_PATH('teamviewer', 'data') . '/tv_poll_raw.json'
 */
function CRM_MOD_PATH(string $mod, string $type): string
{
    $mod  = trim($mod);
    $type = trim($type);

    $paths = (array)CRM_CFG('paths', []);
    $base  = $paths[$type] ?? '';

    if ($mod === '' || !is_string($base) || trim($base) === '') { return ''; }

    return rtrim($base, '/') . '/' . $mod;
}

/*
 * 0104 - CRM_LoginPath
 * Liefert Login-bezogene Dateien anhand der Konvention settings_login.php:
 *   return ['login'=>['files'=>['filename_mitarbeiter'=>..., 'filename_mitarbeiter_status'=>...]]];
 *
 * Pfadbasis kommt aus CRM_MOD_PATH('login','data')  => <paths.data>/login
 * Kein Hardcode von /data/login im Endpoint.
 */
function CRM_LoginPath(string $key): string
{
    $key = trim($key);
    if ($key === '') { return ''; }

    // Modul aktiv?
    $mods = (array)CRM_CFG('modules', []);
    if (!(bool)($mods['login'] ?? false)) { return ''; }

    // Basisverzeichnis für Login-Dateien: <paths.data>/login
    $base = CRM_MOD_PATH('login', 'data');
    if ($base === '') {
        $base = CRM_BASE . '/data/login';
    }


    // Dateinamen aus settings_login.php
    $files = (array)CRM_MOD_CFG('login', 'files', []);
    $map = [
        'mitarbeiter' => (string)($files['filename_mitarbeiter'] ?? ''),
        'status'      => (string)($files['filename_mitarbeiter_status'] ?? ''),
    ];

    $fn = trim((string)($map[$key] ?? ''));
    if ($fn === '') { return ''; }

    return rtrim($base, '/') . '/' . $fn;
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
    ini_set('session.cookie_lifetime', '0');
}

######## SESSION START ##################################################################################################################

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CRMSESSID_V2');
    session_start();
}

######## SESSION GUARD (IDLE + MAX LIFETIME + IP PROFIL) ###############################################################################

function CRM_GetRemoteIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return trim($ip);
}

function CRM_IsApiRequest(): bool
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/api/') !== false) { return true; }

    $xhr = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if (strtolower($xhr) === 'xmlhttprequest') { return true; }

    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false) { return true; }

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

    header('Location: /login/');
    exit;
}

function CRM_SessionGuard(): void
{

    $now = time();

    require_once CRM_ROOT . '/_inc/crm_user_context.php';

    // Profil nur sinnvoll, wenn eingeloggt – sonst remote
    $isLoggedIn = isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user']);
    $profile = $isLoggedIn ? CRM_UserContext_EnsureProfile() : 'remote';

    $idleOffice   = (int)CRM_CFG('session_idle_timeout_office_sec', 0);
    $idleRemote   = (int)CRM_CFG('session_idle_timeout_remote_sec', 0);
    $idleFallback = (int)CRM_CFG('session_idle_timeout_sec', 0);

    $idle = 0;
    if ($profile === 'office' && $idleOffice > 0) { $idle = $idleOffice; }
    elseif ($profile !== 'office' && $idleRemote > 0) { $idle = $idleRemote; }
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

    $_SESSION['last_activity'] = $now;

    // Mirror/Compat
    $_SESSION['crm_profile']     = $profile;
    $_SESSION['session_profile'] = $profile;
}


error_log('[DBG] uri=' . ($_SERVER['REQUEST_URI'] ?? '') .
    ' accept=' . ($_SERVER['HTTP_ACCEPT'] ?? '') .
    ' script=' . ($_SERVER['SCRIPT_NAME'] ?? '') .
    ' file=' . ($_SERVER['SCRIPT_FILENAME'] ?? '') .
    ' docroot=' . ($_SERVER['DOCUMENT_ROOT'] ?? '') .
    ' isApi=' . (CRM_IsApiRequest() ? '1' : '0')
);


CRM_SessionGuard();

######## JSON Datei einlesen ############################################################################################################

function CRM_LoadJsonFile(string $file, array $default = []): array
{
    if (!is_file($file)) { return $default; }

    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') { return $default; }

    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}
