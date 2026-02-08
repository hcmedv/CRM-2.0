/* =================================================================================================
   CRM 2.0 – CAMERA MODULE
   Datei: /public/camera/assets/crm_camera.js

   Zweck:
   - Kamera Start/Stop + Snapshot Queue
   - Thumbnail-Queue + Remove
   - Lightbox Preview (Klick auf Thumbnail)
   - Customer Search (Autocomplete) über /api/crm/api_crm_search_customers.php?type=customer
   - Upload Queue -> /api/camera/api_crm_camera_upload.php (multipart/form-data)
   - Cleanup Session -> /api/camera/api_crm_camera_cleanup.php (POST)

   Erwartete DOM-IDs:
   - cam_video, cam_start, cam_stop, cam_snap, cam_res, cam_status
   - cam_queue, cam_queue_info
   - cam_doc, cam_upload, cam_clear
   - cam_lightbox, cam_lightbox_img, cam_lightbox_title
   - cam_customer_q, cam_customer_number, cam_customer_source, cam_customer_id
   - cam_customer_preview (muss im DOM bleiben, wird aber ausgeblendet)
   - cam_customer_suggest
   - optional: cam_customer_chip   (KN-Chip)
================================================================================================= */

(function () {
  "use strict";

  /* =========================================================================================
     [BLOCK: CONFIG / ENDPOINTS]
  ========================================================================================= */
  const API_SEARCH  = "/api/crm/api_crm_search_customers.php";
  const API_UPLOAD  = "/api/camera/api_crm_camera_upload.php";
  const API_CLEANUP = "/api/camera/api_crm_camera_cleanup.php";
  // Commit lassen wir vorerst als Hook (kommt als nächstes):
  // const API_COMMIT  = "/api/crm/api_crm_events_commit.php";

  /* =========================================================================================
     [BLOCK: UTILS]
  ========================================================================================= */
  const $ = (id) => document.getElementById(id);

  function esc(s) {
    return String(s || "").replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[m]));
  }

  function setDisabled(el, v) {
    if (!el) return;
    el.disabled = !!v;
  }

  function setText(el, t) {
    if (!el) return;
    el.textContent = String(t ?? "");
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  function getCsrfToken() {
    // 1) meta tag
    const m = document.querySelector('meta[name="csrf-token"], meta[name="csrf_token"], meta[name="csrf"]');
    if (m && m.getAttribute("content")) return String(m.getAttribute("content") || "").trim();

    // 2) window config (best effort)
    const w = window.__CRM_CONFIG || window.CRM_CONFIG || null;
    if (w && typeof w === "object") {
      const v =
        (w.csrf_token || "") ||
        (w.csrfToken || "") ||
        (w.csrf && (w.csrf.token || w.csrf.csrf_token) || "");
      if (v) return String(v).trim();
    }
    return "";
  }

  function randHex(len) {
    const a = new Uint8Array(Math.ceil(len / 2));
    try { crypto.getRandomValues(a); } catch (_) {}
    return Array.from(a).map(b => b.toString(16).padStart(2, "0")).join("").slice(0, len);
  }

  /* =========================================================================================
     [BLOCK: SESSION]
     - Upload-Session fürs TMP-Verzeichnis (/tmp/camera/<session>/...)
  ========================================================================================= */
  function getOrCreateSessionId() {
    const key = "crm_camera_session";
    try {
      const cur = sessionStorage.getItem(key);
      if (cur && String(cur).trim() !== "") return String(cur);
      const sid = `cam_${Date.now()}_${randHex(10)}`;
      sessionStorage.setItem(key, sid);
      return sid;
    } catch (_) {
      return `cam_${Date.now()}_${randHex(10)}`;
    }
  }

  const SESSION_ID = getOrCreateSessionId();

  /* =========================================================================================
     [BLOCK: STATE]
  ========================================================================================= */
  const state = {
    stream: null,
    snapIndex: 0,
    uploading: false,
    // queue items:
    // {
    //   id, blob, localUrl, name,
    //   uploaded: bool,
    //   server: { id, ts, kunden_nummer, full, thumb, w,h,... } | null
    // }
    queue: [],
  };

  /* =========================================================================================
     [BLOCK: DOM]
  ========================================================================================= */
  const videoEl     = $("cam_video");
  const btnStart    = $("cam_start");
  const btnStop     = $("cam_stop");
  const btnSnap     = $("cam_snap");
  const resEl       = $("cam_res");
  const statusEl    = $("cam_status");

  const queueEl     = $("cam_queue");
  const queueInfoEl = $("cam_queue_info");

  const btnDoc      = $("cam_doc");
  const btnUpload   = $("cam_upload");
  const btnClear    = $("cam_clear");

  const lbEl        = $("cam_lightbox");
  const lbImgEl     = $("cam_lightbox_img");
  const lbTitleEl   = $("cam_lightbox_title");

  const qEl         = $("cam_customer_q");
  const numEl       = $("cam_customer_number");
  const srcEl       = $("cam_customer_source");
  const idEl        = $("cam_customer_id");
  const previewEl   = $("cam_customer_preview");
  const suggestEl   = $("cam_customer_suggest");

  const chipEl      = $("cam_customer_chip"); // optional

  /* =========================================================================================
     [BLOCK: GUARDS]
  ========================================================================================= */
  if (!videoEl || !btnStart || !btnStop || !btnSnap || !resEl || !statusEl || !queueEl || !queueInfoEl) return;

  /* =========================================================================================
     [BLOCK: UI HELPERS]
  ========================================================================================= */
  function hasCustomerSelected() {
    const kn = String((numEl && numEl.value) || "").trim();
    return kn.length > 0;
  }

  function updateQueueInfo() {
    setText(queueInfoEl, `Fotos: ${state.queue.length}`);

    const hasPhotos = state.queue.length > 0;
    const hasCust   = hasCustomerSelected();
    const canUpload = hasPhotos && hasCust && !state.uploading;

    setDisabled(btnUpload, !canUpload);
    setDisabled(btnClear, !(hasPhotos || hasCust) || state.uploading);

    // Dokumentieren: später ggf. "nur wenn upload fertig"
    const allUploaded = hasPhotos && state.queue.every(it => it.uploaded);
    setDisabled(btnDoc, !(allUploaded && hasCust) || state.uploading);
  }

  function updateCameraButtons(isOn) {
    setDisabled(btnStart, isOn);
    setDisabled(btnStop, !isOn);
    setDisabled(btnSnap, !isOn);
  }

  function setStatus(txt) {
    setText(statusEl, txt);
  }

  function setCustomerChip(kn) {
    if (!chipEl) return;
    const v = String(kn || "").trim();
    if (v) {
      chipEl.textContent = `KN ${v}`;
      chipEl.classList.add("is-set");
      chipEl.hidden = false;
    } else {
      chipEl.textContent = "";
      chipEl.classList.remove("is-set");
      chipEl.hidden = true;
    }
  }

  /* =========================================================================================
     [BLOCK: LIGHTBOX]
  ========================================================================================= */
  function openLightbox(url, title) {
    if (!lbEl || !lbImgEl) return;
    if (lbTitleEl) lbTitleEl.textContent = String(title || "Vorschau");
    lbImgEl.src = url;
    lbEl.hidden = false;
  }

  function closeLightbox() {
    if (!lbEl || !lbImgEl) return;
    lbEl.hidden = true;
    lbImgEl.src = "";
  }

  if (lbEl) {
    lbEl.addEventListener("click", (e) => {
      const t = e.target;
      if (t && (t.hasAttribute("data-close") || t.closest("[data-close]"))) {
        e.preventDefault();
        closeLightbox();
      }
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && lbEl && !lbEl.hidden) closeLightbox();
    });
  }

  /* =========================================================================================
     [BLOCK: QUEUE RENDER]
  ========================================================================================= */
  function getThumbUrl(it) {
    if (it.uploaded && it.server && it.server.thumb) return String(it.server.thumb);
    return it.localUrl;
  }

  function getFullUrl(it) {
    if (it.uploaded && it.server && it.server.full) return String(it.server.full);
    return it.localUrl;
  }

  function renderQueue() {
    queueEl.innerHTML = "";

    state.queue.forEach((it, idx) => {
      const wrap = document.createElement("div");
      wrap.className = "cam_thumb";
      wrap.dataset.idx = String(idx);

      const img = document.createElement("img");
      img.src = getThumbUrl(it);
      img.alt = it.name || `Foto ${idx + 1}`;

      const rm = document.createElement("button");
      rm.className = "cam_thumb__remove";
      rm.type = "button";
      rm.title = "Entfernen";
      rm.textContent = "×";

      rm.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        removeFromQueue(idx);
      });

      wrap.addEventListener("click", (e) => {
        e.preventDefault();
        openLightbox(getFullUrl(it), it.name || `Foto ${idx + 1}`);
      });

      // Upload badge (optional)
      if (it.uploaded) {
        wrap.classList.add("is-uploaded");
      } else {
        wrap.classList.remove("is-uploaded");
      }

      wrap.appendChild(img);
      wrap.appendChild(rm);
      queueEl.appendChild(wrap);
    });

    updateQueueInfo();
  }

  function removeFromQueue(idx) {
    const it = state.queue[idx];
    if (!it) return;
    try { if (it.localUrl) URL.revokeObjectURL(it.localUrl); } catch (_) {}
    state.queue.splice(idx, 1);
    renderQueue();
  }

  function clearQueueLocalOnly() {
    state.queue.forEach((it) => {
      try { if (it.localUrl) URL.revokeObjectURL(it.localUrl); } catch (_) {}
    });
    state.queue = [];
    renderQueue();
  }

  /* =========================================================================================
     [BLOCK: CAMERA START/STOP]
  ========================================================================================= */
  async function startCamera() {
    const v = String(resEl.value || "1280x720");
    const parts = v.split("x");
    const w = parseInt(parts[0] || "1280", 10);
    const h = parseInt(parts[1] || "720", 10);

    try {
      await stopCamera(true);

      const constraints = {
        audio: false,
        video: {
          width:  { ideal: w },
          height: { ideal: h },
          facingMode: "environment",
        },
      };

      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      state.stream = stream;
      videoEl.srcObject = stream;

      updateCameraButtons(true);
      setStatus("Kamera: aktiv");
    } catch (_) {
      state.stream = null;
      updateCameraButtons(false);
      setStatus("Kamera: Fehler (kein Zugriff)");
    }
  }

  async function stopCamera(silent) {
    if (state.stream) {
      try { state.stream.getTracks().forEach((t) => t.stop()); } catch (_) {}
      state.stream = null;
    }
    videoEl.srcObject = null;
    updateCameraButtons(false);
    if (!silent) setStatus("Kamera: aus");
  }

  /* =========================================================================================
     [BLOCK: SNAPSHOT]
  ========================================================================================= */
  async function takeSnapshot() {
    if (!state.stream) return;

    const vw = videoEl.videoWidth || 0;
    const vh = videoEl.videoHeight || 0;
    if (!vw || !vh) return;

    const canvas = document.createElement("canvas");
    canvas.width = vw;
    canvas.height = vh;

    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    ctx.drawImage(videoEl, 0, 0, vw, vh);

    const blob = await new Promise((resolve) => {
      canvas.toBlob((b) => resolve(b), "image/jpeg", 0.92);
    });

    if (!blob) return;

    state.snapIndex += 1;
    const name = `Foto ${state.snapIndex}`;

    const localUrl = URL.createObjectURL(blob);
    state.queue.push({
      id: `snap_${Date.now()}_${state.snapIndex}`,
      blob,
      localUrl,
      name,
      uploaded: false,
      server: null,
    });

    renderQueue();
  }

  /* =========================================================================================
     [BLOCK: UPLOAD]
  ========================================================================================= */
  function getKnOrEmpty() {
    return String((numEl && numEl.value) || "").trim();
  }

  async function apiCleanupSession() {
    try {
      const csrf = getCsrfToken();
      const headers = {};
      if (csrf) headers["X-CSRF-Token"] = csrf;

      const res = await fetch(API_CLEANUP, {
        method: "POST",
        credentials: "same-origin",
        headers,
        body: JSON.stringify({ session: SESSION_ID }),
      });

      // Cleanup ist best-effort
      if (!res.ok) return false;
      const j = await res.json().catch(() => ({}));
      return !!j.ok;
    } catch (_) {
      return false;
    }
  }

  async function uploadOne(it, idx, total) {
    const kn = getKnOrEmpty();
    if (!kn) throw new Error("missing_kunden_nummer");
    if (!it || !it.blob) throw new Error("missing_blob");

    const fd = new FormData();
    fd.append("session", SESSION_ID);
    fd.append("kunden_nummer", kn);

    // Filename ist egal, server baut eigenen Namen
    const fn = `camera_${Date.now()}_${idx + 1}.jpg`;
    fd.append("image", it.blob, fn);

    const csrf = getCsrfToken();
    const headers = {};
    if (csrf) headers["X-CSRF-Token"] = csrf;

    setStatus(`Upload: ${idx + 1}/${total} …`);

    const res = await fetch(API_UPLOAD, {
      method: "POST",
      credentials: "same-origin",
      headers,
      body: fd,
    });

    if (!res.ok) {
      throw new Error(`upload_http_${res.status}`);
    }

    const j = await res.json().catch(() => ({}));
    if (!j || j.ok !== true || !j.item) {
      const err = (j && j.error) ? String(j.error) : "upload_failed";
      throw new Error(err);
    }

    return j.item;
  }

  async function uploadAll() {
    if (state.uploading) return;
    if (!hasCustomerSelected()) {
      setStatus("Upload: bitte zuerst Kunde wählen");
      return;
    }
    if (state.queue.length === 0) return;

    state.uploading = true;
    updateQueueInfo();
    setDisabled(btnUpload, true);
    setDisabled(btnClear, true);
    setDisabled(btnDoc, true);

    const total = state.queue.length;

    try {
      for (let i = 0; i < state.queue.length; i++) {
        const it = state.queue[i];
        if (it.uploaded) continue;

        const item = await uploadOne(it, i, total);

        // Update queue item
        it.uploaded = true;
        it.server = item;

        // Optional: local preview URL freigeben (wir nutzen jetzt server thumb)
        try { if (it.localUrl) URL.revokeObjectURL(it.localUrl); } catch (_) {}
        it.localUrl = it.localUrl || ""; // belassen (falls revoke fehlschlägt)

        renderQueue();

        // kleine Pause (UI fühlt sich stabiler an)
        await sleep(30);
      }

      setStatus("Upload: fertig");
    } catch (e) {
      const msg = (e && e.message) ? String(e.message) : "upload_error";
      setStatus(`Upload: Fehler (${msg})`);
    } finally {
      state.uploading = false;
      updateQueueInfo();
    }
  }

  /* =========================================================================================
     [BLOCK: ACTION BUTTONS]
  ========================================================================================= */
  if (btnStart) btnStart.addEventListener("click", (e) => { e.preventDefault(); startCamera(); });
  if (btnStop)  btnStop.addEventListener("click",  (e) => { e.preventDefault(); stopCamera(false); });
  if (btnSnap)  btnSnap.addEventListener("click",  (e) => { e.preventDefault(); takeSnapshot(); });

  if (btnUpload) btnUpload.addEventListener("click", (e) => {
    e.preventDefault();
    uploadAll();
  });

  if (btnClear) btnClear.addEventListener("click", async (e) => {
    e.preventDefault();

    // server cleanup (best effort), dann lokal löschen
    setStatus("Änderungen verwerfen …");
    await apiCleanupSession();
    clearQueueLocalOnly();

    // Kunde ebenfalls zurücksetzen (wie gewünscht per Chip/Klick - hier nur best effort)
    if (qEl) { qEl.disabled = false; qEl.value = ""; }
    if (numEl) numEl.value = "";
    if (srcEl) srcEl.value = "";
    if (idEl)  idEl.value  = "";
    if (previewEl) { previewEl.textContent = ""; previewEl.style.display = "none"; }
    setCustomerChip("");

    setStatus("Bereit");
    updateQueueInfo();
  });

  if (btnDoc) btnDoc.addEventListener("click", (e) => {
    e.preventDefault();
    // kommt als nächstes: Event anlegen (commit) und TMP -> DATA verschieben
    setStatus("Dokumentieren: folgt (Commit/API)");
  });

  /* =========================================================================================
     [BLOCK: CUSTOMER_AUTOCOMPLETE]  (Camera: nur Kunden)
  ========================================================================================= */
  (function customerAutocomplete() {
    if (!qEl || !suggestEl || !previewEl) return;

    const SEARCH_TYPE = "customer";

    let lastQ = "";
    let activeIndex = -1;
    let items = [];
    let abort = null;
    let tmr = null;

    // Preview muss im DOM bleiben, aber nicht sichtbar
    previewEl.textContent = "";
    previewEl.style.display = "none";

    function clearSuggest() {
      suggestEl.hidden = true;
      suggestEl.innerHTML = "";
      activeIndex = -1;
      items = [];
    }

    function buildSubtitle(it) {
      const kn = it.customer_number ? `KN ${it.customer_number}` : "";
      const name = it.name ? it.name : "";
      const parts = [];
      if (kn) parts.push(kn);
      if (name && it.company && it.company !== name) parts.push(name);
      return parts.join(" · ");
    }

    function setSelected(it) {
      const title = it.company || it.name || "";

      qEl.value = title;
      qEl.disabled = true;

      const kn = String(it.customer_number || "").trim();

      if (numEl) numEl.value = kn;
      if (srcEl) srcEl.value = it.source || "";
      if (idEl)  idEl.value  = it.id || "";

      // Preview bleibt leer/unsichtbar, aber Element muss existieren
      previewEl.innerHTML = "";
      previewEl.style.display = "none";

      // Chip nur KN
      setCustomerChip(kn);

      clearSuggest();
      updateQueueInfo();
    }

    function renderSuggest(list) {
      suggestEl.innerHTML = "";
      activeIndex = -1;
      items = list;

      if (!list.length) {
        clearSuggest();
        return;
      }

      list.forEach((it, i) => {
        const div = document.createElement("div");
        div.className = "suggest__item";
        div.dataset.idx = String(i);

        const title = it.company || it.name || "";
        const sub = buildSubtitle(it);

        div.innerHTML =
          `<div class="suggest__row">` +
            `<div class="suggest__main">` +
              `<div class="suggest__title"><strong>${esc(title)}</strong></div>` +
              (sub ? `<div class="events-muted">${esc(sub)}</div>` : ``) +
            `</div>` +
            `<div class="suggest__badge">Kunde</div>` +
          `</div>`;

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

      const res = await fetch(url, { credentials: "same-origin", signal: abort.signal });
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
        } catch (_) {
          // ignore
        }
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
      if (act && act.scrollIntoView) act.scrollIntoView({ block: "nearest" });
    });

    document.addEventListener("click", (e) => {
      if (!suggestEl.contains(e.target) && e.target !== qEl) clearSuggest();
    });

    // optional: Chip-Klick als "Reset Kunde"
    if (chipEl) {
      chipEl.addEventListener("click", async (e) => {
        e.preventDefault();

        // Kunde zurücksetzen (Queue lassen wir, Upload wird dann disabled)
        if (qEl) { qEl.disabled = false; qEl.value = ""; qEl.focus(); }
        if (numEl) numEl.value = "";
        if (srcEl) srcEl.value = "";
        if (idEl)  idEl.value  = "";
        setCustomerChip("");
        clearSuggest();
        updateQueueInfo();
      });
    }
  })();

  /* =========================================================================================
     [BLOCK: INIT]
  ========================================================================================= */
  updateCameraButtons(false);
  renderQueue();
  setStatus("Kamera: aus");
  updateQueueInfo();

})();
