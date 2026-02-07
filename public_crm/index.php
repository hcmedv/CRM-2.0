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
    
  
  </div>
</section>




</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
