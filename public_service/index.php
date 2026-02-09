<?php
declare(strict_types=1);

/*
  Datei: /public_service/index.php
  Zweck:
  - Öffentliche Startseite service.hcmedv.de (Desktop-first, responsive)
  - Grid: Service/Login, Fernwartung (TeamViewer Code), Status, Kontaktformular
  - Kontaktformular: Type/Priority dynamisch aus /config/defs/defs_events.php (lesend)
*/

header('Content-Type: text/html; charset=utf-8');

######## SETTINGS ########################################################################################################################

// Service Settings einbinden
$SVC        = (array)require __DIR__ . '/_inc/settings_service.php';
$SVC_STATUS = (array)($SVC['status'] ?? []);

// DEFS zentral über settings_service.php (Fallback auf Datei, falls Closure fehlt)
$DEFS = [];
if (isset($SVC['getDefs']) && is_callable($SVC['getDefs'])) {
    $DEFS = (array)($SVC['getDefs'])();
} else {
    $defsPath = dirname(__DIR__) . '/config/defs/defs_events.php';
    if (is_file($defsPath)) { $DEFS = (array)require $defsPath; }
}

$types      = (array)($DEFS['types'] ?? []);
$priorities = (array)($DEFS['priorities'] ?? []);
$uiType     = (array)($DEFS['ui']['type'] ?? []);
$uiPrio     = (array)($DEFS['ui']['priority'] ?? []);

######## HELPER #########################################################################################################################

/*
 * 0001 - FN_OptLabel
 * Liefert UI-Label für Key aus UI-Map oder Key selbst.
 */
function FN_OptLabel(array $uiMap, string $key) : string
{
    $label = $uiMap[$key]['label'] ?? $key;
    return (string)$label;
}

/*
 * 0002 - FN_H
 * HTML Escaping (UTF-8).
 */
function FN_H(string $s) : string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HCM EDV · Service</title>

<link rel="stylesheet" href="/_inc/assets/service.css?v=1">

<!-- BEGIN: QUICK FIXES (nur falls noch nicht in service.css) -->
<style>
/* Zeilenumbrüche in tvMsg per \n ermöglichen */
#tvMsg{ white-space: pre-line; }
</style>
<!-- END: QUICK FIXES -->
</head>

<body>

<!-- =================================================================================================
     BEGIN: TOPNAV (HEADER)
     ================================================================================================= -->
<div class="topnav bar">
  <div class="bar__inner topnav__inner">
    <div class="topnav__left">
      <img src="/_inc/img/logo.png" style="height:50px; margin-top:0.25em;" alt="HCM EDV" class="topnav__logo">
    </div>

    <div class="text">
      <p>Wir beraten Sie gerne! <span>Tel.: <a href="tel:00494071189963">040 / 711 899 63</a> | E-Mail: <a href="mailto:info@hcmedv.de">info@hcmedv.de</a></span></p>
    </div>

    <div class="topnav__right"></div>
  </div>
</div>
<!-- =================================================================================================
     END: TOPNAV (HEADER)
     ================================================================================================= -->

<!-- =================================================================================================
     BEGIN: MAIN (APP)
     ================================================================================================= -->
