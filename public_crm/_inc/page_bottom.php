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

</body>
</html>
