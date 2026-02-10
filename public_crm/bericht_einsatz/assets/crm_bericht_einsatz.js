// Datei: /public_crm/bericht_einsatz/assets/crm_bericht_einsatz.js



(function () {
  'use strict';

  const DEBUG = false;
  function dbg(...a) { if (DEBUG) console.log('[BE]', ...a); }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
  function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  function todayISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  function niceDateDE(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
    return iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
  }

  function normalizeApiArray(data) {
    if (Array.isArray(data)) return data;
    if (!data || typeof data !== 'object') return [];
    if (Array.isArray(data.items)) return data.items;
    if (Array.isArray(data.results)) return data.results;
    if (Array.isArray(data.customers)) return data.customers;
    if (Array.isArray(data.data)) return data.data;
    return [];
  }

  // API
  const API_BASE_RAW = String(window.__CRM?.apiBase ?? window.CRM_API_BASE ?? '').trim();
  const API_BASE = API_BASE_RAW.replace(/\/+$/, '');

  const API_CUSTOMERS = API_BASE + '/api/crm/api_crm_search_customers.php';
  const API_ARTICLES  = API_BASE + '/api/crm/api_crm_search_articles.php';

  async function searchArticle(q) {
    if (!API_BASE) return [];
    const res = await fetch(`${API_ARTICLES}?q=${encodeURIComponent(q)}`, { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return [];
    return normalizeApiArray(await res.json());
  }


    // ========== Kunde Autofill + Chip ==========
    function customerDisplay(c) 
    {
    const kn = String(
        c.customer_number ?? c.customerNumber ??
        c.kunden_nummer ?? c.kunde_nummer ?? c.kundennummer ?? c.nr ?? c.address_no ?? ''
    ).trim();

    if (kn) return `KN ${kn}`;
    return 'Unbekannt';
    }


  function phonesToString(c) {
    if (Array.isArray(c.phones)) {
      const out = c.phones.map((p) => {
        if (p == null) return '';
        if (typeof p === 'string' || typeof p === 'number') return String(p);
        if (typeof p === 'object') return String(p.number ?? p.phone ?? p.value ?? '').trim();
        return '';
      }).filter(Boolean);
      if (out.length) return out.join(' / ');
    }
    const t = c.phone ?? c.telefon ?? '';
    return String(t ?? '').trim();
  }

  function fillCustomer(c) {
    const hidId = qs('#be_kunde_id');
    const hidNr = qs('#be_kunden_nummer');
    if (hidId) hidId.value = String(c.id ?? '').trim();
    if (hidNr) hidNr.value = String(c.kunden_nummer ?? c.kunde_nummer ?? c.kundennummer ?? c.nr ?? c.address_no ?? '').trim();

    const chip = qs('#be_customer_chip');
    if (chip) chip.textContent = customerDisplay(c);

    const ownerEl = qs('#be_kunde_inhaber');
    const apEl    = qs('#be_kunde_ansprechpartner');
    const strEl   = qs('#be_kunde_strasse');
    const plzEl   = qs('#be_kunde_plzort');
    const telEl   = qs('#be_kunde_telefon');
    const omEl    = qs('#be_kunde_auftragsmail');
    const emEl    = qs('#be_kunde_emails');

    if (strEl) strEl.value = String(c.street ?? c.strasse ?? '').trim();

    const plz = String(c.postal_code ?? c.plz ?? '').trim();
    const ort = String(c.city ?? c.ort ?? '').trim();
    if (plzEl) plzEl.value = [plz, ort].filter(Boolean).join(' ').trim();

    if (telEl) telEl.value = phonesToString(c);

    const apVal = String(c.contact_person ?? c.ansprechpartner ?? '').trim();
    if (apEl) apEl.value = apVal;

    let ownerVal = String(
      c.owner_name ?? c.ownerName ?? c.owner ?? c.inhaber ?? c.inhaber_name ?? c.geschaeftsfuehrer ?? c.gf ?? ''
    ).trim();
    if (!ownerVal) {
      const fullName = String(c.name ?? '').trim();
      const company  = String(c.company ?? c.firma ?? '').trim();
      if (fullName && company && fullName.toLowerCase().startsWith(company.toLowerCase())) {
        const rest = fullName.substring(company.length).trim();
        if (rest) ownerVal = rest;
      }
    }
    if (ownerEl) ownerEl.value = ownerVal;

    if (omEl) omEl.value = String(c.order_email ?? c.auftragsmail ?? '').trim();

    if (emEl) {
      if (Array.isArray(c.emails)) emEl.value = c.emails.filter(Boolean).join(', ');
      else emEl.value = '';
    }

    // Signer Defaults aktualisieren
    syncSignerFieldsFromDefaults(true);
    updateSignatureWatermarks();
  }

  function clearCustomer() {
    const input = qs('#be_kunde_firma');
    const box   = qs('#be_customer_suggest');
    const prev  = qs('#be_customer_preview');

    if (input) { input.disabled = false; input.value = ''; }
    if (prev) { prev.textContent = ''; prev.style.display = 'none'; }

    const hidId = qs('#be_kunde_id'); if (hidId) hidId.value = '';
    const hidNr = qs('#be_kunden_nummer'); if (hidNr) hidNr.value = '';

    const chip = qs('#be_customer_chip'); if (chip) chip.textContent = 'Unbekannt';

    // Felder leeren (optional bewusst nur die autofill-Felder)
    const ownerEl = qs('#be_kunde_inhaber'); if (ownerEl) ownerEl.value = '';
    const apEl    = qs('#be_kunde_ansprechpartner'); if (apEl) apEl.value = '';
    const strEl   = qs('#be_kunde_strasse'); if (strEl) strEl.value = '';
    const plzEl   = qs('#be_kunde_plzort'); if (plzEl) plzEl.value = '';
    const telEl   = qs('#be_kunde_telefon'); if (telEl) telEl.value = '';
    const omEl    = qs('#be_kunde_auftragsmail'); if (omEl) omEl.value = '';
    const emEl    = qs('#be_kunde_emails'); if (emEl) emEl.value = '';

    // Suggest schließen
    if (box) { box.hidden = true; box.innerHTML = ''; }

    syncSignerFieldsFromDefaults(true);
    updateSignatureWatermarks();
  }

  function wireCustomerAutocomplete() {
    const input = qs('#be_kunde_firma');
    const box   = qs('#be_customer_suggest');
    if (!input || !box) return;

    // Namensraum sicherstellen
    if (!box.classList.contains('crm-suggest')) box.classList.add('crm-suggest');

    // Lib muss über settings eingebunden sein
    if (window.CRM_AUTOFILL && typeof CRM_AUTOFILL.initCustomerAutocomplete === 'function') {
      CRM_AUTOFILL.initCustomerAutocomplete({
        input:   '#be_kunde_firma',
        suggest: '#be_customer_suggest',
        id:      '#be_kunde_id',
        number:  '#be_kunden_nummer',
        api:     API_CUSTOMERS,
        onSelect: function (c) {
          fillCustomer(c);
        }
      });
    }

    const chip = qs('#be_customer_chip');
    if (chip) chip.addEventListener('click', clearCustomer);
  }

  // ========== Defaults ==========
  function applyDefaults() {
    const d = qs('#be_date');
    if (d && !d.value) d.value = todayISO();

    const def = String(qs('#default_mitarbeiter')?.value ?? '').trim();
    const emp = qs('#be_employee');
    if (emp && def && !String(emp.value || '').trim()) emp.value = def;

    // Start: 1 Zeile in Einsatz/Tätigkeit/Material
    if (qs('#be_einsatz_list')?.children.length === 0) addEinsatzRow();
    if (qs('#be_tasks_list')?.children.length === 0) addTaskRow();
    if (qs('#be_material_list')?.children.length === 0) addMaterialRow();

    syncSignerFieldsFromDefaults(true);
  }

  // ========== Checkbox exclusiv ==========
  function wireExclusiveCheckboxGroup(ids, hiddenId, valueMap) {
    const boxes = ids.map(id => qs('#' + id)).filter(Boolean);
    const hidden = hiddenId ? qs('#' + hiddenId) : null;

    function updateHidden() {
      if (!hidden) return;
      let val = '';
      boxes.forEach(b => { if (b.checked) val = valueMap?.[b.id] ?? (b.value || b.id); });
      hidden.value = val;
    }

    boxes.forEach(b => {
      b.addEventListener('change', () => {
        if (b.checked) boxes.forEach(o => { if (o !== b) o.checked = false; });
        updateHidden();
      });
    });

    updateHidden();
  }

  // ========== Einsatzzeiten (Variante B) ==========
  function timeToMinutes(t) {
    if (!t || typeof t !== 'string' || t.indexOf(':') < 0) return null;
    const [h, m] = t.split(':');
    const hh = parseInt(h, 10);
    const mm = parseInt(m, 10);
    if (Number.isNaN(hh) || Number.isNaN(mm)) return null;
    return (hh * 60) + mm;
  }

  function calcRowMinutes(row) {
    const s = qs('.be_e_start', row)?.value || '';
    const e = qs('.be_e_end', row)?.value || '';
    const out = qs('.be_e_dur', row);
    const a = timeToMinutes(String(s));
    const b = timeToMinutes(String(e));
    if (!out) return 0;

    if (a === null || b === null) { out.value = ''; return 0; }
    const diff = b - a;
    if (diff <= 0) { out.value = ''; return 0; }
    out.value = String(diff);
    return diff;
  }

  function updateSumMinutes() {
    const rows = qsa('.be_e_row', qs('#be_einsatz_list'));
    let sum = 0;
    rows.forEach(r => { sum += (parseInt(String(qs('.be_e_dur', r)?.value || '0'), 10) || 0); });
    const out = qs('#be_sum_minutes');
    if (out) out.textContent = String(sum);
  }

    function wireEinsatzRow(row) {
    const s = qs('.be_e_start', row);
    const e = qs('.be_e_end', row);
    const x = qs('.be_row_del', row);

    function onChange() { calcRowMinutes(row); updateSumMinutes(); }

    if (s) { s.addEventListener('input', onChange); s.addEventListener('change', onChange); }
    if (e) { e.addEventListener('input', onChange); e.addEventListener('change', onChange); }

    if (x) {
        x.addEventListener('click', () => {
        row.remove();
        updateSumMinutes();
        });
    }

    // Mitarbeiter default setzen (pro Zeile)
    const def = String(qs('#default_mitarbeiter')?.value ?? '').trim();
    const mit = qs('.be_e_mit', row);
    if (mit && def && !String(mit.value || '').trim()) mit.value = def;
    }

    function addEinsatzRow() {
    const list = qs('#be_einsatz_list');
    if (!list) return;

    const dateVal = String(qs('#be_date')?.value || todayISO());
    const def = String(qs('#default_mitarbeiter')?.value ?? '').trim();

    const row = document.createElement('div');
    row.className = 'be_e_row';

    // Keine Labels mehr in der Zeile – nur Inputs (Kopfzeile steht oben)
    row.innerHTML = `
        <div class="be_e_cell be_e_date">
        <input class="input be_e_dat" name="e_dat[]" type="date" value="${escHtml(dateVal)}" aria-label="Datum">
        </div>

        <div class="be_e_cell">
        <input class="input be_e_start" name="e_start[]" type="time" aria-label="Beginn">
        </div>

        <div class="be_e_cell">
        <input class="input be_e_end" name="e_end[]" type="time" aria-label="Ende">
        </div>

        <div class="be_e_cell be_e_durcell">
        <input class="input be_e_dur" name="e_dur[]" type="text" inputmode="numeric" readonly aria-label="Einsatzzeit (min)">
        </div>

        <div class="be_e_cell be_e_mitcell">
        <input class="input be_e_mit" name="e_mit[]" type="text" value="${escHtml(def)}" aria-label="Mitarbeiter">
        </div>

        <div class="be_e_cell be_e_delcell">
        <button class="crm-btn crm-btn--icon crm-btn--danger be_row_del" type="button" title="Zeile löschen" aria-label="Zeile löschen">×</button>
        </div>

    `;

    list.appendChild(row);
    wireEinsatzRow(row);

    // initial berechnen + Summe
    calcRowMinutes(row);
    updateSumMinutes();
    }


  function wireEinsatzList() {
    const btn = qs('#be_btn_add_einsatz');
    if (btn) btn.addEventListener('click', addEinsatzRow);
  }

  // ========== Tätigkeiten ==========
  function wireTaskRow(row) {
    const x = qs('.be_row_del', row);
    if (x) x.addEventListener('click', () => row.remove());
  }

  function addTaskRow() {
    const list = qs('#be_tasks_list');
    if (!list) return;

    const row = document.createElement('div');
    row.className = 'be_t_row';
    row.innerHTML = `
      <div class="be_t_cell be_t_txt">
        <input class="input" type="text" name="t_txt[]" placeholder="Kurzbeschreibung (z. B. Patchkabel ersetzt)" maxlength="120">
      </div>
        <div class="be_t_cell be_t_del">
        <button class="crm-btn crm-btn--icon crm-btn--danger be_row_del" type="button" title="Zeile löschen" aria-label="Zeile löschen">×</button>
        </div>

    `;
    list.appendChild(row);
    wireTaskRow(row);
  }

  function wireTasks() {
    const btn = qs('#be_btn_add_task');
    if (btn) btn.addEventListener('click', addTaskRow);
  }

  // ========== Material (Zeile, Add/Remove, Autocomplete) ==========
  function pick(it, keys, defVal = '') {
    for (const k of keys) {
      if (it && Object.prototype.hasOwnProperty.call(it, k) && it[k] != null && String(it[k]).trim() !== '') {
        return String(it[k]).trim();
      }
    }
    return defVal;
  }

  function wireMaterialRow(row) {
    const inArt = qs('.m_artno', row);
    const inTxt = qs('.m_name', row);
    const inQty = qs('.m_qty', row);
    const inUnit = qs('.m_unit', row);
    const suggest = qs('.m_suggest', row);
    const x = qs('.be_row_del', row);

    if (x) x.addEventListener('click', () => row.remove());
    if (!inArt || !suggest) return;

    let t = null;
    let last = '';

    function hide() { suggest.hidden = true; suggest.innerHTML = ''; }
    function show(items) {
      if (!items || items.length === 0) { hide(); return; }
      suggest.innerHTML = items.map((it) => {
        const artNo = pick(it, ['art_no', 'artnr', 'artikel_nr', 'artikelnummer', 'nr', 'id'], '');
        const name  = pick(it, ['name', 'bezeichnung', 'title', 'text'], '');
        const payload = encodeURIComponent(JSON.stringify(it));
        return `<button type="button" data-json="${payload}"><b>${escHtml(artNo)}</b> – ${escHtml(name)}</button>`;
      }).join('');
      suggest.hidden = false;
    }

    inArt.addEventListener('input', () => {
      const q = String(inArt.value || '').trim();
      if (q.length < 2) { hide(); return; }
      if (q === last) return;
      last = q;

      clearTimeout(t);
      t = setTimeout(async () => {
        const items = await searchArticle(q);
        show(items);
      }, 200);
    });

    suggest.addEventListener('click', (ev) => {
      const btn = ev.target.closest('button[data-json]');
      if (!btn) return;
      const it = JSON.parse(decodeURIComponent(btn.getAttribute('data-json') || ''));

      const artNo = pick(it, ['art_no', 'artnr', 'artikel_nr', 'artikelnummer', 'nr', 'id'], '');
      const name  = pick(it, ['name', 'bezeichnung', 'title', 'text'], '');
      const unit  = pick(it, ['unit', 'einheit', 'uom'], '');

      inArt.value = artNo;
      if (inTxt && !String(inTxt.value || '').trim()) inTxt.value = name;
      if (inUnit) inUnit.value = unit;
      if (inQty && !String(inQty.value || '').trim()) inQty.value = '1';

      hide();
    });

    document.addEventListener('click', (ev) => {
      if (ev.target === inArt || suggest.contains(ev.target)) return;
      hide();
    });
  }

  function addMaterialRow() {
    const list = qs('#be_material_list');
    if (!list) return;

    const row = document.createElement('div');
    row.className = 'be_m_row';

    row.innerHTML = `
      <div class="be_m_cell be_m_art autocomplete">
        <input class="input m_artno" type="text" name="m_artno[]" autocomplete="off" placeholder="Art.-Nr. / Suche">
        <div class="crm-suggest m_suggest" hidden></div>
      </div>

      <div class="be_m_cell be_m_txt">
        <input class="input m_name" type="text" name="m_name[]" placeholder="Freitext möglich">
      </div>

      <div class="be_m_cell be_m_qty">
        <input class="input m_qty" type="text" name="m_qty[]" inputmode="numeric" pattern="[0-9]*" placeholder="0">
      </div>

      <div class="be_m_cell be_m_unit">
        <input class="input m_unit" type="text" name="m_unit[]" placeholder="Einheit">
      </div>

      <div class="be_m_cell be_m_del">
        <button class="crm-btn crm-btn--icon crm-btn--danger be_row_del" type="button" title="Zeile löschen" aria-label="Zeile löschen">×</button>
      </div>
    `;

    list.appendChild(row);
    wireMaterialRow(row);
  }

  function wireMaterial() {
    const btn = qs('#be_btn_add_material');
    if (btn) btn.addEventListener('click', addMaterialRow);
    qsa('.be_m_row', qs('#be_material_list')).forEach(wireMaterialRow);
  }

  // ========== Signaturen ==========
  const sigState = {
    mainSigned: false,  // Datensicherung "Kunde"
    dasiSigned: false,  // Datensicherung "DaSi"
    abSigned:   false,  // Abnahme "Kunde"
    touched: {
      main: { name: false },
      dasi: { name: false },
      ab:   { name: false }
    }
  };

  function getDefaultSignerName(kind) {
    const owner = qs('#be_kunde_inhaber');
    const ap    = qs('#be_kunde_ansprechpartner');
    const vOwner = String(owner?.value ?? '').trim();
    const vAp    = String(ap?.value ?? '').trim();

    // Datensicherung (main): eher Inhaber/GF, sonst Ansprechpartner
    if (kind === 'main') return vOwner || vAp;

    // Datensicherung (dasi): eher Ansprechpartner, sonst Inhaber/GF
    if (kind === 'dasi') return vAp || vOwner;

    // Abnahme (ab): in der Regel Ansprechpartner unterschreibt
    return vAp || vOwner;
  }

  function getDefaultSignerDateDE() {
    const d = qs('#be_date');
    const iso = String(d?.value ?? '').trim();
    const useIso = (/^\d{4}-\d{2}-\d{2}$/.test(iso)) ? iso : todayISO();
    return niceDateDE(useIso) || '';
  }

  function getSignerNameUpperFor(kind) {
    let el = null;

    if (kind === 'dasi') el = qs('#be_sig_dasi_name');
    else if (kind === 'ab') el = qs('#be_sig_ab_name');
    else el = qs('#be_sig_main_name');

    const v = String(el?.value ?? '').trim();
    const fallback = getDefaultSignerName(kind);
    const out = (v || fallback || '').trim();
    return out ? out.toUpperCase() : '';
  }

    function syncSignerFieldsFromDefaults(force = false) {
    const mainName = qs('#be_sig_main_name');
    const dasiName = qs('#be_sig_dasi_name');

    const defMain = getDefaultSignerName('main');
    const defDasi = getDefaultSignerName('dasi');

    if (mainName && (!sigState.touched.main.name || force)) {
        mainName.value = String(defMain || '').trim();
    }
    if (dasiName && (!sigState.touched.dasi.name || force)) {
        dasiName.value = String(defDasi || '').trim();
    }
    }



  function drawSignatureWatermark(canvas, ctx, nameUpper, dateDE) {
    const w = canvas.width;
    const h = canvas.height;

    ctx.clearRect(0, 0, w, h);

    const lineY = Math.round(h * 0.78);

    ctx.save();
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(0,0,0,0.55)';
    ctx.beginPath();
    ctx.moveTo(30, lineY);
    ctx.lineTo(w - 30, lineY);
    ctx.stroke();
    ctx.restore();

    ctx.save();
    ctx.fillStyle = 'rgba(0,0,0,0.80)';
    ctx.font = '24px Arial';
    const leftText = `Datum:  ${dateDE}${nameUpper ? '   Name:  ' + nameUpper : ''}`;
    ctx.fillText(leftText, 30, lineY + 28);
    ctx.restore();
  }

  function initSignature(canvasId, hiddenId, clearBtnId, stateKey, kind) {
    const canvas = qs('#' + canvasId);
    const hid = qs('#' + hiddenId);
    const btn = qs('#' + clearBtnId);
    if (!canvas || !hid) return null;

    const ctx = canvas.getContext('2d');

    function watermarkNow() {
      const nameUpper = getSignerNameUpperFor(kind);
      const dateDE = getDefaultSignerDateDE();
      drawSignatureWatermark(canvas, ctx, nameUpper, dateDE);
      hid.value = '';
      sigState[stateKey] = false;
    }

    watermarkNow();

    let drawing = false;
    let last = null;

    function pos(e) {
      const r = canvas.getBoundingClientRect();
      const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
      const y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
      return { x: x * (canvas.width / r.width), y: y * (canvas.height / r.height) };
    }

    function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
    function move(e) {
      if (!drawing) return;
      const p = pos(e);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#000';
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
      e.preventDefault();
    }
    function end() {
      if (!drawing) return;
      drawing = false;
      last = null;
      hid.value = canvas.toDataURL('image/png');
      sigState[stateKey] = true;
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);

    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('touchend', end);

    if (btn) btn.addEventListener('click', watermarkNow);

    return { watermarkNow };
  }

  let sigMainCtl = null;
  let sigDasiCtl = null;
  let sigAbCtl   = null;

  function updateSignatureWatermarks() {
    if (sigMainCtl && !sigState.mainSigned) sigMainCtl.watermarkNow();
    if (sigDasiCtl && !sigState.dasiSigned) sigDasiCtl.watermarkNow();
    if (sigAbCtl   && !sigState.abSigned)   sigAbCtl.watermarkNow();
  }

  function wireSignerFieldTouch() {
    const mainName = qs('#be_sig_main_name');
    const dasiName = qs('#be_sig_dasi_name');
    const abName   = qs('#be_sig_ab_name');

    function commit(which) {
      if (which === 'main' && sigState.mainSigned && sigMainCtl) { sigMainCtl.watermarkNow(); return; }
      if (which === 'dasi' && sigState.dasiSigned && sigDasiCtl) { sigDasiCtl.watermarkNow(); return; }
      if (which === 'ab'   && sigState.abSigned   && sigAbCtl)   { sigAbCtl.watermarkNow(); return; }
      updateSignatureWatermarks();
    }

    if (mainName) {
      mainName.addEventListener('input', () => { sigState.touched.main.name = true; });
      mainName.addEventListener('change', () => { sigState.touched.main.name = true; commit('main'); });
      mainName.addEventListener('blur', () => { sigState.touched.main.name = true; commit('main'); });
    }
    if (dasiName) {
      dasiName.addEventListener('input', () => { sigState.touched.dasi.name = true; });
      dasiName.addEventListener('change', () => { sigState.touched.dasi.name = true; commit('dasi'); });
      dasiName.addEventListener('blur', () => { sigState.touched.dasi.name = true; commit('dasi'); });
    }
    if (abName) {
      abName.addEventListener('input', () => { sigState.touched.ab.name = true; });
      abName.addEventListener('change', () => { sigState.touched.ab.name = true; commit('ab'); });
      abName.addEventListener('blur', () => { sigState.touched.ab.name = true; commit('ab'); });
    }
  }


  // ========== Init ==========
  document.addEventListener('DOMContentLoaded', () => {
    applyDefaults();
    wireCustomerAutocomplete();

    wireExclusiveCheckboxGroup(
      ['be_dasi_ok', 'be_dasi_none', 'be_dasi_before'],
      'be_dasi_status',
      { 'be_dasi_ok': 'ok', 'be_dasi_none': 'not_present_execute', 'be_dasi_before': 'before_start' }
    );

    wireExclusiveCheckboxGroup(
      ['be_ab_done', 'be_ab_open'],
      'be_ab_status',
      { 'be_ab_done': 'done', 'be_ab_open': 'open' }
    );

    wireEinsatzList();
    wireTasks();
    wireMaterial();

    wireSignerFieldTouch();

    sigMainCtl = initSignature('be_sig_main', 'be_sig_main_data', 'be_btn_sig_main_clear', 'mainSigned', 'main');
    sigDasiCtl = initSignature('be_sig_dasi', 'be_sig_dasi_data', 'be_btn_sig_dasi_clear', 'dasiSigned', 'dasi');
    sigAbCtl   = initSignature('be_sig_ab',   'be_sig_ab_data',   'be_btn_sig_ab_clear',   'abSigned',   'ab');


    // Default Signer reaktiv bei Änderungen
    const ap = qs('#be_kunde_ansprechpartner');
    const owner = qs('#be_kunde_inhaber');
    const dt = qs('#be_date');

    if (owner) owner.addEventListener('input', () => { syncSignerFieldsFromDefaults(); updateSignatureWatermarks(); });
    if (ap) ap.addEventListener('input', () => { syncSignerFieldsFromDefaults(); updateSignatureWatermarks(); });
    if (dt) dt.addEventListener('change', () => { updateSignatureWatermarks(); });

    // Initial Summen
    qsa('.be_e_row', qs('#be_einsatz_list')).forEach((r) => calcRowMinutes(r));
    updateSumMinutes();
  });

})();





