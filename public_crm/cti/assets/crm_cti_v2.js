(() => {
  const q = document.getElementById('cti2-q');
  const dd = document.getElementById('cti2-dd');
  const list = document.getElementById('cti2-list');

  let t = null;

  const open = () => dd.classList.add('is-open');
  const close = () => dd.classList.remove('is-open');

  q.addEventListener('input', () => {
    clearTimeout(t);
    const v = q.value.trim();
    if (v.length < 2) { close(); return; }

    t = setTimeout(async () => {
      const r = await fetch(`/public/api/crm/api_crm_search_customers.php?q=${encodeURIComponent(v)}&mode=cti`);
      const j = await r.json();

      list.innerHTML = '';
      (j.items || []).forEach(it => {
        const el = document.createElement('div');
        el.className = 'cti2-item';

        const phones = (it.phones || []);
        const phonesHtml = phones.length
          ? `<div class="cti2-phones">` + phones.map(p =>
              `<div class="cti2-phone ${p.dial === it.primary_phone ? 'cti2-phone--primary':''}">
                 ${p.number}
                 ${p.label ? `<span class="cti2-phone--muted"> · ${p.label}</span>` : ''}
               </div>`
            ).join('') + `</div>`
          : `<div class="cti2-phone--muted">keine Telefonnummer</div>`;

        el.innerHTML = `
          <div class="cti2-ava"></div>
          <div class="cti2-lines">
            <div class="cti2-l1">${it.name || it.company || ''}</div>
            <div class="cti2-l2">${it.company || ''}</div>
            ${phonesHtml}
          </div>
        `;

        list.appendChild(el);
      });

      open();
    }, 200);
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('#cti2')) close();
  });
})();
