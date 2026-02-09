<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_rules/common/rules_enrich.php
 *
 * Zweck:
 * - Zentrale, modulübergreifende Anreicherung von Event-Patches
 * - Auflösung von Kunde (KN), Firma, Kontakt (m365) und Telefonen
 * - Nutzung interner Datenquellen (kunden.json, kunden_phone_map.json, contacts.json)
 *
 * WICHTIG – Dateipfade:
 * - Es werden KEINE Pfade in dieser Datei hardcodiert.
 * - Alle internen Datenquellen werden ausschließlich über settings_crm.php -> ['files'] aufgelöst
 *   (via RULES_COMMON_FileFromSettings()).
 *
 * Erwartete Keys in settings_crm.php:
 *   'files' => [
 *     'KUNDEN_JSON'           => '/data/kunden.json',
 *     'KUNDEN_PHONE_MAP_JSON' => '/data/kunden_phone_map.json',
 *     'CONTACTS_JSON'         => '/data/contacts.json',
 *   ]
 *
 * Ablauf:
 * - Processor baut Patch (rules_pbx / rules_teamviewer / …)
 * - rules_common.php ist bereits geladen
 * - RULES_ENRICH_Apply() reichert den Patch an
 * - Writer validiert & schreibt
 *
 * Export:
 * - RULES_ENRICH_Apply(array $eventPatch, array $ctx = []): array
 */

/* ===================================================================================================================== */
/* ENTRYPOINT                                                                                                           */
/* ===================================================================================================================== */

