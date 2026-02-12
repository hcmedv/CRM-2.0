(function () {
  'use strict';

  const NS = (window.CRM_EVENTS = window.CRM_EVENTS || {});
  NS.app = NS.app || {};

  function FN_InitDom() {
    NS.overlay && NS.overlay.init && NS.overlay.init();

    const openEl   = document.querySelector('#events-list-open');
    const workEl   = document.querySelector('#events-list-work');
    const doneEl   = document.querySelector('#events-list-done');
    const archivEl = document.querySelector('#events-list-archiv');
    const delEl    = document.querySelector('#events-list-deleted');

    if (!openEl) return;

    // initial load (live)
    NS.api.listEvents({})
      .then(function (res) {
        if (!res || !res.ok || !res.lanes) return;

        NS.render.renderLane(openEl,   res.lanes.open || []);
        if (workEl)   NS.render.renderLane(workEl,   res.lanes.work || []);
        if (doneEl)   NS.render.renderLane(doneEl,   res.lanes.done || []);
        if (archivEl) NS.render.renderLane(archivEl, res.lanes.archiv || []);
        if (delEl)    NS.render.renderLane(delEl,    res.lanes.deleted || []);
      })
      .catch(function () {
        // minimal: nichts loggen/spammen
        if (openEl) openEl.innerHTML = '<div class="event-empty muted">Fehler beim Laden.</div>';
      });
  }

  NS.app.init = function FN_AppInit() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', FN_InitDom);
    } else {
      FN_InitDom();
    }
  };

  // Auto-Init
  NS.app.init();

})();
