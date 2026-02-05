<?php
declare(strict_types=1);

/*
 * Datei: /public/_inc/bootstrap.php
 * Zweck:
 * - Settings laden (config/settings_crm.php -> return array)
 * - Debug/Error Handling schaltbar
 * - Session starten
 * - Basis-Konstanten/Helper
 */

######## BASIS PFADE ####################################################################################################################

$PUBLIC = dirname(__DIR__);          // /public
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

define('CRM_DEBUG', (bool)($__CRM_CONFIG['debug'] ?? false));
define('CRM_ENV', (string)($__CRM_CONFIG['env'] ?? 'prod'));

######## CONFIG HELPER ##################################################################################################################
function CRM_CFG(string $key, mixed $default = null): mixed
{
    global $__CRM_CONFIG;
    if (!is_array($__CRM_CONFIG)) return $default;
    return array_key_exists($key, $__CRM_CONFIG) ? $__CRM_CONFIG[$key] : $default;
}

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

if (CRM_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

######## SESSION TIME LOGOUT ############################################################################################################

$ttl = (int)CRM_CFG('session_ttl_sec', 0);
if ($ttl > 0) {
    ini_set('session.gc_maxlifetime', (string)$ttl);
    ini_set('session.cookie_lifetime', (string)$ttl);
}

######## SESSION START ##################################################################################################################

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CRMSESSID');
    session_start();
}