function RULES_ENRICH_Apply(array $eventPatch, array $ctx = []): array
{
    $patch = $eventPatch;

    if (!isset($patch['display']) || !is_array($patch['display'])) {
        $patch['display'] = [];
    }
    if (!isset($patch['display']['tags']) || !is_array($patch['display']['tags'])) {
        $patch['display']['tags'] = [];
    }

    /* =====================================================================
     * 1) Phone normalisieren + Kandidaten sammeln
     * ===================================================================== */
    $phones = [];

    if (isset($patch['display']['phone']) && is_string($patch['display']['phone'])) {
        $np = RULES_COMMON_NormalizePhone($patch['display']['phone'], 'DE');
        if ($np !== '') {
            $patch['display']['phone'] = $np;
            $phones[] = $np;
        }
    }

    foreach ([
        RULES_COMMON_ArrayGet($patch, ['meta','pbx','raw','from'], null),
        RULES_COMMON_ArrayGet($patch, ['meta','pbx','raw','to'], null),
    ] as $v) {
        if (is_string($v) && trim($v) !== '') {
            $np = RULES_COMMON_NormalizePhone($v, 'DE');
            if ($np !== '') {
                $phones[] = $np;
            }
        }
    }

    $phones = array_values(array_unique($phones));

    /* =====================================================================
     * 2) Contacts (m365) – Name / crm_contact_id
     * ===================================================================== */
    $contacts    = RULES_ENRICH_LoadContacts();
    $contactsIdx = RULES_ENRICH_ContactsIndexByPhone($contacts);

    $contactHit = null;
    foreach ($phones as $p) {
        if (isset($contactsIdx[$p])) {
            $contactHit = $contactsIdx[$p];
            break;
        }
    }

    if (is_array($contactHit)) {
        $name = trim((string)($contactHit['name'] ?? ''));
        $cid  = trim((string)($contactHit['id'] ?? ''));
        $src  = trim((string)($contactHit['src'] ?? ''));

        if ($name !== '') { $patch['display']['name'] = $name; }
        if ($cid  !== '') { $patch['display']['crm_contact_id'] = $cid; }
        if ($src  !== '') {
            $patch['display']['contact_source'] = $src;
            $patch['display']['tags'][] = $src;
        }
    }

    /* =====================================================================
     * 3) Customer (KN) ermitteln + customer_source
     * ===================================================================== */
    $kn = null;
    $customerSource = '';

    // a) bereits im Patch (z. B. TeamViewer group/#KN)
    $kn0 = RULES_COMMON_ArrayGet($patch, ['display','customer','number'], null);
    if (is_string($kn0) && trim($kn0) !== '') {
        $kn = trim($kn0);

        $tvKn = RULES_COMMON_ArrayGet($patch, ['meta','teamviewer','_norm','kn'], null);
        $customerSource = ($tvKn !== null) ? 'teamviewer_group' : 'patch';
    }

    // b) TeamViewer AdHoc → deviceid-Map
    if (($kn === null || $kn === '') && ($patch['event_source'] ?? '') === 'teamviewer') {
        $deviceId = (string)(
            RULES_COMMON_ArrayGet($patch, ['meta','teamviewer','raw','deviceid'], '') ?:
            RULES_COMMON_ArrayGet($patch, ['meta','teamviewer','raw','deviceId'], '')
        );

        if ($deviceId !== '') {
            $tvMap = RULES_ENRICH_LoadTeamviewerAdhocMap(); // deviceid => KN
            if (isset($tvMap[$deviceId])) {
                $kn = trim((string)$tvMap[$deviceId]);
                $customerSource = 'teamviewer_device_map';
            }
        }
    }

    // c) Phone-Map
    if ($kn === null || $kn === '') {
        $phoneMap = RULES_ENRICH_LoadPhoneMap(); // phone => KN
        foreach ($phones as $p) {
            if (isset($phoneMap[$p])) {
                $kn = trim((string)$phoneMap[$p]);
                $customerSource = 'phone_map';
                break;
            }
        }
    }

    if ($kn !== null && $kn !== '') {
        $patch['display']['customer'] = ['number' => $kn];
        if ($customerSource !== '') {
            $patch['display']['customer_source'] = $customerSource;
        }
    }

    /* =====================================================================
     * 4) kunden.json → Firmenname + Kontaktname
     *     (address_no ist der maßgebliche Key!)
     * ===================================================================== */
    if ($kn !== null && $kn !== '') {
        $customerIndex = RULES_ENRICH_LoadCustomersIndex(); // address_no => customer
        $cust = $customerIndex[$kn] ?? null;

        if (is_array($cust)) {
            $company = trim((string)($cust['company'] ?? $cust['name'] ?? ''));
            $cname   = trim((string)($cust['contact_person'] ?? $cust['owner_name'] ?? ''));

            $patch['display']['customer'] = array_filter([
                'number'  => $kn,
                'company' => $company !== '' ? $company : null,
                'name'    => $cname   !== '' ? $cname   : null,
            ], fn($v) => $v !== null);

            // Falls noch kein display.name existiert (TV-Adhoc!)
            if (!isset($patch['display']['name']) && $cname !== '') {
                $patch['display']['name'] = $cname;
            }
        }
    }

    /* =====================================================================
     * 5) Tags normalisieren + Cleanup
     * ===================================================================== */
    $patch['display']['tags'] = RULES_COMMON_UniqueTags($patch['display']['tags']);

    foreach (['name','crm_contact_id','contact_source','customer_source'] as $k) {
        if (isset($patch['display'][$k]) && trim((string)$patch['display'][$k]) === '') {
            unset($patch['display'][$k]);
        }
    }

    if (empty($patch['display']['tags'])) {
        unset($patch['display']['tags']);
    }
    if (empty($patch['display']['customer'])) {
        unset($patch['display']['customer']);
    }

    return $patch;
}






/* ===================================================================================================================== */
/* CUSTOMER / KN DETECTION                                                                                               */
/* ===================================================================================================================== */

