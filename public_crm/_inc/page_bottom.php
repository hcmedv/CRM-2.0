<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/page_bottom.php
 * Zweck:
 * - Schließt page-block
 * - Rendert Footer innerhalb des App-Rahmens
 * - Schließt HTML
 *
 * Enthält:
 * - Flyout Toggle (User-Menü)
 * - Globaler CRM_FetchJson Helper (robust: 401/403 + HTML statt JSON)
 * - Session-Countdown Rendering (Suffix im CTI Input)
 *
 * WICHTIG:
 * - Session-Keys sind in auth.php als crm_login_at / crm_last_activity gesetzt.
 * - session_profile ist optional; wenn nicht gesetzt -> wird per Whitelist (IP) abgeleitet.
 */

$appVer = (string)CRM_CFG('app_version', 'dev');

// ---------------- Session-Daten (best-effort) ----------------
$loginAt = (int)($_SESSION['crm_login_at'] ?? 0);
$lastAct = (int)($_SESSION['crm_last_activity'] ?? 0);

// Profil: wenn nicht gesetzt, best-effort aus IP Whitelist ableiten (wie im Guard)
$profile = (string)($_SESSION['session_profile'] ?? '');
if ($profile === '') {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $wl = (array)CRM_CFG('session_ip_whitelist', []);
    $wl = array_values(array_filter(array_map('trim', array_map('strval', $wl))));
    $profile = ($ip !== '' && in_array($ip, $wl, true)) ? 'office' : 'remote';
}

// Settings (aus CRM_CFG)
$idleFallback = (int)CRM_CFG('session_idle_timeout_sec', 0);
$idleOffice   = (int)CRM_CFG('session_idle_timeout_office_sec', 0);
$idleRemote   = (int)CRM_CFG('session_idle_timeout_remote_sec', 0);
$maxLife      = (int)CRM_CFG('session_max_lifetime_sec', 0);

// Idle je Profil bestimmen (wie im Guard)
$idle = 0;
if ($profile === 'office' && $idleOffice > 0) { $idle = $idleOffice; }
elseif ($profile === 'remote' && $idleRemote > 0) { $idle = $idleRemote; }
else { $idle = $idleFallback; }

$payload = [
    'login_at'      => $loginAt,
    'last_activity' => $lastAct,
    'profile'       => $profile !== '' ? $profile : 'unknown',
    'idle_timeout'  => $idle,
    'max_lifetime'  => $maxLife,
    'now_server'    => time(),
];

?>
  </div>

  <footer class="app-footer">
    &copy; /// HCM EDV GmbH · CRM 2.0 · <?= htmlspecialchars($appVer, ENT_QUOTES) ?>
  </footer>
</main>

<script>
(function(){
  const box = document.getElementById('crmUserBox');
  if(!box) return;

  const btn = box.querySelector('.userbtn');
  const fly = box.querySelector('.userfly');
  if(!btn) return;

  function close(){
    box.classList.remove('is-open');
    btn.setAttribute('aria-expanded','false');
  }

  function toggle(){
    const open = !box.classList.contains('is-open');
    if(open){
      box.classList.add('is-open');
      btn.setAttribute('aria-expanded','true');
    }else{
      close();
    }
  }

  btn.addEventListener('click', function(ev){
    ev.stopPropagation();
    toggle();
  });

  document.addEventListener('click', function(){
    close();
  });

  document.addEventListener('keydown', function(ev){
    if(ev.key === 'Escape') close();
  });

  if(fly){
    fly.addEventListener('click', function(ev){
      ev.stopPropagation();
    });
  }
})();
</script>

<script>
/* =================================================================================================
   CRM Fetch Helper (JSON) – global
   - Einheitliche Fehlerbehandlung für alle API-Calls (JSON erwartet)
   - 401/403 -> Session abgelaufen -> Redirect /login
   - HTML statt JSON -> Session abgelaufen (typisch Redirect/HTML Login)
   - Keine JSON.parse SyntaxError mehr im UI
   ================================================================================================= */
