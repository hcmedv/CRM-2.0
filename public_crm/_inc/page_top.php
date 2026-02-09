<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/page_top.php
 *
 * Zweck:
 * - Startet HTML + App-Header (Topnav + optional Subnav)
 * - Lädt Basis-CSS (/_inc/assets/crm.css)
 * - Lädt Modul-Assets:
 *   a) optional über settings_<modul>.php -> ['<modul>'=>['assets'=>['css'=>[], 'js'=>[]]]]
 *   b) fallback: /<modul>/assets/crm_<modul>.css|js (wenn Datei existiert)
 *
 * Navigation:
 * - Topnav kommt aus settings_crm.php -> 'nav'
 * - Subnav kommt aus nav[].children des aktiven Topnav-Items
 * - Aktiv-Keys werden pro Seite gesetzt:
 *   define('CRM_PAGE_ACTIVE', 'vorgang');
 *   define('CRM_SUBNAV_ACTIVE', 'events');
 */

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/bootstrap.php';
}

$title  = defined('CRM_PAGE_TITLE') ? (string)CRM_PAGE_TITLE : 'CRM';
$active = defined('CRM_PAGE_ACTIVE') ? (string)CRM_PAGE_ACTIVE : 'start';

$subActive = defined('CRM_SUBNAV_ACTIVE') ? (string)CRM_SUBNAV_ACTIVE : '';

$isLoggedIn = isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user']);
$userName   = $isLoggedIn ? (string)($_SESSION['crm_user']['name'] ?? $_SESSION['crm_user']['user'] ?? '') : '';

$nav = CRM_CFG('nav', []);
if (!is_array($nav) || count($nav) === 0) {
    $nav = [
        ['key' => 'start', 'label' => 'Start', 'href' => '/index.php']
    ];
}

$mods       = (array)CRM_CFG('modules', []);
$ctiEnabled = (bool)($mods['cti'] ?? false);

/*
 * CRM_RenderModuleAssets
 * Rendert CSS/JS für ein Modul anhand:
 * - settings: <modul>.assets.css / <modul>.assets.js (relativ zu "/<modul>/")
 * - fallback: "/<modul>/assets/crm_<modul>.css|js" (nur wenn Datei existiert)
 */
function CRM_RenderModuleAssets(string $mod): void
{
    $mod = trim($mod);
    if ($mod === '') { return; }

    $cfg  = CRM_MOD_CFG($mod, 'assets', []);
    $base = '/' . $mod . '/';

    // CSS
    $cssList = [];
    if (is_array($cfg) && isset($cfg['css']) && is_array($cfg['css'])) {
        $cssList = $cfg['css'];
    }

    if (count($cssList) > 0) {
        foreach ($cssList as $css) {
            $css = trim((string)$css);
            if ($css === '') { continue; }
            $href = $base . ltrim($css, '/');
            $abs  = CRM_ROOT . $href;
            if (!is_file($abs)) { continue; }
            echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '?v=1">' . PHP_EOL;
        }
    } else {
        $href = '/' . $mod . '/assets/crm_' . $mod . '.css';
        $abs  = CRM_ROOT . $href;
        if (is_file($abs)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '?v=1">' . PHP_EOL;
        }
    }

    // JS
    $jsList = [];
    if (is_array($cfg) && isset($cfg['js']) && is_array($cfg['js'])) {
        $jsList = $cfg['js'];
    }

    if (count($jsList) > 0) {
        foreach ($jsList as $js) {
            $js = trim((string)$js);
            if ($js === '') { continue; }
            $src = $base . ltrim($js, '/');
            $abs = CRM_ROOT . $src;
            if (!is_file($abs)) { continue; }
            echo '<script defer src="' . htmlspecialchars($src, ENT_QUOTES) . '?v=1"></script>' . PHP_EOL;
        }
    } else {
        $src = '/' . $mod . '/assets/crm_' . $mod . '.js';
        $abs = CRM_ROOT . $src;
        if (is_file($abs)) {
            echo '<script defer src="' . htmlspecialchars($src, ENT_QUOTES) . '?v=1"></script>' . PHP_EOL;
        }
    }
}

/*
 * CRM_BuildSubnavHtmlFromNav
 * Baut Subnav-HTML aus settings_crm.php -> nav[].children für das aktive Topnav-Item.
 */
function CRM_BuildSubnavHtmlFromNav(array $nav, string $activeTopKey, string $activeSubKey): string
{
    $activeTopKey = trim($activeTopKey);
    $activeSubKey = trim($activeSubKey);

    foreach ($nav as $it) {
        if (!is_array($it)) { continue; }
        $k = trim((string)($it['key'] ?? ''));
        if ($k === '' || $k !== $activeTopKey) { continue; }

        $children = $it['children'] ?? null;
        if (!is_array($children) || count($children) === 0) { return ''; }

        $html = '';
        foreach ($children as $ch) {
            if (!is_array($ch)) { continue; }

            $ck   = trim((string)($ch['key'] ?? ''));
            $lbl  = (string)($ch['label'] ?? $ck);
            $href = (string)($ch['href'] ?? '#');

            if ($ck === '' || $lbl === '' || $href === '') { continue; }

            $cls = 'subnav__chip' . (($ck === $activeSubKey) ? ' subnav__chip--active' : '');
            $html .= '<a class="' . htmlspecialchars($cls, ENT_QUOTES) . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                  . htmlspecialchars($lbl, ENT_QUOTES)
                  . '</a>';
        }

        return $html;
    }

    return '';
}

