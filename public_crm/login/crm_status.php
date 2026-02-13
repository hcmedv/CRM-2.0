<?php
declare(strict_types=1);
<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/crm_status.php
 *
 * Zweck:
 * - Zentrale Statusverwaltung für CRM-Benutzer
 * - Verwaltung von:
 *     - manual_state   (auto|online|busy|away|off)
 *     - pbx_busy       (bool)
 *     - logged_in      (bool)
 *     - updated_at     (timestamp|null)
 *
 * Datenquelle (verbindlich):
 *   <crm_data>/core/users_status.json
 *
 * WICHTIG:
 * - Diese Datei ist CORE-Logik.
 * - Kein Zugriff auf /data/login/*
 * - Kein Zugriff auf Public-Verzeichnisse.
 * - Kein Modul darf Statusdateien selbst verwalten.
 *
 * Architektur:
 * - Login setzt logged_in
 * - api_user_status_set.php setzt manual_state
 * - PBX setzt pbx_busy
 * - CRM_Status_Effective() berechnet daraus den effektiven Zustand
 *
 * Effektiv-Logik:
 *   1. logged_in = false       → off
 *   2. manual_state != auto    → manual_state
 *   3. pbx_busy = true         → busy
 *   4. sonst                   → online
 *
 * Technische Regeln:
 * - Atomic Write via .tmp + rename
 * - Keine Fachlogik (keine Workflow-State-Änderung)
 * - Kein Event-Zugriff
 * - Reiner Core-Status-Container
 */



define('CRM_STATUS_FILE', __DIR__ . '/../../data/login/mitarbeiter_status.json');

function CRM_Status_Defaults(): array
{
    return [
        'manual_state' => 'auto',
        'pbx_busy'     => false,
        'logged_in'    => false,
        'updated_at'   => null,
    ];
}

function CRM_Status_Load(): array
{
    if (!is_file(CRM_STATUS_FILE)) return [];
    $json = file_get_contents(CRM_STATUS_FILE);
    $db = json_decode($json, true);
    return is_array($db) ? $db : [];
}

function CRM_Status_Save(array $db): bool
{
    $tmp = CRM_STATUS_FILE . '.tmp';

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    $json = json_encode($db, $flags);
    if ($json === false) {
        return false;
    }

    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, CRM_STATUS_FILE);
}

function CRM_Status_GetUser(string $user): array
{
    $db = CRM_Status_Load();
    $row = $db[$user] ?? [];
    return array_merge(CRM_Status_Defaults(), $row);
}

function CRM_Status_UpdateUser(string $user, array $patch): array
{
    $db = CRM_Status_Load();
    $prev = array_merge(CRM_Status_Defaults(), $db[$user] ?? []);
    $next = array_merge($prev, $patch);
    $db[$user] = $next;
    CRM_Status_Save($db);
    return $next;
}

function CRM_Status_Effective(array $row): string
{
    if (empty($row['logged_in'])) return 'off';
    if (($row['manual_state'] ?? 'auto') !== 'auto') return $row['manual_state'];
    return !empty($row['pbx_busy']) ? 'busy' : 'online';
}