(function(){
  function FN_CRM_ShowSessionExpired(msg)
  {
    msg = String(msg || 'Session abgelaufen. Bitte neu anmelden.');
    try { console.warn('[CRM] session expired:', msg); } catch(e) {}
    try { alert(msg); } catch(e) {}
    window.location.href = '/login';
  }

  async function FN_CRM_FetchJson(url, opts)
  {
    opts = (opts && typeof opts === 'object') ? opts : {};

    const headers = Object.assign(
      { 'Accept': 'application/json' },
      (opts.headers && typeof opts.headers === 'object') ? opts.headers : {}
    );

    let r;
    try {
      r = await fetch(url, Object.assign({
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: headers
      }, opts));
    } catch (e) {
      return { ok: false, status: 0, json: null, raw: '', error: 'network' };
    }

    const raw = await r.text();

    // 401/403: sicher Session weg
    if (r.status === 401 || r.status === 403) {
      FN_CRM_ShowSessionExpired('Session abgelaufen. Bitte neu anmelden.');
      return { ok: false, status: r.status, json: null, raw: raw, error: 'unauthorized' };
    }

    // HTML statt JSON: sehr wahrscheinlich Redirect/Login-Seite
    if (raw && /<html|<!doctype/i.test(raw)) {
      FN_CRM_ShowSessionExpired('Session abgelaufen. Bitte neu anmelden.');
      return { ok: false, status: r.status, json: null, raw: raw, error: 'html' };
    }

    // JSON parse
    let j = null;
    try { j = JSON.parse(raw); } catch(e) { j = null; }

    if (!j || typeof j !== 'object') {
      return { ok: false, status: r.status, json: null, raw: raw, error: 'non_json' };
    }

    // optional: API signalisiert unauthorized im JSON
    if (j.ok === false && String(j.error || '') === 'unauthorized') {
      FN_CRM_ShowSessionExpired('Session abgelaufen. Bitte neu anmelden.');
      return { ok: false, status: r.status, json: j, raw: raw, error: 'unauthorized_json' };
    }

    return { ok: !!r.ok, status: r.status, json: j, raw: raw, error: '' };
  }

  // global export
  window.CRM_ShowSessionExpired = FN_CRM_ShowSessionExpired;
  window.CRM_FetchJson = FN_CRM_FetchJson;
})();
</script>

<script>
/* =================================================================================================
   Session Countdown – inline Suffix im CTI Input
   ================================================================================================= */
(function(){
  const el = document.getElementById('crmSessionCountdown');
  if (!el) return;

  const S = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const pad2 = (n) => String(n).padStart(2,'0');
  const fmt = (sec) => {
    sec = Math.max(0, Math.floor(sec));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if (h > 0) return `${h}:${pad2(m)}:${pad2(s)}`;
    return `${m}:${pad2(s)}`;
  };

  // Drift-Korrektur: server-now vs client-now beim Render
  const t0Client = Date.now() / 1000;
  const skew = (S.now_server || 0) - t0Client; // seconds

  const calcRemaining = () => {
    const now = (Date.now() / 1000) + skew;

    let idleRem = Infinity;
    if ((S.idle_timeout || 0) > 0 && (S.last_activity || 0) > 0) {
      idleRem = (S.last_activity + S.idle_timeout) - now;
    }

    let maxRem = Infinity;
    if ((S.max_lifetime || 0) > 0 && (S.login_at || 0) > 0) {
      maxRem = (S.login_at + S.max_lifetime) - now;
    }

    const rem = Math.min(idleRem, maxRem);
    return { rem, idleRem, maxRem };
  };

  const render = () => {
    const r = calcRemaining();

    // Wenn keine Limits aktiv: nichts anzeigen
    if (!isFinite(r.rem)) {
      el.textContent = '';
      return;
    }

    // abgelaufen (nur Anzeige – echte Abmeldung macht Server)
    if (r.rem <= 0) {
      el.textContent = 'Session abgelaufen';
      return;
    }

    el.textContent = `${fmt(r.rem)} (${S.profile || '-:-'})`;
  };

  render();
  window.setInterval(render, 1000);
})();
</script>

</body>
</html>