/*
 * Subnav-Quelle:
 * - Wenn CRM_SUBNAV_HTML explizit gesetzt ist: verwende das.
 * - Sonst: aus settings nav[].children automatisch bauen.
 */
$subnavHtml = '';
if (defined('CRM_SUBNAV_HTML') && is_string(CRM_SUBNAV_HTML) && CRM_SUBNAV_HTML !== '') {
    $subnavHtml = (string)CRM_SUBNAV_HTML;
} else {
    $subnavHtml = CRM_BuildSubnavHtmlFromNav($nav, $active, $subActive);
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/favicon.ico">

<title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>

<link rel="stylesheet" href="/_inc/assets/crm.css?v=1">
<link rel="stylesheet" href="/_inc/assets/crm_forms.css?v=1">


<?php
// -------------------------------------------------
// Modul Assets (optional)
// Erwartet: $MOD = '<modul>'
// -------------------------------------------------
$mod = '';
if (isset($MOD) && is_string($MOD)) {
    $mod = trim($MOD);
}
if ($mod !== '') {
    CRM_RenderModuleAssets($mod);
}
?>

<?php if ($ctiEnabled): ?>
<link rel="stylesheet" href="/cti/assets/crm_cti.css?v=1">
<script defer src="/cti/assets/crm_cti_search.js?v=1"></script>
<?php endif; ?>

<?php if (defined('CRM_DEBUG') && CRM_DEBUG): ?>
<meta name="robots" content="noindex,nofollow">
<?php endif; ?>
</head>
<body>

<?php if ($isLoggedIn): ?>



<header class="app-header">

  <div class="topnav bar">
    <div class="bar__inner topnav__inner">
      <div class="topnav__left">
        <?php foreach ($nav as $it):
            $k    = trim((string)($it['key'] ?? ''));
            $lbl  = (string)($it['label'] ?? $k);
            $href = (string)($it['href'] ?? '#');
            if ($k === '') { continue; }
            $cls = 'topnav__item' . (($k === $active) ? ' topnav__item--active' : '');
        ?>
          <a class="<?= htmlspecialchars($cls, ENT_QUOTES) ?>" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= htmlspecialchars($lbl, ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="topnav__right">

      <?php if ($ctiEnabled): ?>
        <!-- CTI Integration -->
        <div class="cti2-nav" id="cti2">
          <input
            class="cti2-input"
            id="cti2-q"
            placeholder="Kunde / Telefon suchen …"
            autocomplete="off"
          />
          <span class="cti2-input__suffix" id="crmSessionCountdown" aria-hidden="true"></span>

          <div class="cti2-dd" id="cti2-dd">
            <div class="cti2-list" id="cti2-list"></div>
            <div class="cti2-status" id="cti2-status">
              <span class="cti2-status__text"></span>
            </div>
          </div>
        </div>
        <!-- End CTI Integration -->
      <?php endif; ?>

        <!-- User Integration -->
        <div class="userbox" id="crmUserBox">

          <span class="sesshint" id="crmSessHint"></span>

          <button class="userbtn userbtn--state-off" id="crmUserBtn" type="button"
                  aria-label="Benutzermenü" aria-haspopup="menu" aria-expanded="false">
            <span class="userbtn__dot" aria-hidden="true"></span>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path fill="currentColor" d="M12 12a4 4 0 1 0-4-4a4 4 0 0 0 4 4Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/>
            </svg>
          </button>

          <div class="userfly" role="menu" aria-label="Benutzermenü">
            <div class="userfly__head">Angemeldet</div>

            <div class="userfly__body">
              <?php if ($userName !== ''): ?>
                <div class="userfly__muted"><?= htmlspecialchars($userName, ENT_QUOTES) ?></div>
              <?php endif; ?>

              <div class="userstatus" data-userstatus>
                <div class="userstatus__head">
                  <div class="userfly__muted">Status:</div>
                  <div class="userfly__muted userstatus__msg" id="crmUserStatusMsg" aria-live="polite"></div>
                </div>

                <div class="userstatus__row">
                  <button class="chip chip--auto" type="button" data-userstatus-set="auto">Auto</button>
                  <button class="chip chip--work" type="button" data-userstatus-set="online">Verfügbar</button>
                  <button class="chip chip--wait" type="button" data-userstatus-set="busy">Beschäftigt</button>
                  <button class="chip chip--closed" type="button" data-userstatus-set="away">Abwesend</button>
                </div>
              </div>

              <a class="userfly__logout" href="/login/logout.php" role="menuitem">Abmelden</a>
            </div>
          </div>
        </div>
        <script defer src="/_inc/assets/crm_user_status.js?v=1"></script>
        <link rel="stylesheet" href="/_inc/assets/crm_user_status.css?v=1">
        <!-- End User Integration -->

      </div>
    </div>
  </div>

  <?php if ($subnavHtml !== ''): ?>
    <div class="subnav bar">
      <div class="bar__inner subnav__inner">
        <?= $subnavHtml ?>
      </div>
    </div>
  <?php endif; ?>

</header>
<?php endif; ?>

<main class="app">
  <div class="page-block">
