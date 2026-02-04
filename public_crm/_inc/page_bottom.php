<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/page_bottom.php
 * Zweck:
 * - Einheitlicher Seitenabschluss
 */

$appVer = (string)CRM_CFG('app_version', '');
?>

</main>

<footer class="app-footer">
  <?php if ($appVer !== ''): ?>
    <span class="app-footer__meta"><?= htmlspecialchars($appVer, ENT_QUOTES) ?></span>
  <?php endif; ?>
</footer>

</div>

<?php if (defined('CRM_DEBUG') && CRM_DEBUG): ?>
<!-- DEBUG ACTIVE -->
<?php endif; ?>

</body>
</html>
