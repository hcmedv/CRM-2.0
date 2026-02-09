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
    
  <?php
echo '<pre style="font-size:12px;line-height:1.4">';

$cfgAll = CRM_CFG(); // ALLE Module


echo "=== ALL CONFIG KEYS ===\n";
echo implode("\n", array_keys($cfgAll)) . "\n\n";

if (!isset($cfgAll['pbx'])) {
    echo "PBX CONFIG: NOT FOUND\n";
} else {
    echo "PBX CONFIG FOUND\n";
    echo json_encode($cfgAll['pbx'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\nPBX raw_store.enabled = ";
var_export(CRM_MOD_CFG('pbx', 'raw_store.enabled', 'MISSING'));

echo "\nPBX data_path = ";
var_export(CRM_MOD_PATH('pbx', 'data'));

echo "\n</pre>";


?>


  </div>
</section>




</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
