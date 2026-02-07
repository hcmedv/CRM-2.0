/* Datei: /public_crm/_inc/assets/crm_user_status.js
   Zweck:
   - Status-Flyout: Buttons setzen manual_state
   - Header-Icon Farbe:
     away/off => rot
     busy     => gelb (manuell ODER pbx_busy)
     auto/online + pbx_busy => gelb
     auto/online + !pbx_busy => grün
   - Aktiver Chip wird passend zur effektiven Anzeige markiert (.is-active)
*/
(function(){
  const root = document.getElementById('crmUserBox');
  if (!root) return;

  const btn = root.querySelector('#crmUserBtn');
  const msg = root.querySelector('#crmUserStatusMsg');

  const fetchJsonSafe = async (url, opts) => {
    const r = await fetch(url, opts);
    const txt = await r.text();
    try { return JSON.parse(txt); }
    catch(e) { return { ok:false, error:'non_json', http_status:r.status, raw:txt }; }
  };

  const apiGet = async () => {
    return fetchJsonSafe('/api/user/api_user_status_set.php', {
      method: 'GET',
      credentials: 'same-origin'
    });
  };

  const apiSet = async (manual_state) => {
    return fetchJsonSafe('/api/user/api_user_status_set.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ manual_state })
    });
  };

  const effectiveState = (manual, pbxBusy) => {
    if (manual === 'away' || manual === 'off') return 'away';
    if (manual === 'busy' || pbxBusy) return 'busy';
    return 'online';
  };

  const applyDot = (manual, pbxBusy) => {
    if (!btn) return;

    btn.classList.remove('userbtn--state-online','userbtn--state-busy','userbtn--state-away','userbtn--state-off');

    const eff = effectiveState(manual, pbxBusy);
    if (eff === 'away')  { btn.classList.add(manual === 'off' ? 'userbtn--state-off' : 'userbtn--state-away'); return; }
    if (eff === 'busy')  { btn.classList.add('userbtn--state-busy'); return; }
    btn.classList.add('userbtn--state-online');
  };

  const applyActiveChip = (manual, pbxBusy) => {
    const row = root.querySelector('.userstatus__row');
    if (!row) return;

    row.querySelectorAll('[data-userstatus-set]').forEach(el => el.classList.remove('is-active'));

    const eff = effectiveState(manual, pbxBusy); // online|busy|away
    const el = row.querySelector(`[data-userstatus-set="${eff}"]`);
    if (el) el.classList.add('is-active');
  };

  const applyAll = (manual, pbxBusy) => {
    applyDot(manual, pbxBusy);
    applyActiveChip(manual, pbxBusy);
  };

  // initial
  apiGet().then(j => {
    if (j && j.ok) applyAll(j.manual_state, !!j.pbx_busy);
  }).catch(()=>{});

// set
root.querySelectorAll('[data-userstatus-set]').forEach(el => {
  el.addEventListener('click', async () => {
    const v = String(el.getAttribute('data-userstatus-set') || '').trim();
    if (!v) return;

    if (msg) msg.textContent = '';
    try {
      const j = await apiSet(v);
      if (j && j.ok) {
        applyAll(j.manual_state, !!j.pbx_busy);
        if (msg) msg.textContent = 'Gespeichert.';
        return;
      }
      if (msg) msg.textContent = 'Fehler: ' + (j?.error || 'unknown');
    } catch(e) {
      if (msg) msg.textContent = 'Fehler beim Speichern.';
    }
  });
})
})();
