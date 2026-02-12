<?php
declare(strict_types=1);

/*
 * Datei: /public/events/index.php
 * Zweck:
 * - Modul "Events" (Child unter Topnav "Vorgang")
 * - Kanban-Lanes: Offen / Bearbeitung (Scroll intern), darunter Erledigt / Archiv / Gelöscht
 * - Overlay System B als einziges Detailfenster
 * - Ausgabe ist NUR die Hülle (keine Test-/Demo-Daten). Daten kommen ausschließlich via API/JS.
 */

$MOD = 'events';

define('CRM_PAGE_ACTIVE', 'vorgang');
define('CRM_SUBNAV_ACTIVE', 'events');

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';
?>

<div class="grid grid--events">

  <!-- A -->
  <section class="card card--lane">
    <div class="card__title">Offen</div>
    <div class="card__body">
      <div class="events-lane__scroll" id="events-list-open">
        <div class="muted">Noch keine Events vorhanden.</div>
      </div>
    </div>
  </section>

  <!-- C -->
  <section class="card card--lane">
    <div class="card__title">Bearbeitung</div>
    <div class="card__body">
      <div class="events-lane__scroll" id="events-list-work">
        <div class="muted">Noch keine Events vorhanden.</div>
      </div>
    </div>
  </section>

  <!-- D -->
  <section class="card card--wide">
    <div class="card__title">Erledigt</div>
    <div class="card__body" id="events-list-done">
      <div class="muted">Noch keine Events vorhanden.</div>
    </div>
  </section>

  <!-- E -->
  <section class="card card--wide">
    <div class="card__title">Archiv</div>
    <div class="card__body" id="events-list-archiv">
      <div class="muted">Noch keine Events vorhanden.</div>
    </div>
  </section>

  <!-- F -->
  <section class="card card--wide">
    <div class="card__title">Ausgeblendet / Gelöscht</div>
    <div class="card__body" id="events-list-deleted">
      <div class="muted">Noch keine Events vorhanden.</div>
    </div>
  </section>

</div>

<!-- Overlay (System B) -->
<div class="events-overlay" id="events-overlay" hidden>
  <div class="events-overlay__panel" role="dialog" aria-modal="true" aria-labelledby="events-overlay-title">
    <div class="events-overlay__head">
      <div>
        <div class="events-overlay__title" id="events-overlay-title">Event</div>
        <div class="muted" id="events-overlay-meta" style="font-size:12px;"></div>
      </div>
      <button class="events-overlay__close" type="button" data-overlay-close aria-label="Schließen">×</button>
    </div>

    <div class="events-overlay__body" id="events-overlay-content"></div>

    <div class="events-overlay__foot">
      <div class="muted" id="events-overlay-status">Speichern nur über Status.</div>
    </div>
  </div>
</div>

<!-- NUR produktive JS-Module (kein Test-Filler) -->
<script src="/events/assets/crm_events_30_overlay.js?v=1"></script>
<script src="/events/assets/crm_events_20_render_list.js?v=1"></script>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
