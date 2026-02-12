<?php
declare(strict_types=1);

/*
 * Datei: /public/api/crm/api_crm_search_customers.php
 * Zweck:
 * - Kunden-Suche (Adressliste) für Bericht Einsatz / LN / CRM
 * - OPTIONAL: M365 Kontakte (bei type=m365|all)
 * - Read-only, JSON
 * - Nutzt settings_crm.php -> files[*] via CFG_FILE_REQ()/CFG_FILE()
 * - Ranking (Firma/Name/KN/Keyword/Ort/Telefon/E-Mail)
 *
 * Query:
 * - q=...              (min. 2 Zeichen)
 * - limit=1..25        (default: 10)
 * - type=customer|m365|all (default: customer)
 */

require_once __DIR__  . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q     = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 10);
$type  = strtolower(trim((string)($_GET['type'] ?? 'customer')));

if ($limit < 1)  { $limit = 10; }
if ($limit > 25) { $limit = 25; }

if (!in_array($type, ['customer','m365','all'], true)) { $type = 'customer'; }

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function CRM_NormalizePhoneDE(string $in): string
{
    $s = trim($in);
    if ($s === '') { return ''; }

    $s = str_replace([' ', '-', '/', '(', ')', "\t", "\r", "\n"], '', $s);

    $plus = (strpos($s, '+') === 0);
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') { return ''; }

    if ($plus) { return '+' . $digits; }
    if (strpos($digits, '00') === 0) { return '+' . substr($digits, 2); }
    if (strpos($digits, '0') === 0) { return '+49' . substr($digits, 1); }
    return '+' . $digits;
}

function CRM_IsPhoneQuery(string $q): bool
{
    return (bool)preg_match('/[0-9]{3,}/', $q);
}

function CRM_ExtractList(array $j, array $keys): array
{
    foreach ($keys as $k) {
        if (isset($j[$k]) && is_array($j[$k])) { return $j[$k]; }
    }
    if (array_keys($j) === range(0, count($j) - 1)) { return $j; }
    return [];
}

function CRM_DedupePhones(array $phonesRaw): array
{
    $norm = [];
    foreach ($phonesRaw as $p) {
        $np = CRM_NormalizePhoneDE((string)$p);
        if ($np !== '') { $norm[$np] = true; }
    }
    return array_keys($norm);
}

function CRM_IsCustomerActive(array $c): bool
{
    if (array_key_exists('inactive', $c)) {
        $v = $c['inactive'];
        if (is_bool($v)) { return ($v === false); }
        $s = trim((string)$v);
        if ($s === '') { return true; }
        if (in_array(strtolower($s), ['1','true','yes','ja'], true)) { return false; }
        return true;
    }

    if (array_key_exists('active', $c)) {
        $v = $c['active'];
        if (is_bool($v)) { return ($v === true); }
        $s = trim((string)$v);
        if ($s === '') { return false; }
        if (in_array(strtolower($s), ['1','true','yes','ja'], true)) { return true; }
        if (in_array(strtolower($s), ['0','false','no','nein'], true)) { return false; }
        return false;
    }

    return true;
}

function CRM_ScoreMatch(array $fieldsLower, string $qLower): int
{
    $s = 0;

    if (($fieldsLower['company'] ?? '') !== '' && strpos($fieldsLower['company'], $qLower) !== false) { $s += 70; }
    if (($fieldsLower['name'] ?? '') !== '' && strpos($fieldsLower['name'], $qLower) !== false) { $s += 55; }

    if (($fieldsLower['kn'] ?? '') !== '' && strpos($fieldsLower['kn'], $qLower) !== false) { $s += 90; }

    if (($fieldsLower['keyword'] ?? '') !== '' && strpos($fieldsLower['keyword'], $qLower) !== false) { $s += 35; }
    if (($fieldsLower['city'] ?? '') !== '' && strpos($fieldsLower['city'], $qLower) !== false) { $s += 25; }
    if (($fieldsLower['mail'] ?? '') !== '' && strpos($fieldsLower['mail'], $qLower) !== false) { $s += 30; }

    return $s;
}

