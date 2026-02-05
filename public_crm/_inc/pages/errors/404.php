<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/pages/404.php
 * Zweck:
 * - Zentrale 404-Seite
 * - Einheitliches Layout
 */

define('CRM_PAGE_TITLE', '404 – Nicht gefunden');

require_once __DIR__ . '/../_inc/page_top.php';
?>

<div class="card-block card-block--error">
  <h1 class="card-block__title">404</h1>
  <p class="card-block__text">Die angeforderte Seite existiert nicht.</p>
</div>

<?php
require_once __DIR__ . '/../_inc/page_bottom.php';
 