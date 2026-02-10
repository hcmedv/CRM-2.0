<?php
declare(strict_types=1);

/*
 * Datei: /public/events/index.php
 * Zweck:
 * - Modul "Events" (Child unter Topnav "Vorgang")
 * - Lanes (Offen/Bearbeitung) mit internem Scroll
 * - Overlay System B als einziges Detailfenster
 * - TEST Step 2: PHP liefert echte Demo-Events (ohne API)
 */

$MOD = 'events';

define('CRM_PAGE_ACTIVE', 'vorgang');
define('CRM_SUBNAV_ACTIVE', 'events');

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';

/* -------------------------------------------------------------------------------------------------
   STEP 2: echte Demo-Daten aus PHP (später ersetzt durch Reader/API)
   ------------------------------------------------------------------------------------------------- */
$events = [
    [
        'event_id'  => 'evt-001',
        'state'     => 'open',
        'title'     => 'Office: Anmeldung / Passwort reset',
        'meta'      => '02.02.2026 15:00 — Dauer 00:02:11',
        'body_html' => '<div>Erster echter Inhalt aus PHP.<br>Notizen…<br>Weitere Zeilen…</div>',
    ],
    [
        'event_id'  => 'evt-002',
        'state'     => 'open',
        'title'     => 'PBX: Rückruf Kunde ERKO',
        'meta'      => '02.02.2026 16:10 — Dauer 00:05:42',
        'body_html' => '<div>Telefonat geführt.<br>Kurzinfo…</div>',
    ],
    [
        'event_id'  => 'evt-101',
        'state'     => 'work',
        'title'     => 'TeamViewer: Druckerproblem',
        'meta'      => '03.02.2026 09:12 — Dauer 00:12:03',
        'body_html' => '<div>In Bearbeitung.<br>Check Treiber/Spooler…</div>',
    ],
];

/* Lane-Split */
$laneOpen = [];
$laneWork = [];
$laneDone = [];
$laneArchiv = [];
$laneDeleted = [];

foreach ($events as $e) {
    $st = (string)($e['state'] ?? '');
    if ($st === 'open') { $laneOpen[] = $e; continue; }
    if ($st === 'work') { $laneWork[] = $e; continue; }
    if ($st === 'done') { $laneDone[] = $e; continue; }
    if ($st === 'archiv') { $laneArchiv[] = $e; continue; }
    if ($st === 'deleted') { $laneDeleted[] = $e; continue; }
}
?>

<div class="grid grid--events">

  <!-- A -->
  <section class="card card--lane">
    <div class="card__title">Offen</div>
    <div class="card__body">
      <div class="events-lane__scroll" id="events-list-open">
        <?php foreach ($laneOpen as $e):
            $id    = (string)($e['event_id'] ?? '');
            $title = (string)($e['title'] ?? '');
            $meta  = (string)($e['meta'] ?? '');
            $body  = (string)($e['body_html'] ?? '');
        ?>
          <a href="#"
             class="event-item-link"
             data-overlay-open
             data-ov-id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
             data-ov-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
             data-ov-meta="<?= htmlspecialchars($meta, ENT_QUOTES) ?>"
             data-ov-body="<?= htmlspecialchars($body, ENT_QUOTES) ?>"
             style="text-decoration:none;color:inherit;display:block;">
            <div class="event-item">
              <div class="event-item__title"><?= htmlspecialchars($title, ENT_QUOTES) ?></div>
              <div class="event-item__meta"><?= htmlspecialchars($meta, ENT_QUOTES) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- C -->
  <section class="card card--lane">
    <div class="card__title">Bearbeitung</div>
    <div class="card__body">
      <div class="events-lane__scroll" id="events-list-work">
        <?php foreach ($laneWork as $e):
            $id    = (string)($e['event_id'] ?? '');
            $title = (string)($e['title'] ?? '');
            $meta  = (string)($e['meta'] ?? '');
            $body  = (string)($e['body_html'] ?? '');
        ?>
          <a href="#"
             class="event-item-link"
             data-overlay-open
             data-ov-id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
             data-ov-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
             data-ov-meta="<?= htmlspecialchars($meta, ENT_QUOTES) ?>"
             data-ov-body="<?= htmlspecialchars($body, ENT_QUOTES) ?>"
             style="text-decoration:none;color:inherit;display:block;">
            <div class="event-item">
              <div class="event-item__title"><?= htmlspecialchars($title, ENT_QUOTES) ?></div>
              <div class="event-item__meta"><?= htmlspecialchars($meta, ENT_QUOTES) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- D -->
  <section class="card card--wide">
    <div class="card__title">Erledigt</div>
    <div class="card__body" id="events-list-done">
      <?php foreach ($laneDone as $e): ?>
        <div class="event-item">
          <div class="event-item__title"><?= htmlspecialchars((string)($e['title'] ?? ''), ENT_QUOTES) ?></div>
          <div class="event-item__meta"><?= htmlspecialchars((string)($e['meta'] ?? ''), ENT_QUOTES) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- E -->
  <section class="card card--wide">
    <div class="card__title">Archiv</div>
    <div class="card__body" id="events-list-archiv">
      <?php foreach ($laneArchiv as $e): ?>
        <div class="event-item">
          <div class="event-item__title"><?= htmlspecialchars((string)($e['title'] ?? ''), ENT_QUOTES) ?></div>
          <div class="event-item__meta"><?= htmlspecialchars((string)($e['meta'] ?? ''), ENT_QUOTES) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- F -->
  <section class="card card--wide">
    <div class="card__title">Ausgeblendet / Gelöscht</div>
    <div class="card__body" id="events-list-deleted">
      <?php foreach ($laneDeleted as $e): ?>
        <div class="event-item">
          <div class="event-item__title"><?= htmlspecialchars((string)($e['title'] ?? ''), ENT_QUOTES) ?></div>
          <div class="event-item__meta"><?= htmlspecialchars((string)($e['meta'] ?? ''), ENT_QUOTES) ?></div>
        </div>
      <?php endforeach; ?>
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


<script>
(function(){
  'use strict';

  const ov      = document.getElementById('events-overlay');
  const ovTitle = document.getElementById('events-overlay-title');
  const ovMeta  = document.getElementById('events-overlay-meta');
  const ovBody  = document.getElementById('events-overlay-content');
  const ovStat  = document.getElementById('events-overlay-status');

  function overlayOpen(title, meta, html, statusText){
    if (!ov) return;
    if (ovTitle) ovTitle.textContent = String(title || 'Event');
    if (ovMeta)  ovMeta.textContent  = String(meta || '');
    if (ovBody)  ovBody.innerHTML    = String(html || '');
    if (ovStat)  ovStat.textContent  = String(statusText || 'Speichern nur über Status.');
    ov.hidden = false;
  }

  function overlayClose(){
    if (!ov) return;
    ov.hidden = true;
  }

  document.addEventListener('click', function(e){
    const closeBtn = e.target.closest('[data-overlay-close]');
    if (closeBtn){
      e.preventDefault();
      overlayClose();
      return;
    }

    const openEl = e.target.closest('[data-overlay-open]');
    if (openEl){
      e.preventDefault();
      overlayOpen(
        openEl.getAttribute('data-ov-title') || 'Event',
        openEl.getAttribute('data-ov-meta')  || '',
        openEl.getAttribute('data-ov-body')  || '',
        'Speichern nur über Status.'
      );
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') overlayClose();
  });
})();
</script>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