function CRM_ScorePhoneMatch(array $phonesNorm, string $qPhone): int
{
    if ($qPhone === '') { return 0; }
    foreach ($phonesNorm as $p) {
        if ($p === $qPhone) { return 220; }
        if (strpos($p, $qPhone) !== false || strpos($qPhone, $p) !== false) { return 130; }
    }
    return 0;
}

function CRM_PickString(array $a, array $keys, string $def = ''): string
{
    foreach ($keys as $k) {
        if (isset($a[$k]) && is_string($a[$k]) && trim((string)$a[$k]) !== '') { return trim((string)$a[$k]); }
        if (isset($a[$k]) && (is_int($a[$k]) || is_float($a[$k])) && (string)$a[$k] !== '') { return trim((string)$a[$k]); }
    }
    return $def;
}

function CRM_PickEmails(array $c): array
{
    $out = [];

    if (isset($c['emails']) && is_array($c['emails'])) {
        foreach ($c['emails'] as $e) {
            $s = trim((string)$e);
            if ($s !== '') { $out[$s] = true; }
        }
    }

    $single = CRM_PickString($c, ['email', 'mail'], '');
    if ($single !== '') { $out[$single] = true; }

    return array_keys($out);
}

function CRM_PhonesToItems(array $phonesNorm): array
{
    $out = [];
    foreach ($phonesNorm as $p) {
        $out[] = ['number' => $p, 'label' => '', 'dial' => $p];
    }
    return $out;
}

$qLower = mb_strtolower($q);
$qPhone = (CRM_IsPhoneQuery($q) ? CRM_NormalizePhoneDE($q) : '');

$ranked = [];

/* =============================
   CUSTOMERS
   ============================= */
if ($type === 'customer' || $type === 'all') {

    $customersJson = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_JSON'), []);
    $phoneMap      = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_PHONE_MAP_JSON'), []);
    $kundenList    = CRM_ExtractList($customersJson, ['customers', 'items']);

    foreach ($kundenList as $c) {
        if (!is_array($c)) { continue; }
        if (!CRM_IsCustomerActive($c)) { continue; }

        $kn      = CRM_PickString($c, ['address_no', 'customer_number', 'kundennummer', 'kunden_nummer', 'nr'], '');
        $name    = CRM_PickString($c, ['name'], '');
        $company = CRM_PickString($c, ['company', 'firma'], '');
        $keyword = CRM_PickString($c, ['keyword'], '');
        $city    = CRM_PickString($c, ['city', 'ort'], '');

        $street  = CRM_PickString($c, ['street', 'strasse'], '');
        $plz     = CRM_PickString($c, ['postal_code', 'plz'], '');
        $contact = CRM_PickString($c, ['contact_person', 'ansprechpartner'], '');

        $owner = CRM_PickString($c, ['owner_name', 'ownerName', 'owner', 'inhaber', 'inhaber_name', 'geschaeftsfuehrer', 'gf'], '');
        $orderMail = CRM_PickString($c, ['order_email', 'auftragsmail'], '');

        $mail = '';
        $emails = CRM_PickEmails($c);
        if (isset($emails[0])) { $mail = (string)$emails[0]; }

        $phonesRaw = [];
        foreach ((array)($c['phones'] ?? []) as $p) { $phonesRaw[] = $p; }
        $singlePhone = CRM_PickString($c, ['phone', 'telefon'], '');
        if ($singlePhone !== '') { $phonesRaw[] = $singlePhone; }

        if ($q !== '' && isset($phoneMap[$q]) && (string)$phoneMap[$q] === $kn) {
            $phonesRaw[] = $q;
        }

        $phonesNorm = CRM_DedupePhones($phonesRaw);

        $fieldsLower = [
            'kn'      => mb_strtolower($kn),
            'name'    => mb_strtolower($name),
            'company' => mb_strtolower($company),
            'keyword' => mb_strtolower($keyword),
            'city'    => mb_strtolower($city),
            'mail'    => mb_strtolower($mail),
        ];

        $score = CRM_ScoreMatch($fieldsLower, $qLower) + CRM_ScorePhoneMatch($phonesNorm, $qPhone);
        if ($score <= 0) { continue; }

        $ranked[] = [
            's'               => $score,
            'source'          => 'customer',
            'id'              => null,
            'customer_number' => $kn,
            'name'            => $name,
            'company'         => $company,
            'street'          => $street,
            'postal_code'     => $plz,
            'city'            => $city,
            'contact_person'  => $contact,
            'owner_name'      => $owner,
            'order_email'     => $orderMail,
            'emails'          => $emails,
            'phones_norm'     => $phonesNorm,
            'keyword'         => $keyword,
            'avatar_url'      => null,
        ];
    }
}

