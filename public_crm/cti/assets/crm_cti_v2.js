/**
 * Datei: /public_crm/cti/assets/crm_cti_v2.js
 *
 * Zweck:
 * - Zentrale, globale CTI-Suchleiste im Top-Header (immer sichtbar)
 * - Live-Suche nach Kunden, Kontakten und Telefonnummern
 * - Click-to-Dial per Klick auf Telefonnummer
 *
 * Datenquellen / Endpoints:
 * - Suche: GET /api/crm/api_crm_search_customers.php?q=…&mode=cti
 * - Dial:  POST /cti/crm_cti_dial.php   Body: { "number": "+49..." }
 *
 * Verhalten:
 * - Debounce (200 ms)
 * - Klick außerhalb: Dropdown schließen + Input zurücksetzen
 * - ESC: Dropdown schließen + Input zurücksetzen
 */

(() => {
  const root = document.getElementById('cti2');
  if (!root) return;

  const q    = document.getElementById('cti2-q');
  const dd   = document.getElementById('cti2-dd');
  const list = document.getElementById('cti2-list');
  if (!q || !dd || !list) return;

  let t = null;

  const open  = () => dd.classList.add('is-open');
  const close = () => dd.classList.remove('is-open');

  const resetUi = () => {
    close();
    q.value = '';
    list.innerHTML = '';
  };

  const FN_Dial = async (number) => {
    const n = String(number || '').trim();
    if (!n) return;

    await fetch('/cti/crm_cti_dial.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ number: n })
    });
  };

  q.addEventListener('input', () => {
    clearTimeout(t);

    const v = q.value.trim();
    if (v.length < 2) { close(); return; }

    t = setTimeout(async () => {
      try {
        const r = await fetch(
          `/api/crm/api_crm_search_customers.php?q=${encodeURIComponent(v)}&mode=cti`,
          { credentials: 'same-origin' }
        );

        const j = await r.json();
        list.innerHTML = '';

        (j.items || []).forEach(it => {
          const el = document.createElement('div');
          el.className = 'cti2-item';

          const src = String(it.source || '').toLowerCase();
          const badge =
            src === 'customer'
              ? '<span class="cti2-chip cti2-chip--customer">Kunde</span>'
              : src === 'm365'
                ? '<span class="cti2-chip cti2-chip--m365">M365</span>'
                : '';

          const phones = (it.phones || []);
          const phonesHtml = phones.length
            ? `<div class="cti2-phones">` +
              phones.map(p => `
                <div class="cti2-phone ${p.dial === it.primary_phone ? 'cti2-phone--primary' : ''}"
                     data-dial="${String(p.dial || '')}">
                  ${p.number}
                  ${p.label ? `<span class="cti2-phone--muted"> · ${p.label}</span>` : ''}
                </div>
              `).join('') +
              `</div>`
            : `<div class="cti2-phone--muted">keine Telefonnummer</div>`;

          el.innerHTML = `
            <div class="cti2-ava"></div>
            <div class="cti2-lines">
              <div class="cti2-l1">
                <span class="cti2-title">${it.name || it.company || ''}</span>
                ${badge}
              </div>
              <div class="cti2-l2">${it.company || ''}</div>
              ${phonesHtml}
            </div>
          `;

          // Click-to-Dial: nur auf Telefonnummern
          el.querySelectorAll('.cti2-phone[data-dial]').forEach(ph => {
            ph.addEventListener('click', async (e) => {
              e.preventDefault();
              e.stopPropagation();

              const number = String(ph.dataset.dial || '').trim();
              if (!number) return;

              try {
                await FN_Dial(number);
              } catch (err) {
                // bewusst still (optional später Toast/Log)
              }
            });
          });

          list.appendChild(el);
        });

        open();
      } catch (e) {
        close();
      }
    }, 200);
  });

  // ESC im Input: schließen + reset
  q.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      resetUi();
      q.blur();
    }
  });

  // Klick außerhalb: schließen + reset (Input leeren, damit neue Suche ohne markieren möglich ist)
  document.addEventListener('click', e => {
    if (!e.target.closest('#cti2')) resetUi();
  });
})();
