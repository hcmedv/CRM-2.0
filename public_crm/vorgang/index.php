<?php
declare(strict_types=1);

/*
 * Datei: /public/vorgang/index.php
 * Zweck:
 * - Modul-Seite "Vorgang" (Start / leer)
 */

$MOD = 'vorgang';

define('CRM_PAGE_ACTIVE', 'vorgang');   // Topnav aktiv
define('CRM_SUBNAV_ACTIVE', 'overview');  // Subnav aktiv

require_once __DIR__ . '/../_inc/bootstrap.php';
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

</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
