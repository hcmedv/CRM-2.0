<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/crm_user_context.php
 *
 * Zweck:
 * - Zentrale Session-/Kontext-Erkennung für eingeloggte CRM-User
 * - Ermittelt das Arbeitsprofil (office|remote) anhand
 *   der dem Mitarbeiter zugeordneten IP-Adressen.
 *
 * Datenquelle:
 * - /data/login/mitarbeiter.json
 *
 * Logik (v1):
 * - Wenn REMOTE_ADDR in employee.cti.office_ips → profile = "office"
 * - sonst → profile = employee.cti.default_profile || "remote"
 *
 * Session:
 * - Ergebnis wird in $_SESSION['crm_profile'] gespeichert
 *
 * Hinweise:
 * - Keine CTI-/PBX-Abhängigkeiten
 * - Mitarbeiterdaten werden nur gelesen
 * - Robust gegen fehlende Felder
 */

/* -------------------------------------------------
   Intern: Mitarbeiterdaten laden
   ------------------------------------------------- */
function CRM_UserContext_LoadEmployees(): array
{
    if (!defined('CRM_LOGIN_FILE')) {
        return [];
    }

    $raw = @file_get_contents(CRM_LOGIN_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

/* -------------------------------------------------
   Intern: Mitarbeiter nach User finden
   ------------------------------------------------- */
function CRM_UserContext_GetEmployee(string $user): ?array
{
    $user = trim($user);
    if ($user === '') return null;

    foreach (CRM_UserContext_LoadEmployees() as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['user'] ?? '') === $user) {
            return $row;
        }
    }
    return null;
}

/* -------------------------------------------------
   CRM_UserContext_DetectProfile
 * Ermittelt Profil anhand Mitarbeiter-IP-Zuordnung
 * ------------------------------------------------- */
function CRM_UserContext_DetectProfile(): string
{
    $u = $_SESSION['crm_user']['user'] ?? '';
    $u = trim((string)$u);
    if ($u === '') return 'remote';

    $emp = CRM_UserContext_GetEmployee($u);
    if (!is_array($emp)) return 'remote';

    $cti = (array)($emp['cti'] ?? []);
    $officeIps = (array)($cti['office_ips'] ?? []);
    $default   = (string)($cti['default_profile'] ?? 'remote');

    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    foreach ($officeIps as $allowed) {
        $allowed = trim((string)$allowed);
        if ($allowed !== '' && $ip === $allowed) {
            return 'office';
        }
    }

    return ($default !== '') ? $default : 'remote';
}

/* -------------------------------------------------
   CRM_UserContext_GetProfile
 * ------------------------------------------------- */
function CRM_UserContext_GetProfile(): string
{
    $p = trim((string)($_SESSION['crm_profile'] ?? ''));

    if ($p === 'office' || $p === 'remote') {
        return $p;
    }

    return 'remote';
}

/* -------------------------------------------------
   CRM_UserContext_EnsureProfile
 * ------------------------------------------------- */
function CRM_UserContext_EnsureProfile(): string
{
    if (!isset($_SESSION['crm_profile'])) {
        $_SESSION['crm_profile'] = CRM_UserContext_DetectProfile();
    }
    return CRM_UserContext_GetProfile();
}