function RULES_ENRICH_DetectCustomerNumber(array $patch, array $phoneMap): ?string
{
    // 1) bereits gesetzt?
    $kn = RULES_COMMON_ArrayGet($patch, ['display','customer','number'], null);
    if (is_string($kn) && trim($kn) !== '') { return trim($kn); }

    // 2) meta.*._norm.kn
    foreach (['teamviewer','pbx','camera','user','m365'] as $ns) {
        $kn = RULES_COMMON_ArrayGet($patch, ['meta',$ns,'_norm','kn'], null);
        if (is_string($kn) && trim($kn) !== '') { return trim($kn); }
    }

    // 3) heuristik: aus display.* / notes
    $hay  = (string)RULES_COMMON_ArrayGet($patch, ['display','title'], '');
    $hay .= "\n" . (string)RULES_COMMON_ArrayGet($patch, ['display','subtitle'], '');
    $hay .= "\n" . (string)RULES_COMMON_ArrayGet($patch, ['meta','teamviewer','_norm','notes'], '');
    $cand = RULES_COMMON_ExtractKN($hay);
    if ($cand !== null) { return $cand; }

    // 4) phone-map
    foreach (RULES_ENRICH_CollectPhones($patch) as $p) {
        $np = RULES_COMMON_NormalizePhone($p, 'DE');
        if ($np !== '' && isset($phoneMap[$np])) {
            return (string)$phoneMap[$np];
        }
    }

    return null;
}

function RULES_ENRICH_CollectPhones(array $patch): array
{
    $out = [];

    foreach ([
        RULES_COMMON_ArrayGet($patch, ['display','phone'], null),
        RULES_COMMON_ArrayGet($patch, ['meta','pbx','raw','from'], null),
        RULES_COMMON_ArrayGet($patch, ['meta','pbx','raw','to'], null),
    ] as $v) {
        if (is_string($v) && trim($v) !== '') { $out[] = trim($v); }
    }

    return array_values(array_unique($out));
}

function RULES_ENRICH_ContactsIndexByPhone(array $contacts): array
{
    $idx = [];

    foreach ($contacts as $c) {
        if (!is_array($c)) { continue; }

        $cid  = trim((string)($c['id'] ?? ''));
        $name = trim((string)($c['displayName'] ?? ''));
        $src  = trim((string)($c['source'] ?? ''));

        $phones = $c['phones'] ?? [];
        if (!is_array($phones)) { $phones = []; }

        foreach ($phones as $p) {
            if (!is_string($p) || trim($p) === '') { continue; }

            $np = RULES_COMMON_NormalizePhone($p, 'DE'); // -> digits-only
            if ($np === '') { continue; }

            $idx[$np] = ['id' => $cid, 'name' => $name, 'src' => $src];
        }
    }

    return $idx;
}



/* ===================================================================================================================== */
/* CONTACT MATCHING (contacts.json)                                                                                      */
/* ===================================================================================================================== */

function RULES_ENRICH_FindContactByPatchPhones(array $patch, array $contactsIdx): ?array
{
    if (empty($contactsIdx)) { return null; }

    foreach (RULES_ENRICH_CollectPhones($patch) as $p) {
        $np = RULES_COMMON_NormalizePhone($p, 'DE');
        if ($np === '') { continue; }

        if (isset($contactsIdx[$np]) && is_array($contactsIdx[$np])) {
            return $contactsIdx[$np];
        }
    }

    return null;
}

function RULES_ENRICH_LoadContacts(): array
{
    $path = RULES_COMMON_File('CONTACTS_JSON');
    if ($path === '' || !is_file($path)) { return []; }

    $j = json_decode((string)file_get_contents($path), true);
    if (!is_array($j)) { return []; }

    if (isset($j['items']) && is_array($j['items'])) {
        return $j['items'];
    }

    return $j; // fallback: top-level array
}

/* ===================================================================================================================== */
/* TEAMVIEWER ADHOC MATCHING                                                                                       */
/* ===================================================================================================================== */

