/**
 * Datei: /public_crm/cti/assets/crm_cti_v2.js
 *
 * Zweck:
 * - Globale CTI-Suchleiste im Top-Header
 * - Live-Suche (Kunde/M365) + Direktwahl für frei eingegebene Nummern
 * - Click-to-Dial (Sipgate) über /cti/crm_cti_dial.php
 */

(() => {
  const root = document.getElementById('cti2');
  if (!root) return;

  const q      = document.getElementById('cti2-q');
  const dd     = document.getElementById('cti2-dd');
  const list   = document.getElementById('cti2-list');
  const status = document.getElementById('cti2-status'); // vorhanden via page_top.php

  if (!q || !dd || !list) return;

  let t = null;

  const open = () => dd.classList.add('is-open');
  const close = () => {
    dd.classList.remove('is-open');
    list.innerHTML = '';
    setStatus('', '');
    q.value = '';
  };

  const setStatus = (txt, type = '') => {
    if (!status) return;
    const elTxt = status.querySelector('.cti2-status__text');
    if (elTxt) elTxt.textContent = txt;
    status.className = 'cti2-status' + (type ? (' ' + type) : '');
  };

  const isPhoneQuery = (s) => {
    const d = String(s || '').replace(/\D+/g, '');
    return d.length >= 3;
  };

  const normalizeDial = (s) => {
    let x = String(s || '').trim();
    if (!x) return '';
    x = x.replace(/[^\d+]/g, '');
    return x;
  };

  const renderDirectDial = (rawQuery) => {
    const dial = normalizeDial(rawQuery);
    if (!dial) return;

    const el = document.createElement('div');
    el.className = 'cti2-item cti2-item--direct';

    el.innerHTML = `
      <div class="cti2-lines">
        <div class="cti2-l1 cti2-l1--row">
          <span class="cti2-ellipsis">Nummer anrufen: ${dial}</span>
          <span class="cti2-chip cti2-chip--direct">Direkt</span>
        </div>
        <div class="cti2-phones">
          <button class="cti2-phone" data-dial="${dial}" type="button">${dial}</button>
        </div>
      </div>
    `;

    list.appendChild(el);
  };


  const renderResults = (items) => {
    (items || []).forEach(it => {
      const el = document.createElement('div');
      el.className = 'cti2-item';

      const badge =
        it.source === 'customer'
          ? '<span class="cti2-chip cti2-chip--customer">Kunde</span>'
          : '<span class="cti2-chip cti2-chip--m365">M365</span>';

      const phones = it.phones || [];
      const phonesHtml = phones.length
        ? `<div class="cti2-phones">` +
          phones.map(p => `
            <button class="cti2-phone" data-dial="${p.dial}" type="button">${p.number}</button>
          `).join('') +
          `</div>`
        : `<div class="cti2-phone--muted">keine Telefonnummer</div>`;

      el.innerHTML = `
        <div class="cti2-lines">
          <div class="cti2-l1 cti2-l1--row">
            <span class="cti2-ellipsis">${it.name || it.company || ''}</span>
            ${badge}
          </div>
          <div class="cti2-l2">${it.company || ''}</div>
          ${phonesHtml}
        </div>
      `;

      list.appendChild(el);
    });
  };

  /* ---------------- Suche ---------------- */

  q.addEventListener('input', () => {
    clearTimeout(t);

    const v = q.value.trim();
    if (v.length < 2) {
      list.innerHTML = '';
      dd.classList.remove('is-open');
      setStatus('', '');
      return;
    }

    t = setTimeout(async () => {
      try {
        const r = await fetch(
          `/api/crm/api_crm_search_customers.php?q=${encodeURIComponent(v)}&type=all`,
          { credentials: 'same-origin' }
        );

        const j = await r.json();
        list.innerHTML = '';

        if (isPhoneQuery(v)) {
          renderDirectDial(v);
        }

        renderResults(j.items || []);

        open();
      } catch {
        close();
      }
    }, 200);
  });

  /* ---------------- Dial ---------------- */

  list.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-dial]');
    if (!btn) return;

    const number = String(btn.dataset.dial || '').trim();
    if (!number) return;

    setStatus('Wählt …', 'cti2-status--busy');

    try {
      const r = await fetch('/cti/crm_cti_dial.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ number })
      });

      const j = await r.json();
      if (j && j.ok) {
        setStatus('Wahl gestartet', 'cti2-status--ok');
      } else {
        setStatus('Dial fehlgeschlagen', 'cti2-status--error');
      }
    } catch {
      setStatus('Dial fehlgeschlagen', 'cti2-status--error');
    }
  });

  /* Klick außerhalb */
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#cti2')) close();
  });
})();