<main class="app">
  <div class="page-block">

    <div class="grid grid--start">

      <!-- ===========================================================================================
           BEGIN: CARD "SERVICE"
           =========================================================================================== -->
      <section class="card">
        <div class="card__title">Service</div>
        <div class="card__body">
          <div class="muted"><b>Techniker-Login</b></div>
          <div class="space"></div>
          <button class="btn" type="button" disabled>Login</button>
        </div>
      </section>
      <!-- ===========================================================================================
           END: CARD "SERVICE"
           =========================================================================================== -->

      <!-- ===========================================================================================
           BEGIN: CARD "FERNWARTUNG" (TEAMVIEWER)
           =========================================================================================== -->
      <section class="card">
        <div class="card__title">Fernwartung</div>

        <div class="card__body">

          <!-- BEGIN: Fernwartung Intro/Info -->
          <div class="muted">
            <b>Bitte geben Sie den Service-Code ein.</b>
          </div>

          <div class="space"></div>

          <!-- BEGIN: Message-Bereich (fixe Höhe, springt nicht) -->
          <div class="msg msg--fixed" id="tvMsg" aria-live="polite">Mit Klick auf „Verbinden“ erlauben Sie die Fernwartung für diese Sitzung.</div>
          <!-- END: Message-Bereich -->
          <!-- END: Fernwartung Intro/Info -->

          <div class="space"></div>

          <!-- BEGIN: Fernwartung Bottom Bar -->
          <div class="tvbar">
            <div class="tvbar__left">
              <label class="label tvbar__label" for="tvCode">Service-Code</label>
              <div class="space"></div>

              <input
                class="input tvbar__input"
                id="tvCode"
                name="tv_code"
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="123456"
                maxlength="10"
                minlength="4"
                pattern="[0-9]*"
              />
            </div>

            <div class="tvbar__right">
              <button class="btn" id="tvStart" type="button">Verbinden</button>
              <button class="btn btn--ghost" id="tvHelp" type="button">Hilfe</button>
            </div>
          </div>
          <!-- END: Fernwartung Bottom Bar -->

        </div>
      </section>
      <!-- ===========================================================================================
           END: CARD "FERNWARTUNG" (TEAMVIEWER)
           =========================================================================================== -->

      <!-- ===========================================================================================
           BEGIN: CARD "ONLINE STATUS"
           =========================================================================================== -->
      <section class="card">
        <div class="card__title">Online Status</div>
        <div class="card__body">

          <div class="status">
            <div class="status__dot" data-state="online"></div>
            <div>
              <div class="status__title" id="svcStateTitle">Online</div>
              <div class="muted" id="svcStateText">Fernwartung aktuell möglich.</div>
            </div>
          </div>

          <!-- (Optional) Platzhalter-Spacing: lieber per CSS lösen; bleibt hier wie dein Stand -->
          <div class="space"></div>
          <div class="space"></div>
          <div class="space"></div>
          <div class="space"></div>
          <div class="space"></div>
          <div class="space"></div>
          <div class="space"></div>

          <!-- BEGIN: Online Status-Chips -->
          <?php $chips = (array)($SVC_STATUS['chips'] ?? []); ?>
          <div class="row_chips">
            <?php foreach ($chips as $k => $cfg): ?>
              <button class="chip" type="button" data-set-state="<?= FN_H((string)$k) ?>">
                <?= FN_H((string)($cfg['label'] ?? $k)) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <!-- END: Online Status-Chips -->

        </div>
      </section>
      <!-- ===========================================================================================
           END: CARD "ONLINE STATUS"
           =========================================================================================== -->

      <!-- ===========================================================================================
           BEGIN: CARD "KONTAKT" (FORM)
           =========================================================================================== -->
      <section class="card card--wide">
        <div class="card__title">Kontakt</div>
        <div class="card__body">

          <div class="formgrid">

            <div class="field">
              <label class="label" for="cFirma">&nbsp;</label>
              <input class="input" id="cFirma" name="company" placeholder="Firma" />
            </div>

            <div class="field">
              <label class="label" for="cName">&nbsp;</label>
              <input class="input" id="cName" name="name" placeholder="Name" />
            </div>

            <div class="field">
              <label class="label" for="cType">&nbsp;</label>
              <select class="input input--select" id="cType" name="type">
                <?php
                $defType = 'service';
                foreach ($types as $t) {
                    $k = (string)$t;
                    $lbl = FN_OptLabel($uiType, $k);
                    $sel = ($k === $defType) ? ' selected' : '';
                    echo '<option value="' . FN_H($k) . '"' . $sel . '>' . FN_H($lbl) . '</option>';
                }
                ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="cMail">&nbsp;</label>
              <input class="input" id="cMail" name="mail" type="email" placeholder="mail@domain.tld" />
            </div>

            <div class="field">
              <label class="label" for="cPrio">&nbsp;</label>
              <select class="input input--select" id="cPrio" name="priority">
                <?php
                $defPrio = 'normal';
                foreach ($priorities as $p) {
                    $k = (string)$p;
                    $lbl = FN_OptLabel($uiPrio, $k);
                    $sel = ($k === $defPrio) ? ' selected' : '';
                    echo '<option value="' . FN_H($k) . '"' . $sel . '>' . FN_H($lbl) . '</option>';
                }
                ?>
              </select>
            </div>

            <div class="field field--full">
              <label class="label" for="cMsg">&nbsp;</label>
              <textarea class="input input--ta" id="cMsg" name="msg" rows="4" placeholder="Worum geht’s?"></textarea>
              <div class="space"></div>
              <div class="note"><b>Hinweis: Priorität kann Reaktionszeit und ggf. Kosten beeinflussen.</b></div>
              <div class="space"></div>
            </div>

            <div class="field field--full row row--end">
              <button class="btn" type="button" disabled>Absenden</button>
            </div>

            <div class="field field--full">
              <div class="msg" id="cMsgOut" aria-live="polite"></div>
            </div>

          </div>

        </div>
      </section>
      <!-- ===========================================================================================
           END: CARD "KONTAKT" (FORM)
           =========================================================================================== -->

    </div>

  </div>
