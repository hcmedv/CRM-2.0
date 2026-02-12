/* Datei: /public/events/assets/crm_events_30_overlay.js
 * Zweck:
 * - Overlay System B: Open/Close
 * - Bei Open: Detail per API nachladen und (zum Test) roh anzeigen
 */
(function () {
  'use strict';

  const API_DETAIL_URL = '/api/crm/api_crm_events_get_event.php';

  const ov      = document.getElementById('events-overlay');
  const ovTitle = document.getElementById('events-overlay-title');
  const ovMeta  = document.getElementById('events-overlay-meta');
  const ovBody  = document.getElementById('events-overlay-content');
  const ovStat  = document.getElementById('events-overlay-status');

  function FN_EscapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function FN_SetText(el, txt) {
    if (!el) return;
    el.textContent = String(txt ?? '');
  }

  function FN_SetHtml(el, html) {
    if (!el) return;
    el.innerHTML = String(html ?? '');
  }

  function FN_OverlayOpenShell(title, meta, statusText) {
    if (!ov) return;
    FN_SetText(ovTitle, title || 'Event');
    FN_SetText(ovMeta, meta || '');
    FN_SetHtml(ovBody, 'Lade Daten ...');
    FN_SetText(ovStat, statusText || 'Speichern nur über Status.');
    ov.hidden = false;
  }

  function FN_OverlayClose() {
    if (!ov) return;
    ov.hidden = true;
  }

  async function FN_LoadDetail(eventId) {
    const url = API_DETAIL_URL + '?event_id=' + encodeURIComponent(String(eventId || ''));
    const r = await fetch(url, { credentials: 'same-origin' });
    const j = await r.json();
    return j;
  }

  function FN_RenderDetailRaw(j) {
    if (!j || j.ok !== true) {
      const msg = (j && (j.error || j.message)) ? String(j.error || j.message) : 'detail_load_failed';
      return '<div style="line-height:1.5;">'
        + '<b>Fehler:</b> ' + FN_EscapeHtml(msg)
        + '</div>';
    }

    const raw = JSON.stringify(j.event ?? {}, null, 2);
    return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.4;">'
      + FN_EscapeHtml(raw)
      + '</pre>';
  }

  document.addEventListener('click', function (e) {
    const closeBtn = e.target.closest('[data-overlay-close]');
    if (closeBtn) {
      e.preventDefault();
      FN_OverlayClose();
      return;
    }

    const openEl = e.target.closest('[data-overlay-open]');
    if (!openEl) return;

    e.preventDefault();

    const id    = openEl.getAttribute('data-ov-id') || '';
    const title = openEl.getAttribute('data-ov-title') || 'Event';
    const meta  = openEl.getAttribute('data-ov-meta') || '';
    const stat  = openEl.getAttribute('data-ov-status') || 'Speichern nur über Status.';

    FN_OverlayOpenShell(title, meta, stat);

    if (id.trim() === '') {
      FN_SetHtml(ovBody, '<div><b>Fehler:</b> missing event_id</div>');
      return;
    }

    FN_LoadDetail(id).then(function (j) {
      FN_SetHtml(ovBody, FN_RenderDetailRaw(j));
    }).catch(function () {
      FN_SetHtml(ovBody, '<div><b>Fehler:</b> detail_load_failed</div>');
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') FN_OverlayClose();
  });
})();
