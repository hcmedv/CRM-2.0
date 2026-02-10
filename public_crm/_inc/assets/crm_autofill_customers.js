/* =========================================================================================
   CRM - CUSTOMER AUTOFILL
   /_inc/assets/crm_autofill_customers.js

   - Autocomplete / Suggest für Kunden
   - Kann in verschiedenen Kontexten genutzt werden (Camera, Events, CTI-light, ...)
   - Flexible Einbindung durch Optionen (DOM-Elemente, API-Endpoint, Callback)

   HINWEIS:
   - Logik unverändert (minimal erweitert: Badge/Subtitle je Source)
   - Keine Kamera-Abhängigkeiten
   - DOM-Elemente werden per Optionen übergeben
   - CSS-Namensraum: .crm-suggest*
========================================================================================= */

(function () {
  "use strict";

  const NS = (window.CRM_AUTOFILL = window.CRM_AUTOFILL || {});

  NS.initCustomerAutocomplete = function (cfg) {

    const API_SEARCH = cfg.api || "/api/crm/api_crm_search_customers.php";

    const qEl       = document.querySelector(cfg.input);
    const suggestEl = document.querySelector(cfg.suggest);
    const previewEl = cfg.preview ? document.querySelector(cfg.preview) : null;

    const numEl  = cfg.number ? document.querySelector(cfg.number) : null;
    const srcEl  = cfg.source ? document.querySelector(cfg.source) : null;
    const idEl   = cfg.id     ? document.querySelector(cfg.id)     : null;
    const chipEl = cfg.chip   ? document.querySelector(cfg.chip)   : null;

    if (!qEl || !suggestEl) return;

    const SEARCH_TYPE = "customer";

    let lastQ = "";
    let activeIndex = -1;
    let items = [];
    let abort = null;
    let tmr = null;

    if (previewEl) {
      previewEl.textContent = "";
      previewEl.style.display = "none";
    }

    function esc(s) {
      return String(s || "").replace(/[&<>"']/g, (m) => ({
        "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
      })[m]);
    }

    function clearSuggest() {
      suggestEl.hidden = true;
      suggestEl.innerHTML = "";
      activeIndex = -1;
      items = [];
    }

    function getBadge(it) {
      const src = String(it?.source || "").toLowerCase();
      if (src === "m365") return "M365";
      return "Kunde";
    }

    function getPrimaryPhone(it) {
      const pp = String(it?.primary_phone || "").trim();
      if (pp) return pp;

      const phones = Array.isArray(it?.phones) ? it.phones : [];
      for (const p of phones) {
        if (p == null) continue;
        if (typeof p === "string") {
          const s = p.trim();
          if (s) return s;
        } else if (typeof p === "object") {
          const s = String(p.dial ?? p.number ?? p.value ?? "").trim();
          if (s) return s;
        }
      }
      return "";
    }

    function getPrimaryEmail(it) {
      const pe = String(it?.primary_email || "").trim();
      if (pe) return pe;

      const emails = Array.isArray(it?.emails) ? it.emails : [];
      for (const e of emails) {
        const s = String(e || "").trim();
        if (s) return s;
      }

      const raw = it?.raw && typeof it.raw === "object" ? it.raw : null;
      if (raw && Array.isArray(raw.emailAddresses) && raw.emailAddresses[0]) {
        const s = String(raw.emailAddresses[0].address || "").trim();
        if (s) return s;
      }
      return "";
    }

    function buildSubtitle(it) {
      const parts = [];

      const kn = String(it?.customer_number || "").trim();
      if (kn) parts.push(`KN ${kn}`);

      const name = String(it?.name || "").trim();
      const company = String(it?.company || "").trim();
      if (name && company && name !== company) parts.push(name);

      // Wenn keine KN vorhanden (z.B. m365), dann Phone/Mail als "zweite Zeile"
      if (!kn) {
        const phone = getPrimaryPhone(it);
        const mail  = getPrimaryEmail(it);
        if (phone) parts.push(phone);
        else if (mail) parts.push(mail);
      }

      return parts.join(" · ");
    }

    function setSelected(it) {
      const title = it.company || it.name || "";
      qEl.value = title;
      qEl.disabled = true;

      if (numEl) numEl.value = String(it.customer_number || "").trim();
      if (srcEl) srcEl.value = it.source || "";
      if (idEl)  idEl.value  = it.id || "";

      if (previewEl) {
        previewEl.innerHTML = "";
        previewEl.style.display = "none";
      }

      clearSuggest();
      if (cfg.onSelect) cfg.onSelect(it);
    }

    function renderSuggest(list) {
      suggestEl.innerHTML = "";
      activeIndex = -1;
      items = list;

      if (!list.length) { clearSuggest(); return; }

      list.forEach((it, i) => {
        const div = document.createElement("div");
        div.className = "crm-suggest__item";
        div.dataset.idx = String(i);

        const title = it.company || it.name || "";
        const sub = buildSubtitle(it);
        const badge = getBadge(it);

        div.innerHTML =
          `<div class="crm-suggest__row">
             <div class="crm-suggest__main">
               <div class="crm-suggest__title"><strong>${esc(title)}</strong></div>
               ${sub ? `<div class="crm-suggest__sub">${esc(sub)}</div>` : ``}
             </div>
             <div class="crm-suggest__badge">${esc(badge)}</div>
           </div>`;

        div.addEventListener("mousedown", (e) => {
          e.preventDefault();
          setSelected(it);
        });

        suggestEl.appendChild(div);
      });

      suggestEl.hidden = false;
    }

    async function fetchCustomers(q) {
      if (abort) abort.abort();
      abort = new AbortController();

      const url =
        `${API_SEARCH}?q=${encodeURIComponent(q)}` +
        `&type=${encodeURIComponent(SEARCH_TYPE)}` +
        `&limit=7`;

      const res = await fetch(url, {
        credentials: "same-origin",
        signal: abort.signal
      });
      if (!res.ok) return [];
      const j = await res.json();
      return Array.isArray(j.items) ? j.items : [];
    }

    function scheduleFetch() {
      const q = qEl.value.trim();
      if (q.length < 2) { clearSuggest(); return; }
      if (q === lastQ) return;
      lastQ = q;

      if (tmr) window.clearTimeout(tmr);
      tmr = window.setTimeout(async () => {
        try {
          const list = await fetchCustomers(q);
          renderSuggest(list);
        } catch (_) {}
      }, 120);
    }

    qEl.addEventListener("input", scheduleFetch);

    qEl.addEventListener("keydown", (e) => {
      if (suggestEl.hidden) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
      } else if (e.key === "Enter") {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          setSelected(items[activeIndex]);
        }
      } else if (e.key === "Escape") {
        clearSuggest();
      }

      [...suggestEl.children].forEach((el, i) => {
        el.classList.toggle("is-active", i === activeIndex);
      });

      const act = suggestEl.children[activeIndex];
      if (act && act.scrollIntoView) {
        act.scrollIntoView({ block: "nearest" });
      }
    });

    document.addEventListener("click", (e) => {
      if (!suggestEl.contains(e.target) && e.target !== qEl) clearSuggest();
    });
  };

})();
