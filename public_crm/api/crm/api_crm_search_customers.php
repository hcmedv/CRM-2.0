<?php
declare(strict_types=1);

/*
 * Datei: /public/api/crm/api_crm_search_customers.php
 * Zweck:
 * - Zentrale Kunden-/User-Suche (CRM, LN, CTI)
 * - Read-only, JSON
 * - Nutzt settings_crm.php -> files[*] via CFG_FILE_REQ()
 * - Ranking (Name/Firma/KN/Keyword/Ort/Telefon/E-Mail)
 */

require_once __DIR__  . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

CRM_Auth_RequireLoginApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q     = trim((string)($_GET['q'] ?? ''));
$mode  = trim((string)($_GET['mode'] ?? ''));         // optional: mode=cti
$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1)  $limit = 10;
if ($limit > 25) $limit = 25;

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function CRM_NormalizePhoneDE(string $in): string
{
    $s = trim($in);
    if ($s === '') return '';

    $s = str_replace([' ', '-', '/', '(', ')', "\t", "\r", "\n"], '', $s);

    $plus = (strpos($s, '+') === 0);
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') return '';

    if ($plus) return '+' . $digits;
    if (strpos($digits, '00') === 0) return '+' . substr($digits, 2);
    if (strpos($digits, '0') === 0) return '+49' . substr($digits, 1);
    return '+' . $digits;
}

function CRM_IsPhoneQuery(string $q): bool
{
    return (bool)preg_match('/[0-9]{3,}/', $q);
}

function CRM_ExtractList(array $j, array $keys): array
{
    foreach ($keys as $k) {
        if (isset($j[$k]) && is_array($j[$k])) return $j[$k];
    }
    // Top-Level Liste?
    if (array_keys($j) === range(0, count($j) - 1)) return $j;
    return [];
}

function CRM_DedupePhones(array $phonesRaw): array
{
    $norm = [];
    foreach ($phonesRaw as $p) {
        $np = CRM_NormalizePhoneDE((string)$p);
        if ($np !== '') $norm[$np] = true;
    }
    return array_keys($norm);
}

function CRM_ScoreMatch(array $fieldsLower, string $qLower): int
{
    $s = 0;

    // starke Felder
    if (($fieldsLower['name'] ?? '') !== '' && strpos($fieldsLower['name'], $qLower) !== false) $s += 70;
    if (($fieldsLower['company'] ?? '') !== '' && strpos($fieldsLower['company'], $qLower) !== false) $s += 60;

    // mittlere Felder
    if (($fieldsLower['keyword'] ?? '') !== '' && strpos($fieldsLower['keyword'], $qLower) !== false) $s += 35;
    if (($fieldsLower['city'] ?? '') !== '' && strpos($fieldsLower['city'], $qLower) !== false) $s += 25;
    if (($fieldsLower['mail'] ?? '') !== '' && strpos($fieldsLower['mail'], $qLower) !== false) $s += 30;

    // Nummern (KN / address_no)
    if (($fieldsLower['kn'] ?? '') !== '' && strpos($fieldsLower['kn'], $qLower) !== false) $s += 80;

    return $s;
}

function CRM_ScorePhoneMatch(array $phonesNorm, string $qPhone): int
{
    if ($qPhone === '') return 0;
    foreach ($phonesNorm as $p) {
        if ($p === $qPhone) return 220;
        if (strpos($p, $qPhone) !== false || strpos($qPhone, $p) !== false) return 130;
    }
    return 0;
}

/* -----------------------------
   Daten laden
   ----------------------------- */
$customersJson = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_JSON'), []);
$phoneMap      = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_PHONE_MAP_JSON'), []);
$m365Json      = CRM_LoadJsonFile(CFG_FILE_REQ('M365_CONTACTS_JSON'), []);

$kundenList = CRM_ExtractList($customersJson, ['customers', 'items']);
$m365List   = CRM_ExtractList($m365Json, ['items', 'contacts']);

$qLower = mb_strtolower($q);
$qPhone = (CRM_IsPhoneQuery($q) ? CRM_NormalizePhoneDE($q) : '');

$ranked = [];

/* -----------------------------
   Kunden: Ranking
   ----------------------------- */
