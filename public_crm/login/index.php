<?php
declare(strict_types=1);

/*
 * Datei: /public/login/index.php
 * Zweck:
 * - CRM-Login separat
 * - Prüft credentials gegen /data/login/mitarbeiter.json
 * - Setzt Session und leitet weiter
 */

define('CRM_PAGE_TITLE', 'Login');

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

if (CRM_Auth_IsLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string)($_POST['user'] ?? ''));
    $p = (string)($_POST['pass'] ?? '');

    if ($u !== '' && $p !== '' && CRM_Auth_Login($u, $p)) {
        header('Location: /');
        exit;
    }
    $error = 'Login fehlgeschlagen';
}

require_once CRM_ROOT . '/_inc/page_top.php';
?>

<div class="login-block">
  <form method="post" class="login-block__form" autocomplete="off">
    <div class="login-block__title">CRM Login</div>

    <?php if ($error !== ''): ?>
      <div class="login-block__error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="login-block__field">
      <label class="login-block__label" for="user">Benutzer</label>
      <input class="login-block__input" id="user" name="user" required>
    </div>

    <div class="login-block__field">
      <label class="login-block__label" for="pass">Passwort</label>
      <input class="login-block__input" id="pass" name="pass" type="password" required>
    </div>

    <button class="login-block__btn" type="submit">Login</button>
  </form>
</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
