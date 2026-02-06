<?php
declare(strict_types=1);

define('CRM_PAGE_TITLE', 'Start');
define('CRM_PAGE_ACTIVE', 'start');
define('CRM_SUBNAV_HTML', '<a class="subnav__chip subnav__chip--active" href="#">Übersicht</a>');

require_once __DIR__ . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';
?>

<div class="grid grid--start">

  <section class="card">
    <div class="card__title">Vorgänge</div>
    <div class="card__body"></div>
  </section>

  <section class="card">
    <div class="card__title">Dokumente</div>
    <div class="card__body"></div>
  </section>

  <section class="card">
    <div class="card__title">Stammdaten</div>
    <div class="card__body"></div>
  </section>

  <section class="card card--wide">
    <div class="card__title">Status</div>
    <div class="card__body"></div>
  </section>



<section class="card card--wide">
  <div class="card__title">Status</div>
  <div class="card__body">
    <pre style="white-space:pre-wrap;margin:0">
<?php
echo "CRM_ROOT:  " . (defined('CRM_ROOT') ? CRM_ROOT : 'n/a') . PHP_EOL;
echo "CRM_BASE:  " . (defined('CRM_BASE') ? CRM_BASE : 'n/a') . PHP_EOL;
echo "CRM_ENV:   " . (defined('CRM_ENV') ? CRM_ENV : 'n/a') . PHP_EOL;
echo "CRM_DEBUG: " . (defined('CRM_DEBUG') ? (CRM_DEBUG ? 'true' : 'false') : 'n/a') . PHP_EOL;

$mods = (function_exists('CRM_CFG') ? (array)CRM_CFG('modules', []) : []);
echo PHP_EOL . "modules:" . PHP_EOL;
foreach ($mods as $k => $v) {
    echo "  - " . $k . " = " . ((bool)$v ? 'true' : 'false') . PHP_EOL;
}

echo PHP_EOL . "cti.sipgate:" . PHP_EOL;
$cti = (function_exists('CRM_CFG') ? CRM_CFG('cti', null) : null);
if (!is_array($cti)) {
    echo "  cti = null" . PHP_EOL;
} else {
    $sip = (array)($cti['sipgate'] ?? []);
    echo "  enabled: " . (((bool)($sip['enabled'] ?? false)) ? 'true' : 'false') . PHP_EOL;
    echo "  secret_key: " . (string)($sip['secret_key'] ?? '') . PHP_EOL;
    echo "  default_device: " . (string)($sip['default_device'] ?? '') . PHP_EOL;
    echo "  allowed_devices: " . json_encode((array)($sip['allowed_devices'] ?? []), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . "expected settings path:" . PHP_EOL;
foreach ($mods as $module => $enabled) {
    if (!(bool)$enabled) continue;
    $module = trim((string)$module);
    if ($module === '') continue;
    $file = (defined('CRM_BASE') ? (CRM_BASE . '/config/' . $module . '/settings_' . $module . '.php') : '');
    echo "  - " . $module . ": " . $file . "  exists=" . (is_string($file) && $file !== '' && is_file($file) ? 'true' : 'false') . PHP_EOL;
}
?>
    </pre>
  </div>
</section>




</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