</main>
<!-- =================================================================================================
     END: MAIN (APP)
     ================================================================================================= -->

<!-- =================================================================================================
     BEGIN: FOOTER
     ================================================================================================= -->
<footer class="app-footer">
  <div class="app-footer__inner">
    <div class="muted">© <?php echo date('Y'); ?> HCM EDV GmbH</div>
    <div class="app-footer__links">
      <a class="link" href="https://hcmedv.de/impressum.php">Impressum</a>
      <a class="link" href="https://hcmedv.de/datenschutz.php">Datenschutz</a>
    </div>
  </div>
</footer>
<!-- =================================================================================================
     END: FOOTER
     ================================================================================================= -->

<script>
/* =================================================================================================
   BEGIN: SERVICE PAGE JS (SINGLE IIFE)
   ================================================================================================= */
(function(){

  /* ===============================================================================================
     BEGIN: CONFIG (PHP -> JS)
     =============================================================================================== */
  const dot   = document.querySelector('.status__dot');
  const title = document.getElementById('svcStateTitle');
  const text  = document.getElementById('svcStateText');

  const CFG = <?php echo json_encode([
    'poll_ms' => (int)($SVC_STATUS['poll_ms'] ?? 10000),
    'texts'   => (array)($SVC_STATUS['texts'] ?? []),
    'office'  => (array)($SVC_STATUS['office_hours'] ?? []),

    // NEU: innerhalb Office-Hours: state "off" zu z.B. "away" mappen
    'office_off_maps_to' => (string)($SVC_STATUS['office_hours_off_maps_to'] ?? 'away'),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  /* ===============================================================================================
     END: CONFIG
     =============================================================================================== */


  /* ===============================================================================================
     BEGIN: HELPERS (STATE / UI)
     =============================================================================================== */
  const normalize = (v) => {
    v = String(v || '').trim().toLowerCase();
    if (v === 'online' || v === 'busy' || v === 'away' || v === 'off') return v;
    return 'off';
  };

  const markChips = (state) => {
    document.querySelectorAll('[data-set-state]').forEach(el => {
      el.classList.toggle('is-active', String(el.getAttribute('data-set-state')) === state);
    });
  };

  const parseHm = (s) => {
    s = String(s||'').trim();
    const m = /^(\d{1,2}):(\d{2})$/.exec(s);
    if (!m) return null;
    const hh = Math.max(0, Math.min(23, parseInt(m[1],10)));
    const mm = Math.max(0, Math.min(59, parseInt(m[2],10)));
    return hh*60 + mm;
  };

  const isWithinOfficeHours = () => {
    const o = (CFG.office && typeof CFG.office === 'object') ? CFG.office : {};
    if (!o.enabled) return true;

    const d = new Date();
    const day = (d.getDay() === 0) ? 7 : d.getDay();
    const days = Array.isArray(o.days) ? o.days : [1,2,3,4,5];
    if (!days.includes(day)) return false;

    const openMin  = parseHm(o.open)  ?? (8*60);
    const closeMin = parseHm(o.close) ?? (18*60);
    const nowMin = d.getHours()*60 + d.getMinutes();

    return (nowMin >= openMin && nowMin < closeMin);
  };

  const apply = (state) => {
    if (!dot) return;

    state = normalize(state);

    const inOffice = isWithinOfficeHours();

    // außerhalb Office-Hours immer "off"
    if (!inOffice) {
      state = 'off';
    } else {
      // innerhalb Office-Hours: CRM "off" => z.B. "away"
      if (state === 'off') {
        state = normalize(CFG.office_off_maps_to || 'away');
      }
    }

    dot.setAttribute('data-state', state);

    const t = (CFG.texts && CFG.texts[state]) ? CFG.texts[state] : null;
    if (t) {
      title.textContent = String(t.title || '');
      text.textContent  = String(t.text  || '');
    } else {
      title.textContent = (state === 'online') ? 'Online' : 'Nicht verfügbar';
      text.textContent  = (state === 'online') ? 'Fernwartung aktuell möglich.' : 'Bitte Kontaktformular nutzen.';
    }

    markChips(state);
  };

  const fetchJsonSafe = async (url, opts) => {
    const r = await fetch(url, opts);
    const raw = await r.text();
    let j = null;
    try { j = JSON.parse(raw); } catch(e) { j = null; }
    return { status: r.status, ok: r.ok, json: j, raw };
  };
  /* ===============================================================================================
     END: HELPERS (STATE / UI)
     =============================================================================================== */


  /* ===============================================================================================
     BEGIN: ONLINE STATUS (POLLING)
     =============================================================================================== */
  const pollOnce = async () => {
    try {
      const res = await fetchJsonSafe('/api/status_public.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      });

      const j = (res.json && typeof res.json === 'object') ? res.json : {};
      apply(j.effective_state ?? j.state ?? 'off');
    } catch(e) {
      apply('off');
    }
  };
  /* ===============================================================================================
     END: ONLINE STATUS (POLLING)
     =============================================================================================== */


  /* ===============================================================================================
     BEGIN: TEAMVIEWER SUPPORT (CODE -> LINK -> REDIRECT)
     =============================================================================================== */
  const tvCode  = document.getElementById('tvCode');
  const tvMsg   = document.getElementById('tvMsg');
  const tvStart = document.getElementById('tvStart');
  const tvHelp  = document.getElementById('tvHelp');

  const TV_MAX = 10;

  const tvSetMsg = (s, kind) => {
    if (!tvMsg) return;
    tvMsg.textContent = String(s || '');

    tvMsg.classList.remove('msg--error', 'msg--info');
    if (kind === 'error') tvMsg.classList.add('msg--error');
    if (kind === 'info')  tvMsg.classList.add('msg--info');
  };

  const tvSetBusy = (busy) => {
    if (tvStart) tvStart.disabled = !!busy;
    if (tvHelp)  tvHelp.disabled  = !!busy;
    if (tvCode)  tvCode.disabled  = !!busy;
  };

  const tvNormalizeCode = (v) => {
    v = String(v || '').replace(/\D+/g, '');
    if (v.length > TV_MAX) v = v.slice(0, TV_MAX);
    return v;
  };

  const tvGetCode = () => {
    const v = tvNormalizeCode(tvCode?.value || '');
    if (tvCode && tvCode.value !== v) tvCode.value = v;
    return v;
  };

  // Live-Filter: nur Ziffern + max length (auch bei paste)
  tvCode?.addEventListener('input', () => { tvGetCode(); });

  // Enter startet verbinden
  tvCode?.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      tvStart?.click();
    }
  });

  tvStart?.addEventListener('click', async () => {
    const code = tvGetCode();
    if (!code) { tvSetMsg('Bitte Code eingeben.', 'error'); return; }

    tvSetBusy(true);
    tvSetMsg('Bitte warten …', 'info');

    try {
      const r = await fetch('/api/tv_support_start.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
        body: JSON.stringify({ code })
      });

      const raw = await r.text();
      let j = null;
      try { j = JSON.parse(raw); } catch(e) { j = null; }

      if (!r.ok || !j || !j.ok || !j.link) {
        tvSetBusy(false);
        tvSetMsg("Code ungültig/abgelaufen.\nBitte neuen Code anfordern.", 'error');
        return;
      }

      tvSetMsg('Weiterleitung …', 'info');
      window.location.href = String(j.link);
    } catch (e) {
      tvSetBusy(false);
      tvSetMsg("Fehler beim Verbinden.\nBitte erneut versuchen.", 'error');
    }
  });

  tvHelp?.addEventListener('click', () => {
    tvSetMsg("Hinweis: Sie erhalten den Code vom Techniker.\nBei Problemen bitte Kontaktformular nutzen.", 'info');
  });
  /* ===============================================================================================
     END: TEAMVIEWER SUPPORT (CODE -> LINK -> REDIRECT)
     =============================================================================================== */


  /* ===============================================================================================
     BEGIN: INIT
     =============================================================================================== */
  pollOnce();
  window.setInterval(pollOnce, Math.max(2000, (CFG.poll_ms|0) || 10000));

  // Public Seite: Buttons sind Anzeige (kein POST)
  document.querySelectorAll('[data-set-state]').forEach(btn => {
    btn.addEventListener('click', (e) => { e.preventDefault(); });
  });
  /* ===============================================================================================
     END: INIT
     =============================================================================================== */

})();
/* =================================================================================================
   END: SERVICE PAGE JS
   ================================================================================================= */
</script>

</body>
</html>
