/* Datei: /public_crm/_inc/assets/crm_user_status.js
 * Quelle der Wahrheit: Server (status_get / status_set)
 * Auto-Modus: periodisches Polling (PBX → UI)
 */
(function () {
  const root = document.getElementById('crmUserBox');
  if (!root) return;

  const btn = root.querySelector('#crmUserBtn');
  const msg = root.querySelector('#crmUserStatusMsg');

  /* -------------------------------------------------
     Polling-Config (vorerst hardcoded)
     ------------------------------------------------- */
  const AUTO_POLL_INTERVAL_MS = 4000;
  let pollTimer = null;

  const apiGet = async () =>
    fetch('/api/login/status_get.php', { credentials: 'same-origin' })
      .then(r => r.json());

  const apiSet = async (manual_state) =>
    fetch('/api/login/status_set.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'manual_state=' + encodeURIComponent(manual_state)
    }).then(r => r.json());

  const startAutoPoll = () => {
    if (pollTimer) return;
    pollTimer = setInterval(() => {
      apiGet().then(j => {
        if (j?.ok) applyUI(j.row, j.effective_state);
      });
    }, AUTO_POLL_INTERVAL_MS);
  };

  const stopAutoPoll = () => {
    if (!pollTimer) return;
    clearInterval(pollTimer);
    pollTimer = null;
  };

  const applyUI = (row, effective) => {
    if (!btn) return;

    /* Dot */
    btn.classList.remove(
      'userbtn--state-online',
      'userbtn--state-busy',
      'userbtn--state-away',
      'userbtn--state-off'
    );
    btn.classList.add('userbtn--state-' + effective);

    /* Chips reset */
    root.querySelectorAll('[data-userstatus-set]')
      .forEach(el => el.classList.remove('is-active'));

    /* Active chip + Polling-Steuerung */
    if (row.manual_state === 'auto') {
      root.querySelector('[data-userstatus-set="auto"]')
        ?.classList.add('is-active');
      startAutoPoll();
    } else {
      root.querySelector(`[data-userstatus-set="${row.manual_state}"]`)
        ?.classList.add('is-active');
      stopAutoPoll();
    }
  };

  /* Initial load */
  apiGet().then(j => {
    if (j?.ok) applyUI(j.row, j.effective_state);
  });

  /* Click handler */
  root.querySelectorAll('[data-userstatus-set]').forEach(el => {
    el.addEventListener('click', async () => {
      const state = el.getAttribute('data-userstatus-set');
      if (!state) return;

      if (msg) msg.textContent = '';

      try {
        const j = await apiSet(state);
        if (j?.ok) {
          applyUI(j.row, j.effective_state);
          if (msg) msg.textContent = 'Gespeichert.';
        } else {
          if (msg) msg.textContent = 'Fehler';
        }
      } catch {
        if (msg) msg.textContent = 'Fehler';
      }
    });
  });
})();
