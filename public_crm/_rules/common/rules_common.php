<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_rules/common/rules_common.php
 *
 * Zweck:
 * - Gemeinsame Normalisierungen/Helper für alle Processor (pbx, teamviewer, camera, ...)
 * - Pure Functions (keine harte Pfadverdrahtung)
 *
 * Hinweis zu Dateiquellen:
 * - Falls verfügbar, können Dateipfade aus settings_crm.php -> ['files'] gelesen werden:
 *   RULES_COMMON_FileFromSettings('KUNDEN_JSON') etc.
 * - Diese Helper-Funktion ist optional; Regeln können weiterhin "best-effort" ohne Pfade laufen.
 *
 * Export (Prefix RULES_COMMON_):
 * - RULES_COMMON_Str($v): string
 * - RULES_COMMON_NormLower(string $s): string
 * - RULES_COMMON_ArrayGet($a, array $path, $default=null)
 * - RULES_COMMON_ToEpoch($v): ?int
 * - RULES_COMMON_IsoToTs(string $iso): int
 * - RULES_COMMON_ExtractKN(string $text): ?string
 * - RULES_COMMON_NormalizePhone(string $raw, string $defaultCountry = 'DE'): string
 * - RULES_COMMON_UniqueTags(array $tags): array
 * - RULES_COMMON_CleanNotes(string $s): string
 * - RULES_COMMON_FileFromSettings(string $key, string $fallback=''): string
 */

function RULES_COMMON_Str($v): string
{
    return trim((string)($v ?? ''));
}

function RULES_COMMON_NormLower(string $s): string
{
    return strtolower(trim($s));
}

function RULES_COMMON_ArrayGet($a, array $path, $default = null)
{
    if (!is_array($a)) { return $default; }

    $cur = $a;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) { return $default; }
        $cur = $cur[$k];
    }
    return $cur;
}

/*
 * RULES_COMMON_ToEpoch
 * Akzeptiert:
 * - int/float (sekunden)
 * - string digits (sekunden)
 * - ISO/DateTime-string
 */
function RULES_COMMON_ToEpoch($v): ?int
{
    if ($v === null) { return null; }

    if (is_int($v)) { return ($v > 0) ? $v : null; }
    if (is_float($v)) {
        $i = (int)floor($v);
        return ($i > 0) ? $i : null;
    }

    $s = RULES_COMMON_Str($v);
    if ($s === '') { return null; }

    if (ctype_digit($s)) {
        $i = (int)$s;
        return ($i > 0) ? $i : null;
    }

    try {
        $dt = new DateTimeImmutable($s);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

function RULES_COMMON_IsoToTs(string $iso): int
{
    $iso = trim($iso);
    if ($iso === '') { return 0; }

    $ts = strtotime($iso);
    return ($ts === false) ? 0 : (int)$ts;
}

/*
 * RULES_COMMON_ExtractKN
 * Best-effort:
 * - "#10032"
 * - "KN 10032" / "kn10032"
 */
function RULES_COMMON_ExtractKN(string $text): ?string
{
    $text = trim($text);
    if ($text === '') { return null; }

    if (preg_match('/(?:#|kn\s*)(\d{4,6})/i', $text, $m)) {
        $kn = trim((string)($m[1] ?? ''));
        return ($kn !== '') ? $kn : null;
    }
    return null;
}

/*
 * RULES_COMMON_NormalizePhone
 * Ziel: stabile Vergleichbarkeit (phone-map / contact-match), kein perfektes E.164.
 * Ergebnis: nur Ziffern (kein '+')
 *
 * - entfernt alles außer Ziffern und führendes '+'
 * - "00" -> "+"
 * - wenn kein '+' vorhanden:
 *   - DE: führende 0 entfernen -> +49
 * - final: '+' entfernen, nur digits zurückgeben
 */
function RULES_COMMON_NormalizePhone(string $in, string $country = 'DE'): string
{
    $s = trim($in);
    if ($s === '') { return ''; }

    $s = preg_replace('/[^\d+]/', '', $s);
    if (!is_string($s)) { return ''; }
    $s = trim($s);
    if ($s === '') { return ''; }

    if (strpos($s, '00') === 0) {
        $s = '+' . substr($s, 2);
    }

    $cc = '49'; // DE

    if (strpos($s, '+') === 0) {
        $s = substr($s, 1);
    }

    if (strpos($s, '0') === 0) {
        $s = $cc . ltrim($s, '0');
    }

    $s = preg_replace('/\D/', '', $s);
    return is_string($s) ? $s : '';
}

function RULES_COMMON_UniqueTags(array $tags): array
{
    $out = [];
    foreach ($tags as $t) {
        if (!is_string($t)) { continue; }
        $t = trim($t);
        if ($t === '') { continue; }
        $t = preg_replace('/\s+/', ' ', $t);
        if (!is_string($t)) { continue; }
        $t = strtolower($t);
        $out[] = $t;
    }
    return array_values(array_unique($out));
}

function RULES_COMMON_CleanNotes(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim($s);
    if ($s === '') { return ''; }

    $s = preg_replace("/[ \t]+/", " ", $s);
    if (!is_string($s)) { return ''; }

    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    if (!is_string($s)) { return ''; }

    return trim($s);
}



/*
 * RULES_COMMON_File
 * Kompatibilitäts-Alias:
 * - Alte Aufrufer erwarten RULES_COMMON_File('CONTACTS_JSON')
 * - Intern wird settings_crm.php -> ['files'] genutzt (via CRM_CFG('files',...))
 */
function RULES_COMMON_File(string $key, string $fallback = ''): string
{
    return RULES_COMMON_FileFromSettings($key, $fallback);
}


/*
 * RULES_COMMON_FileFromSettings
 * Liest Pfade aus settings_crm.php -> ['files'] (über CRM_CFG()).
 * Beispiel-Keys:
 * - KUNDEN_JSON
 * - KUNDEN_PHONE_MAP_JSON
 * - CONTACTS_JSON
 */
function RULES_COMMON_FileFromSettings(string $key, string $fallback = ''): string
{
    if (!function_exists('CRM_CFG')) { return $fallback; }

    $files = (array)CRM_CFG('files', []);
    $p = trim((string)($files[$key] ?? ''));

    return ($p !== '') ? $p : $fallback;
}



