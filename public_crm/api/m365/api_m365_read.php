<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/api/m365/api_m365_read.php
 *
 * Zweck:
 * - HTTP-Trigger für den M365 Reader (Cron/Manuell)
 * - Token-Guard über secrets_m365.php: m365.http.http_access_token
 *
 * Token-Übergabe (einer reicht):
 * - Header:        X-CRM-Token: <token>
 * - Authorization: Bearer <token>
 * - Query:         ?token=<token>
 */

$MOD = 'm365';

define('CRM_IS_API', true);
define('CRM_AUTH_REQUIRED', false);
require_once __DIR__ . '/../../_inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$started = microtime(true);

function FN_M365_ReadApi_GetBearerToken(): string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $hdr = trim((string)$hdr);

    if ($hdr === '') {
        return '';
    }

    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }

    return '';
}

function FN_M365_ReadApi_GetTokenFromRequest(): string
{
    $t = '';

    $t = (string)($_SERVER['HTTP_X_CRM_TOKEN'] ?? '');
    if (trim($t) !== '') {
        return trim($t);
    }

    $t = FN_M365_ReadApi_GetBearerToken();
    if (trim($t) !== '') {
        return trim($t);
    }

    $t = (string)($_GET['token'] ?? '');
    if (trim($t) !== '') {
        return trim($t);
    }

    return '';
}

function FN_M365_ReadApi_LoadSecretToken(): string
{
    // Bevorzugt: zentrale Helper, falls vorhanden
    if (function_exists('CRM_MOD_SECRET')) {
        $tok = (string)CRM_MOD_SECRET('m365', 'jobs_access_token', '');
        if (trim($tok) !== '') {
            return trim($tok);
        }
    }

    // Fallback: direkt Secrets laden
    if (defined('CRM_ROOT')) {
        $p = CRM_ROOT . '/config/m365/secrets_m365.php';
        if (is_file($p)) {
            $sec = (array)require $p;
            $m365 = (array)($sec['m365'] ?? []);
            $http = (array)($m365['http'] ?? []);
            $tok  = (string)($http['http_access_token'] ?? '');
            return trim($tok);
        }
    }

    return '';
}

function FN_M365_ReadApi_ResolveReaderPath(): array
{
    $candidates = [];

    if (defined('CRM_ROOT')) {
        $candidates[] = CRM_ROOT . '/m365/crm_m365_read.php';
        $candidates[] = CRM_ROOT . '/m365/crm_m365_read_new.php';
        $candidates[] = CRM_ROOT . '/_jobs/crm_m365_read.php';
    }

    foreach ($candidates as $p) {
        if (is_file($p)) {
            return ['ok' => true, 'path' => $p, 'tried' => $candidates];
        }
    }

    return ['ok' => false, 'path' => '', 'tried' => $candidates];
}

function FN_M365_ReadApi_Respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------------------------------
// Token Guard
// -------------------------------------------------------------------------------------------------
$expected = FN_M365_ReadApi_LoadSecretToken();
$given    = FN_M365_ReadApi_GetTokenFromRequest();

if ($expected !== '') {
    if ($given === '' || !hash_equals($expected, $given)) {
        FN_M365_ReadApi_Respond(401, [
            'ok'    => false,
            'error' => 'unauthorized',
        ]);
    }
}

// -------------------------------------------------------------------------------------------------
// Run Reader
// -------------------------------------------------------------------------------------------------
$rp = FN_M365_ReadApi_ResolveReaderPath();
if (!$rp['ok']) {
    FN_M365_ReadApi_Respond(500, [
        'ok'    => false,
        'error' => 'reader_not_found',
        'tried' => $rp['tried'],
    ]);
}

try {
    // Reader ausführen (soll selbst guards/intervale prüfen)
    $result = require $rp['path'];

    $ms = (int)round((microtime(true) - $started) * 1000);

    FN_M365_ReadApi_Respond(200, [
        'ok'       => true,
        'reader'   => basename($rp['path']),
        'duration' => $ms,
        'result'   => $result,
    ]);
} catch (Throwable $e) {
    $ms = (int)round((microtime(true) - $started) * 1000);

    FN_M365_ReadApi_Respond(500, [
        'ok'       => false,
        'error'    => 'exception',
        'duration' => $ms,
        'message'  => $e->getMessage(),
        'type'     => get_class($e),
    ]);
}
