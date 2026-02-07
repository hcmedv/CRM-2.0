<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/page_bottom.php
 * Zweck:
 * - Schließt page-block
 * - Rendert Footer innerhalb des App-Rahmens
 * - Schließt HTML
 */

$appVer = (string)CRM_CFG('app_version', 'dev');

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

  function close(){
    box.classList.remove('is-open');
    if(btn) btn.setAttribute('aria-expanded','false');
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


<?php
// Session-Daten (best-effort)
$loginAt = (int)($_SESSION['login_at'] ?? 0);
$lastAct = (int)($_SESSION['last_activity'] ?? 0);
$profile = (string)($_SESSION['session_profile'] ?? '');

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
  'login_at'       => $loginAt,
  'last_activity'  => $lastAct,
  'profile'        => $profile !== '' ? $profile : 'unknown',
  'idle_timeout'   => $idle,
  'max_lifetime'   => $maxLife,
  'now_server'     => time(),
];
?>
<script>
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

      // abgelaufen
      if (r.rem <= 0) {
        el.textContent = 'Session abgelaufen (reload)';
        return;
      }

      // Anzeige
      el.textContent = `${fmt(r.rem)} (${S.profile || '-:-'})`;
    };

    render();
    window.setInterval(render, 1000);
  })();
</script>





</body>
</html>
