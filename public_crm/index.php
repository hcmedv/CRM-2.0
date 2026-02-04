<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/index.php
 * Zweck:
 * - Startseite (Skeleton)
 * - 3 Cards oben, 1 breite Card unten
 * - Keine Module, keine Links, nur Platzhalter
 */

define('CRM_PAGE_TITLE', 'Start');
define('CRM_PAGE_ACTIVE', 'start');
define('CRM_SUBNAV_HTML', '<a class="subnav-block__chip subnav-block__chip--active" href="#">Übersicht</a>');

require_once __DIR__ . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';

?>

<div class="grid-block grid-block--start">

  <section class="card-block">
    <div class="card-block__title">Vorgänge</div>
    <div class="card-block__body">
      <!-- Placeholder -->
    </div>
  </section>

  <section class="card-block">
    <div class="card-block__title">Dokumente</div>
    <div class="card-block__body">
      <!-- Placeholder -->
    </div>
  </section>

  <section class="card-block">
    <div class="card-block__title">Stammdaten</div>
    <div class="card-block__body">
      <!-- Placeholder -->
    </div>
  </section>

  <section class="card-block card-block--wide">
    <div class="card-block__title">Status</div>
    <div class="card-block__body">
      <!-- Placeholder -->
    </div>
  </section>

</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
