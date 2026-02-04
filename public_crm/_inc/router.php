<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/router.php
 * Zweck:
 * - Minimal Routing: /, /login, /logout
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

function CRM_Router_Path(): string
{
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    return $path;
}

function CRM_Router_Dispatch(): void
{
    $path = CRM_Router_Path();

    if ($path === CRM_ROUTE_LOGIN) {
        require CRM_ROOT . '/login/index.php';
        return;
    }

    if ($path === '/logout') {
        CRM_Auth_Logout();
        return;
    }

    if ($path === '/') {
        if (!CRM_Auth_IsLoggedIn()) {
            header('Location: ' . CRM_ROUTE_LOGIN);
            exit;
        }
        require CRM_ROOT . '/index.php';
        return;
    }

    CRM_Auth_RequireLogin();
    http_response_code(404);
    echo '404';
}
