<?php
declare(strict_types=1);

/*
  Datei: /public/api/system/api_teamviewer_support_start.php
  Zweck:
  - Internes Test-UI: TV Support Sessions (Multi-Session) anlegen / löschen
  - Persistiert Sessions in JSON (Pfad/Dateiname aus Settings, mit Fallback)
  - Hinweis: intern (Login reicht)
*/

header('Content-Type: text/html; charset=utf-8');

######## BOOTSTRAP + AUTH ###############################################################################################################

require_once dirname(__DIR__, 2) . '/_inc/bootstrap.php';
$authFile = CRM_ROOT . '/_inc/auth.php';
if (is_file($authFile)) {
    require_once $authFile;
    if (function_exists('CRM_Auth_RequireLogin')) {
        CRM_Auth_RequireLogin();
    }
}

######## DEFAULTS (UI) #################################################################################################################

define('TVSUP_DEFAULT_CODE',       '123456');
define('TVSUP_DEFAULT_LINK',       'https://example.com/');
define('TVSUP_DEFAULT_TTL_MIN',    10);
define('TVSUP_DEFAULT_CUSTOMERNO', '1000');
define('TVSUP_TTL_MIN_MIN',        1);
define('TVSUP_TTL_MIN_MAX',        60);
define('TVSUP_MAX_SESSIONS',       200);

######## SETTINGS (STORE-PFAD) #########################################################################################################

/*
 * Storage aus Settings:
 * - paths.data aus settings_crm.php (z.B. /.../data)
 * - teamviewer.support.filename_support_session aus settings_teamviewer.php
 *
 * Fallbacks:
 * - dataBase: CRM_BASE . '/data'
 * - dir:      'teamviewer'
 * - file:     'teamviewer_support_sessions.json'
 */
function FN_DataBase(): string
{
    $paths = (array)CRM_CFG('paths', []);
    $base  = (string)($paths['data'] ?? '');
    if (trim($base) === '') { $base = CRM_BASE . '/data'; }
    return rtrim($base, '/\\');
}

function FN_TvSupportStoreDir(): string
{
    // optional: wenn du später einen Key in settings_teamviewer ergänzt
    $dir = (string)CRM_MOD_CFG('teamviewer', 'support_store_dir', 'teamviewer');
    $dir = trim($dir);
    return ($dir !== '') ? $dir : 'teamviewer';
}

function FN_TvSupportStoreFileName(): string
{
    $support = CRM_MOD_CFG('teamviewer', 'support', []);
    if (is_array($support)) {
        $fn = trim((string)($support['filename_support_session'] ?? ''));
        if ($fn !== '') { return $fn; }
    }
    return 'teamviewer_support_sessions.json';
}

function FN_PathStoreFile(): string
{
    return FN_DataBase() . '/' . FN_TvSupportStoreDir() . '/' . FN_TvSupportStoreFileName();
}

######## HELPER #########################################################################################################################

function FN_NowIso(): string
{
    return date('c');
}

function FN_EnsureDirForFile(string $filePath): bool
{
    $dir = dirname($filePath);
    if (is_dir($dir)) { return true; }
    return @mkdir($dir, 0775, true);
}

function FN_H(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function FN_LoadDbLocked($fp): array
{
    $raw = stream_get_contents($fp);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['version' => 1, 'updated_at' => FN_NowIso(), 'sessions' => []];
    }
    if (!isset($j['version'])) { $j['version'] = 1; }
    if (!isset($j['updated_at'])) { $j['updated_at'] = FN_NowIso(); }
    if (!isset($j['sessions']) || !is_array($j['sessions'])) { $j['sessions'] = []; }
    return $j;
}

function FN_SaveDbLocked($fp, array $db): bool
{
    $db['updated_at'] = FN_NowIso();
    $raw = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($raw === false) { return false; }

    ftruncate($fp, 0);
    rewind($fp);
    $ok = (fwrite($fp, $raw) !== false);
    fflush($fp);
    return $ok;
}

function FN_NewKey(): string
{
    return bin2hex(random_bytes(10));
}

function FN_AddSession(array &$db, string $code, string $link, int $ttlMin, string $customerNo): array
{
    $now = time();

    $entry = [
        'key'               => FN_NewKey(),
        'code'              => $code,
        'customerNo'        => $customerNo,
        'end_customer_link' => $link,
        'created_at'        => $now,
        'expires_at'        => $now + ($ttlMin * 60),
        'used'              => false,
        'used_at'           => 0,
        'used_ip'           => '',
    ];

    if (!isset($db['sessions']) || !is_array($db['sessions'])) {
        $db['sessions'] = [];
    }

    $db['sessions'][] = $entry;

    if (count($db['sessions']) > TVSUP_MAX_SESSIONS) {
        $db['sessions'] = array_slice($db['sessions'], -TVSUP_MAX_SESSIONS);
    }

    return $entry;
}

function FN_DeleteByKey(array &$db, string $key): bool
{
    if (!isset($db['sessions']) || !is_array($db['sessions'])) { return false; }

    $before = count($db['sessions']);
    $db['sessions'] = array_values(array_filter($db['sessions'], function ($s) use ($key) {
        if (!is_array($s)) { return true; }
        return ((string)($s['key'] ?? '') !== $key);
    }));

    return (count($db['sessions']) !== $before);
}

function FN_ClearAll(array &$db): void
{
    $db['sessions'] = [];
}

######## MAIN ###########################################################################################################################

$storeFile = FN_PathStoreFile();
$msg = '';
$lastEntry = null;

