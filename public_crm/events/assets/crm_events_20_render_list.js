/* Datei: /public/events/assets/crm_events_20_render_list.js
 * Zweck:
 * - Lädt Lanes via /api/crm/api_crm_events_list_events.php
 * - Rendert Listen in:
 *   #events-list-open / #events-list-work / #events-list-done / #events-list-archiv / #events-list-deleted
 * - Rendert List-Items als „Mini-Cards“ ohne neues CSS (nutzt vorhandene Layouts + wenig Inline-Style)
 */
(function () {
  'use strict';

  const NS = (window.CRM_EVENTS = window.CRM_EVENTS || {});
  NS.list = NS.list || {};

  const API_URL = '/api/crm/api_crm_events_list_events.php';

  function FN_Q(sel) {
    return document.querySelector(sel);
  }

  function FN_EscapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function FN_Pad2(n) {
    const x = parseInt(n, 10);
    return (x < 10 ? '0' : '') + x;
  }

  function FN_FormatTs(ts) {
    const t = parseInt(ts, 10);
    if (!t || t <= 0) return '';
    const d = new Date(t * 1000);
    return (
      FN_Pad2(d.getDate()) +
      '.' +
      FN_Pad2(d.getMonth() + 1) +
      '.' +
      d.getFullYear() +
      ' ' +
      FN_Pad2(d.getHours()) +
      ':' +
      FN_Pad2(d.getMinutes())
    );
  }

  function FN_BuildMetaLine(it) {
    const created = FN_FormatTs(it?.created_at);
    const updated = FN_FormatTs(it?.updated_at);

    if (created && updated && it?.updated_at && it?.created_at && parseInt(it.updated_at, 10) !== parseInt(it.created_at, 10)) {
      return created + ' — upd ' + updated;
    }
    return created || updated || '';
  }

  function FN_BuildOverlayBodyHtml(it) {
    const lines = [];

    const subtitle = String(it?.subtitle ?? '').trim();
    if (subtitle !== '') {
      lines.push('<div style="margin:0 0 10px 0;">' + FN_EscapeHtml(subtitle) + '</div>');
    }

    lines.push('<div><b>ID:</b> ' + FN_EscapeHtml(it?.event_id) + '</div>');
    lines.push('<div><b>Status:</b> ' + FN_EscapeHtml(it?.state) + '</div>');
    lines.push('<div><b>Quelle/Typ:</b> ' + FN_EscapeHtml(it?.event_source) + ' / ' + FN_EscapeHtml(it?.event_type) + '</div>');

    if (String(it?.customer_number ?? '').trim() !== '') {
      lines.push('<div><b>Kunde:</b> ' + FN_EscapeHtml(it?.customer_number) + '</div>');
    }

    const created = FN_FormatTs(it?.created_at);
    const updated = FN_FormatTs(it?.updated_at);
    if (created) lines.push('<div><b>Erstellt:</b> ' + FN_EscapeHtml(created) + '</div>');
    if (updated) lines.push('<div><b>Aktualisiert:</b> ' + FN_EscapeHtml(updated) + '</div>');

    return '<div style="line-height:1.5;">' + lines.join('') + '</div>';
  }

  function FN_RenderLane(el, items) {
    if (!el) return;

    if (!Array.isArray(items) || items.length === 0) {
      el.innerHTML = '';
      return;
    }

    const out = [];
    for (const it of items) {
      const title = String(it?.title ?? '').trim();
      const meta  = FN_BuildMetaLine(it);

      const ovTitle = title !== '' ? title : 'Event';
      const ovMeta  = meta;
      const ovBody  = FN_BuildOverlayBodyHtml(it);

      out.push(
        '<a href="#" data-overlay-open ' +
          'data-ov-id="' + FN_EscapeHtml(it?.event_id) + '" ' +
          'data-ov-title="' + FN_EscapeHtml(ovTitle) + '" ' +
          'data-ov-meta="' + FN_EscapeHtml(ovMeta) + '" ' +
          'data-ov-body="' + FN_EscapeHtml(ovBody) + '" ' +
          'data-ov-status="Speichern nur über Status." ' +
          'style="text-decoration:none;color:inherit;display:block;">' +

          '<div style="padding:8px 10px;border:1px solid rgba(0,0,0,.08);border-radius:10px;margin:8px 0;background:#fff;">' +
            '<div style="font-weight:700;">' + FN_EscapeHtml(ovTitle) + '</div>' +
            (String(it?.subtitle ?? '').trim() !== '' ? '<div style="margin-top:2px;">' + FN_EscapeHtml(it.subtitle) + '</div>' : '') +
            (meta !== '' ? '<div style="opacity:.7;font-size:12px;margin-top:2px;">' + FN_EscapeHtml(meta) + '</div>' : '') +
          '</div>' +

        '</a>'
      );
    }

    el.innerHTML = out.join('');
  }

  async function FN_Load() {
    // nutzt vorhandene API, aber fallback direkt fetch:
    const fetcher =
      (NS.api && typeof NS.api.listEvents === 'function')
        ? NS.api.listEvents
        : async function () {
            const r = await fetch(API_URL, { credentials: 'same-origin' });
            return await r.json();
          };

    const j = await fetcher();
    if (!j || j.ok !== true) {
      // leeren, damit keine Altinhalte stehen bleiben
      FN_RenderLane(FN_Q('#events-list-open'), []);
      FN_RenderLane(FN_Q('#events-list-work'), []);
      FN_RenderLane(FN_Q('#events-list-done'), []);
      FN_RenderLane(FN_Q('#events-list-archiv'), []);
      FN_RenderLane(FN_Q('#events-list-deleted'), []);
      return;
    }

    const lanes = j.lanes || {};
    FN_RenderLane(FN_Q('#events-list-open'),    lanes.open    || []);
    FN_RenderLane(FN_Q('#events-list-work'),    lanes.work    || []);
    FN_RenderLane(FN_Q('#events-list-done'),    lanes.done    || []);
    FN_RenderLane(FN_Q('#events-list-archiv'),  lanes.archiv  || []);
    FN_RenderLane(FN_Q('#events-list-deleted'), lanes.deleted || []);
  }

  document.addEventListener('DOMContentLoaded', function () {
    FN_Load().catch(function () {
      // bewusst still
    });
  });

  NS.list.reload = FN_Load;
})();
