<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/router.php
 * Zweck:
 * - Minimal Routing: /, /login
 */

require_once __DIR__ . '/bootstrap.php';

$authFile = __DIR__ . '/auth.php';
if (!is_file($authFile)) {
    http_response_code(500);
    echo 'CRM router error: auth.php not found in /public_crm/_inc/';
    exit;
}
require_once $authFile;

define('CRM_ROUTE_LOGIN', '/login');

define('CRM_PAGES_DIR', CRM_ROOT . '/_inc/pages');                 // neu
define('CRM_ERROR_PAGES_DIR', CRM_PAGES_DIR . '/errors');          // neu

function CRM_Router_Path(): string
{
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    return $path;
}

function CRM_Router_RenderError(int $code): void
{
    http_response_code($code);

    $file = CRM_ERROR_PAGES_DIR . '/' . $code . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }

    // Fallback (sollte praktisch nie greifen)
    echo $code;
}

function CRM_Router_Dispatch(): void
{
    $path = CRM_Router_Path();

    if ($path === CRM_ROUTE_LOGIN) {
        require CRM_ROOT . '/login/index.php';
        return;
    }

    // Logout explizit NICHT als Route anbieten:
    // Logout erfolgt über /login/logout.php (oder wie du es jetzt gelöst hast).

    if ($path === '/') {
        if (!CRM_Auth_IsLoggedIn()) {
            header('Location: ' . CRM_ROUTE_LOGIN);
            exit;
        }
        require CRM_ROOT . '/index.php';
        return;
    }

    CRM_Auth_RequireLogin();
    CRM_Router_RenderError(404);
}