/* =============================
   M365
   Erwartet JSON-Liste mit Objekten wie:
   { id, displayName, givenName, surname, companyName, email, phones:[...] }
   Datei-Pfad: files.CONTACTS_JSON
   ============================= */
if ($type === 'm365' || $type === 'all') {

    $m365File = CFG_FILE('CONTACTS_JSON', null);
    if (is_string($m365File) && $m365File !== '' && is_file($m365File)) {
        $m365Json = CRM_LoadJsonFile($m365File, []);
        $list = CRM_ExtractList($m365Json, ['contacts', 'items']);

        foreach ($list as $c) {
            if (!is_array($c)) { continue; }

            $id      = CRM_PickString($c, ['id'], '');
            $name    = CRM_PickString($c, ['displayName', 'name'], '');
            $company = CRM_PickString($c, ['companyName', 'company'], '');

            $emails = [];
            $email  = CRM_PickString($c, ['email', 'mail'], '');
            if ($email !== '') { $emails[] = $email; }

            $phonesRaw = [];
            foreach ((array)($c['phones'] ?? []) as $p) { $phonesRaw[] = $p; }
            $phonesNorm = CRM_DedupePhones($phonesRaw);

            $fieldsLower = [
                'kn'      => '',
                'name'    => mb_strtolower($name),
                'company' => mb_strtolower($company),
                'keyword' => '',
                'city'    => '',
                'mail'    => mb_strtolower($email),
            ];

            $score = CRM_ScoreMatch($fieldsLower, $qLower) + CRM_ScorePhoneMatch($phonesNorm, $qPhone);
            if ($score <= 0) { continue; }

            // leichte Abwertung vs. Kunden, damit KN/Firma aus Adressliste i.d.R. oben bleibt
            $score -= 5;

            $ranked[] = [
                's'               => $score,
                'source'          => 'm365',
                'id'              => ($id !== '' ? $id : null),
                'customer_number' => '',
                'name'            => $name,
                'company'         => $company,
                'street'          => '',
                'postal_code'     => '',
                'city'            => '',
                'contact_person'  => '',
                'owner_name'      => '',
                'order_email'     => '',
                'emails'          => $emails,
                'phones_norm'     => $phonesNorm,
                'keyword'         => '',
                'avatar_url'      => null,
            ];
        }
    }
}

/* -----------------------------
   Sort + Output
   ----------------------------- */
usort($ranked, function($a, $b) {
    return ($b['s'] <=> $a['s']);
});

$items = [];
foreach ($ranked as $r) {
    $phones = CRM_PhonesToItems((array)($r['phones_norm'] ?? []));

    $emails = array_values((array)($r['emails'] ?? []));
    $email0 = (string)(($emails[0] ?? '') ?: '');

    $items[] = [
        'source'          => (string)($r['source'] ?? 'customer'),
        'id'              => $r['id'] ?? null,

        'customer_number' => (string)($r['customer_number'] ?? ''),
        'company'         => (string)($r['company'] ?? ''),
        'name'            => (string)($r['name'] ?? ''),

        'street'          => (string)($r['street'] ?? ''),
        'postal_code'     => (string)($r['postal_code'] ?? ''),
        'city'            => (string)($r['city'] ?? ''),

        'phones'          => $phones,
        'primary_phone'   => $phones[0]['dial'] ?? null,

        'emails'          => $emails,
        'email'           => $email0,

        'contact_person'  => (string)($r['contact_person'] ?? ''),
        'owner_name'      => (string)($r['owner_name'] ?? ''),
        'order_email'     => (string)($r['order_email'] ?? ''),

        'keyword'         => (string)($r['keyword'] ?? ''),

        'avatar_url'      => $r['avatar_url'] ?? null,
    ];

    if (count($items) >= $limit) { break; }
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
