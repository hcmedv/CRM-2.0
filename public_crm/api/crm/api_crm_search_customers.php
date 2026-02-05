<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/*
 * Datei: /public/api/crm/api_crm_search_customers.php
 * Zweck:
 * - Zentrale Kunden-/User-Suche (CRM, LN, CTI)
 * - Read-only
 * - Optionaler Modus: mode=cti (nur Ausgabe-Erweiterung)
 */
 
   
  
require_once __DIR__ . '/../../_inc/bootstrap.php';
require_once CRM_ROOT . '/../../_inc/auth.php';
 
CRM_RequireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$q    = trim((string)($_GET['q'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));
$isCti = ($mode === 'cti');

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

/* -----------------------------
   Helper: Telefon normalisieren
   ----------------------------- */
function CRM_NormalizePhoneE164(string $n): ?string
{
    $n = preg_replace('/[^\d+]/', '', $n);
    if ($n === '') return null;
    if ($n[0] !== '+') {
        // Default: DE
        $n = '+49' . ltrim($n, '0');
    }
    return $n;
}

/* -----------------------------
   Datenquellen laden
   ----------------------------- */
$customers = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_JSON'), ['items' => []]);
$phoneMap  = CRM_LoadJsonFile(CFG_FILE_REQ('KUNDEN_PHONE_MAP_JSON'), []);
$m365      = CRM_LoadJsonFile(CFG_FILE_REQ('M365_CONTACTS_JSON'), ['items' => []]);

$items = [];
$q_lc = mb_strtolower($q);

/* -----------------------------
   Kunden durchsuchen
   ----------------------------- */
foreach (($customers['items'] ?? []) as $row) {
    $hay = mb_strtolower(
        ($row['name'] ?? '') . ' ' .
        ($row['company'] ?? '') . ' ' .
        ($row['customer_number'] ?? '')
    );

    $phones = [];

    // Telefonnummern aus Kundendaten
    foreach (($row['phones'] ?? []) as $p) {
        $e = CRM_NormalizePhoneE164((string)($p['number'] ?? ''));
        if ($e) {
            $phones[] = [
                'number' => $e,
                'label'  => (string)($p['label'] ?? ''),
                'dial'   => $e
            ];
        }
        if (mb_strpos($hay, $q_lc) !== false) break;
    }

    // Treffer über Telefonnummer (phone_map)
    if (isset($phoneMap[$q])) {
        if ((string)$phoneMap[$q] === (string)($row['customer_number'] ?? '')) {
            $hay .= ' ' . $q;
        }
    }

    if (mb_strpos($hay, $q_lc) === false && empty($phones)) {
        continue;
    }

    $primary = $phones[0]['dial'] ?? null;

    $items[] = [
        // bestehend
        'id'              => $row['id'] ?? null,
        'name'            => $row['name'] ?? '',
        'company'         => $row['company'] ?? '',
        'customer_number' => $row['customer_number'] ?? '',
        // neu (optional)
        'phones'          => $phones,
        'primary_phone'   => $primary,
        'avatar_url'      => $row['avatar_url'] ?? null,
    ];
}

/* -----------------------------
   M365 Kontakte (ergänzend)
   ----------------------------- */
foreach (($m365['items'] ?? []) as $c) {
    $hay = mb_strtolower(
        ($c['displayName'] ?? '') . ' ' .
        ($c['companyName'] ?? '')
    );

    if (mb_strpos($hay, $q_lc) === false) continue;

    $phones = [];
    foreach (['mobilePhone','businessPhones','homePhones'] as $k) {
        foreach ((array)($c[$k] ?? []) as $p) {
            $e = CRM_NormalizePhoneE164((string)$p);
            if ($e) {
                $phones[] = [
                    'number' => $e,
                    'label'  => $k,
                    'dial'   => $e
                ];
            }
        }
    }

    if (empty($phones)) continue;

    $items[] = [
        'id'              => $c['id'] ?? null,
        'name'            => $c['displayName'] ?? '',
        'company'         => $c['companyName'] ?? '',
        'customer_number' => null,
        'phones'          => $phones,
        'primary_phone'   => $phones[0]['dial'] ?? null,
        'avatar_url'      => $c['photo'] ?? null,
    ];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