foreach ($kundenList as $c) {
    if (!is_array($c)) continue;

    $kn = (string)($c['address_no'] ?? $c['customer_number'] ?? '');
    $name = (string)($c['name'] ?? '');
    $company = (string)($c['company'] ?? '');
    $keyword = (string)($c['keyword'] ?? '');
    $city = (string)($c['city'] ?? '');

    $mail = '';
    if (isset($c['emails'][0]) && is_string($c['emails'][0])) $mail = (string)$c['emails'][0];
    if ($mail === '' && isset($c['email']) && is_string($c['email'])) $mail = (string)$c['email'];

    $phonesRaw = [];
    foreach ((array)($c['phones'] ?? []) as $p) $phonesRaw[] = $p;
    if (isset($c['phone']) && is_string($c['phone']) && trim($c['phone']) !== '') $phonesRaw[] = (string)$c['phone'];

    // phone_map Treffer als Zusatzsignal (falls Query exakt in Map steht)
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
    if ($score <= 0) continue;

    $ranked[] = [
        's' => $score,
        't' => 'customer',
        'c' => $c,
        'phones' => $phonesNorm,
        'kn' => $kn,
        'name' => $name,
        'company' => $company,
    ];
}

/* -----------------------------
   M365 Kontakte: Ranking
   ----------------------------- */
foreach ($m365List as $c) {
    if (!is_array($c)) continue;

    $id = (string)($c['id'] ?? $c['contactId'] ?? '');
    $name = (string)($c['displayName'] ?? $c['name'] ?? '');
    $company = (string)($c['companyName'] ?? $c['company'] ?? '');

    $mail = '';
    if (isset($c['emailAddresses'][0]['address'])) $mail = (string)$c['emailAddresses'][0]['address'];
    if ($mail === '' && isset($c['mail'])) $mail = (string)$c['mail'];
    if ($mail === '' && isset($c['email'])) $mail = (string)$c['email'];

    $phonesRaw = [];
    foreach (['mobilePhone', 'homePhones', 'businessPhones', 'phones', 'phone'] as $k) {
        if (!isset($c[$k])) continue;
        if (is_string($c[$k]) && $c[$k] !== '') $phonesRaw[] = (string)$c[$k];
        if (is_array($c[$k])) {
            foreach ($c[$k] as $p) {
                if (is_string($p) && $p !== '') $phonesRaw[] = $p;
                if (is_array($p) && isset($p['number'])) $phonesRaw[] = (string)$p['number'];
            }
        }
    }
    $phonesNorm = CRM_DedupePhones($phonesRaw);

    $fieldsLower = [
        'kn'      => '',
        'name'    => mb_strtolower($name),
        'company' => mb_strtolower($company),
        'keyword' => '',
        'city'    => '',
        'mail'    => mb_strtolower($mail),
    ];

    $score = CRM_ScoreMatch($fieldsLower, $qLower) + CRM_ScorePhoneMatch($phonesNorm, $qPhone);
    if ($score <= 0) continue;

    $ranked[] = [
        's' => $score,
        't' => 'm365',
        'c' => $c,
        'phones' => $phonesNorm,
        'id' => $id,
        'name' => $name,
        'company' => $company,
    ];
}

/* -----------------------------
   Sort + Output
   ----------------------------- */
usort($ranked, function($a, $b) {
    return ($b['s'] <=> $a['s']);
});

$items = [];
foreach ($ranked as $r) {
    if ($r['t'] === 'customer') {
        $phones = [];
        foreach ($r['phones'] as $p) {
            $phones[] = ['number' => $p, 'label' => '', 'dial' => $p];
        }
        $primary = $phones[0]['dial'] ?? null;

        $items[] = [
            'source'          => 'customer',
            'id'              => null,
            'name'            => (string)($r['name'] ?? ''),
            'company'         => (string)($r['company'] ?? ''),
            'customer_number' => (string)($r['kn'] ?? ''),
            'phones'          => $phones,
            'primary_phone'   => $primary,
            'avatar_url'      => null,
        ];
    } else {
        $phones = [];
        foreach ($r['phones'] as $p) {
            $phones[] = ['number' => $p, 'label' => '', 'dial' => $p];
        }
        $primary = $phones[0]['dial'] ?? null;

        $hasPhoto = (bool)($r['c']['hasPhoto'] ?? false);
        $cid = (string)($r['id'] ?? '');

        $items[] = [
            'source'          => 'm365',
            'id'              => $cid !== '' ? $cid : null,
            'name'            => (string)($r['name'] ?? ''),
            'company'         => (string)($r['company'] ?? ''),
            'customer_number' => null,
            'phones'          => $phones,
            'primary_phone'   => $primary,
            'avatar_url'      => ($hasPhoto && $cid !== '') ? ('/cti/crm_cti_photo.php?contactId=' . rawurlencode($cid)) : null,
        ];
    }

    if (count($items) >= $limit) break;
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
