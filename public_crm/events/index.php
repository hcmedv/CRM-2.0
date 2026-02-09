<?php
declare(strict_types=1);

/*
 * Datei: /public/events/index.php
 * Zweck:
 * - Modul "Events" (als Child unter Topnav "Vorgang")
 * - Kanban-Lanes (Offen/Bearbeitung) mit internem Scroll
 * - Overlay (System B) als einziges Detailfenster (kein legacy modal)
 */

$MOD = 'events';

define('CRM_PAGE_ACTIVE', 'vorgang');     // Topnav
define('CRM_SUBNAV_ACTIVE', 'events');    // Subnav (children von vorgang)

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
      <div class="events-lane__scroll" id="events-list-open"></div>
    </div>
  </section>

  <!-- C -->
  <section class="card card--lane">
    <div class="card__title">Bearbeitung</div>
    <div class="card__body">
      <div class="events-lane__scroll" id="events-list-work"></div>
    </div>
  </section>

  <!-- D -->
  <section class="card card--wide">
    <div class="card__title">Erledigt</div>
    <div class="card__body" id="events-list-done"></div>
  </section>

  <!-- E -->
  <section class="card card--wide">
    <div class="card__title">Archiv</div>
    <div class="card__body" id="events-list-archiv"></div>
  </section>

  <!-- F -->
  <section class="card card--wide">
    <div class="card__title">Ausgeblendet / Gelöscht</div>
    <div class="card__body" id="events-list-deleted"></div>
  </section>

</div>


<!-- Overlay (System B) -->
<div class="events-overlay" id="events-overlay" hidden>
  <div class="events-overlay__panel" role="dialog" aria-modal="true" aria-labelledby="events-overlay-title">
    <div class="events-overlay__head">
      <div class="events-overlay__title" id="events-overlay-title">Event</div>
      <button class="events-overlay__close" type="button" data-overlay-close aria-label="Schließen">×</button>
    </div>

    <div class="events-overlay__body" id="events-overlay-content"></div>

    <div class="events-overlay__foot">
      <div class="muted" id="events-overlay-status">Speichern nur über Status.</div>
    </div>
  </div>
</div>


<script>
(function(){
  const ov = document.getElementById('events-overlay');
  const ovTitle = document.getElementById('events-overlay-title');
  const ovBody  = document.getElementById('events-overlay-content');
  const ovStat  = document.getElementById('events-overlay-status');

  function FN_OverlayOpen(title, html, statusText)
  {
    if (!ov) return;
    if (ovTitle) ovTitle.textContent = String(title || 'Event');
    if (ovBody)  ovBody.innerHTML = String(html || '');
    if (ovStat)  ovStat.textContent = String(statusText || 'Speichern nur über Status.');
    ov.hidden = false;
  }

  function FN_OverlayClose()
  {
    if (!ov) return;
    ov.hidden = true;
  }

  document.addEventListener('click', function(e){
    const closeBtn = e.target && e.target.closest ? e.target.closest('[data-overlay-close]') : null;
    if (closeBtn) {
      e.preventDefault();
      FN_OverlayClose();
      return;
    }

    const openEl = e.target && e.target.closest ? e.target.closest('[data-overlay-open]') : null;
    if (openEl) {
      e.preventDefault();
      const title  = openEl.getAttribute('data-ov-title') || 'Event';
      const body   = openEl.getAttribute('data-ov-body')  || '';
      const status = openEl.getAttribute('data-ov-status') || 'Speichern nur über Status.';
      FN_OverlayOpen(title, body, status);
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') FN_OverlayClose();
  });

  // Test-Filler (nur Layout)
  function fill(sel, n, label){
    const el = document.querySelector(sel);
    if(!el) return;

    const items = [];
    for(let i=1;i<=n;i++){
      const title = label + ' #' + i;
      const body  =
        '<div style="line-height:1.5;">'
        + '<div style="font-weight:700;margin-bottom:6px;">' + title + '</div>'
        + '<div style="opacity:.8;font-size:12px;margin-bottom:10px;">02.02.2026 15:00 — Dauer 00:02:11</div>'
        + '<div>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br></div>'
        + '<div>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br></div>'
        + '<div>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br></div>'
        + '<div>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br></div>'
        + '<div>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br>Inhalt<br></div>'
        + '</div>';

      items.push(
        '<a href="#" data-overlay-open '
        + 'data-ov-title="' + title.replace(/"/g,'&quot;') + '" '
        + 'data-ov-body="'  + body.replace(/"/g,'&quot;').replace(/\n/g,'') + '" '
        + 'style="text-decoration:none;color:inherit;display:block;">'
        +   '<div style="padding:8px 10px;border:1px solid rgba(0,0,0,.08);border-radius:10px;margin:8px 0;background:#fff;">'
        +     '<div style="font-weight:700;">'+title+'</div>'
        +     '<div style="opacity:.7;font-size:12px;">02.02.2026 15:00 — Dauer 00:02:11</div>'
        +   '</div>'
        + '</a>'
      );
    }
    el.innerHTML = items.join('');
  }

  fill('#events-list-open', 18, 'Offen');
  fill('#events-list-work', 14, 'Bearbeitung');
  fill('#events-list-done', 6, 'Erledigt');
  fill('#events-list-archiv', 4, 'Archiv');
  fill('#events-list-deleted', 3, 'Gelöscht');
})();
</script>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
