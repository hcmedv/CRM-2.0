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


</body>
</html>