$action = (string)($_POST['action'] ?? '');

if (!FN_EnsureDirForFile($storeFile)) {
    $msg = 'ERROR: Konnte Store-Verzeichnis nicht anlegen.';
} else {
    $fp = @fopen($storeFile, 'c+');
    if ($fp === false) {
        $msg = 'ERROR: Konnte Store-Datei nicht öffnen: ' . $storeFile;
    } elseif (!flock($fp, LOCK_EX)) {
        fclose($fp);
        $msg = 'ERROR: Konnte Lock nicht setzen.';
    } else {
        rewind($fp);
        $db = FN_LoadDbLocked($fp);

        if ($action === 'set') {
            $code = trim((string)($_POST['code'] ?? TVSUP_DEFAULT_CODE));
            $link = trim((string)($_POST['link'] ?? TVSUP_DEFAULT_LINK));
            $ttl  = (int)($_POST['ttl_min'] ?? TVSUP_DEFAULT_TTL_MIN);
            $cno  = trim((string)($_POST['customerNo'] ?? TVSUP_DEFAULT_CUSTOMERNO));

            if ($code === '') { $msg = 'ERROR: Code ist leer.'; }
            elseif ($link === '') { $msg = 'ERROR: Link ist leer.'; }
            else {
                if ($ttl < TVSUP_TTL_MIN_MIN) { $ttl = TVSUP_DEFAULT_TTL_MIN; }
                if ($ttl > TVSUP_TTL_MIN_MAX) { $ttl = TVSUP_TTL_MIN_MAX; }

                $lastEntry = FN_AddSession($db, $code, $link, $ttl, $cno);
                $msg = 'OK: Session angelegt (multi).';
            }
        } elseif ($action === 'del') {
            $key = trim((string)($_POST['key'] ?? ''));
            if ($key === '') { $msg = 'ERROR: key ist leer.'; }
            else {
                $ok = FN_DeleteByKey($db, $key);
                $msg = $ok ? 'OK: Session gelöscht.' : 'INFO: key nicht gefunden.';
            }
        } elseif ($action === 'clear') {
            FN_ClearAll($db);
            $msg = 'OK: Alle Sessions gelöscht.';
        }

        $okSave = FN_SaveDbLocked($fp, $db);

        flock($fp, LOCK_UN);
        fclose($fp);

        if (!$okSave) {
            $msg = 'ERROR: Speichern fehlgeschlagen.';
        }
    }
}

/* Current state anzeigen */
$cur = null;
if (is_file($storeFile)) {
    $raw = (string)@file_get_contents($storeFile);
    $arr = json_decode($raw, true);
    if (is_array($arr)) { $cur = $arr; }
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>TV Support Test Set (Multi)</title>
  <style>
    body{ font-family: Arial, sans-serif; margin:20px; }
    .box{ border:1px solid #ccc; border-radius:8px; padding:14px; max-width:900px; }
    label{ display:block; margin-top:10px; }
    input{ width:100%; padding:8px; box-sizing:border-box; }
    .row{ display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
    button{ padding:10px 12px; }
    pre{ background:#f6f6f6; padding:10px; overflow:auto; }
    .msg{ margin:10px 0; font-weight:bold; }
    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media(max-width:900px){ .grid{ grid-template-columns: 1fr; } }
    .small{ color:#666; font-size:12px; }
  </style>
</head>
<body>
  <div class="box">
    <h2>TeamViewer Support (Test) – Multi-Session</h2>

    <div class="msg"><?= FN_H($msg) ?></div>

    <div class="grid">
      <div>
        <h3>Session anlegen</h3>
        <form method="post">
          <input type="hidden" name="action" value="set">

          <label>customerNo</label>
          <input name="customerNo" value="<?= FN_H((string)($_POST['customerNo'] ?? TVSUP_DEFAULT_CUSTOMERNO)) ?>">

          <label>Kurzcode</label>
          <input name="code" value="<?= FN_H((string)($_POST['code'] ?? TVSUP_DEFAULT_CODE)) ?>">

          <label>End-Customer Link (Dummy möglich)</label>
          <input name="link" value="<?= FN_H((string)($_POST['link'] ?? TVSUP_DEFAULT_LINK)) ?>">

          <label>Gültigkeit (Minuten)</label>
          <input name="ttl_min" type="number" min="1" value="<?= (int)($_POST['ttl_min'] ?? TVSUP_DEFAULT_TTL_MIN) ?>">

          <div class="row">
            <button type="submit">Session anlegen</button>
          </div>
        </form>

        <?php if (is_array($lastEntry)) { ?>
          <p class="small"><b>Neu:</b> key=<?= FN_H((string)$lastEntry['key']) ?> | code=<?= FN_H((string)$lastEntry['code']) ?> | customerNo=<?= FN_H((string)$lastEntry['customerNo']) ?></p>
        <?php } ?>

        <h3 style="margin-top:16px;">Session löschen (per key)</h3>
        <form method="post">
          <input type="hidden" name="action" value="del">

          <label>key</label>
          <input name="key" value="">

          <div class="row">
            <button type="submit">Session löschen</button>
          </div>
        </form>

        <h3 style="margin-top:16px;">Alle löschen</h3>
        <form method="post">
          <input type="hidden" name="action" value="clear">
          <button type="submit">Alle Sessions löschen</button>
        </form>
      </div>

      <div>
        <h3>Aktueller State</h3>
        <div><b>Datei:</b> <?= FN_H($storeFile) ?></div>
        <pre><?= FN_H(json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      </div>
    </div>
  </div>
</body>
</html>