function RULES_ENRICH_LoadTeamviewerAdhocMap(): array
{
    $path = RULES_COMMON_FileFromSettings('TEAMVIEWER_ADHOC_MAP_JSON', '');
    if ($path === '' || !is_file($path)) { return []; }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') { return []; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return []; }

    // erwartet: { version, updated_at, map:{ "<deviceid>":{customer_no,...}, ... } }
    $src = $j['map'] ?? null;
    if (!is_array($src)) { return []; }

    $map = [];
    foreach ($src as $deviceId => $row) {
        if (!is_array($row)) { continue; }

        $did = trim((string)$deviceId);
        if ($did === '') { continue; }

        $kn = trim((string)($row['customer_no'] ?? $row['kn'] ?? $row['number'] ?? ''));
        if ($kn === '') { continue; }

        $map[$did] = $kn;
    }

    return $map; // deviceid(string) => KN(string)
}



/* ===================================================================================================================== */
/* DISPLAY BUILDERS                                                                                                      */
/* ===================================================================================================================== */

function RULES_ENRICH_BuildCustomerDisplay(string $kn, $cust): array
{
    $company = '';
    $name    = '';

    if (is_array($cust)) {
        $company = (string)($cust['company'] ?? $cust['firma'] ?? '');
        $name    = (string)($cust['name'] ?? $cust['kontakt'] ?? '');
    }

    return array_filter([
        'number'  => $kn,
        'company' => trim($company) !== '' ? trim($company) : null,
        'name'    => trim($name) !== '' ? trim($name) : null,
    ], fn($v) => $v !== null);
}

function RULES_ENRICH_NormalizeDisplayPhone(array $patch): array
{
    if (!isset($patch['display']) || !is_array($patch['display'])) { return $patch; }
    if (!isset($patch['display']['phone'])) { return $patch; }

    $p = (string)$patch['display']['phone'];
    $np = RULES_COMMON_NormalizePhone($p, 'DE');
    if ($np !== '') {
        $patch['display']['phone'] = $np;
    }

    return $patch;
}

/* ===================================================================================================================== */
/* DATA LOADERS                                                                                                          */
/* ===================================================================================================================== */

function RULES_ENRICH_LoadCustomersIndex(): array
{
    $path = RULES_COMMON_FileFromSettings('KUNDEN_JSON', '');
    if ($path === '' || !is_file($path)) { return []; }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') { return []; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return []; }

    // kunden.json kann sein:
    // A) { meta:{...}, customers:[ {...}, ... ] }
    // B) [ {...}, {...} ]  (fallback)
    $rows = [];

    if (isset($j['customers']) && is_array($j['customers'])) {
        $rows = $j['customers'];
    } elseif (array_keys($j) === range(0, count($j) - 1)) {
        $rows = $j; // numerisches Array
    } else {
        return [];
    }

    $idx = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }

        // Primär-Key ist address_no (dein KN)
        $kn = trim((string)($row['address_no'] ?? $row['number'] ?? $row['kn'] ?? $row['kundenummer'] ?? ''));

        // address_no ist bei dir int -> string normalisieren
        if ($kn === '' && isset($row['address_no']) && is_int($row['address_no'])) {
            $kn = (string)$row['address_no'];
        }

        if ($kn !== '') {
            $idx[$kn] = $row;
        }
    }

    return $idx;
}


function RULES_ENRICH_LoadPhoneMap(): array
{
    $path = RULES_COMMON_FileFromSettings('KUNDEN_PHONE_MAP_JSON', '');
    if ($path === '' || !is_file($path)) { return []; }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') { return []; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return []; }

    // erwartet: { version, updated_at, map:{ "<phone>":{customer_no,...}, ... } }
    $src = $j['map'] ?? null;
    if (!is_array($src)) { return []; }

    $map = [];
    foreach ($src as $phone => $row) {
        if (!is_array($row)) { continue; }

        $kn = trim((string)($row['customer_no'] ?? $row['kn'] ?? $row['number'] ?? ''));
        if ($kn === '') { continue; }

        $np = RULES_COMMON_NormalizePhone((string)$phone, 'DE');
        if ($np !== '') { $map[$np] = $kn; }
    }

    return $map;
}
