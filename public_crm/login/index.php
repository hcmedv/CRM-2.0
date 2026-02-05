<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

if (CRM_Auth_IsLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string)($_POST['user'] ?? ''));
    $p = (string)($_POST['pass'] ?? '');
    if (!CRM_Auth_Login($u, $p)) {
        $err = 'Login fehlgeschlagen';
    } else {
        header('Location: /index.php');
        exit;
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<link rel="stylesheet" href="/_inc/assets/crm.css">
<style>
  .login-wrap{ max-width: 420px; margin: 24px auto; }
  .login-form{ display:grid; gap: 10px; max-width: 360px; }
  .login-form input{
    font: inherit;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(0,0,0,.16);
    background: #fffbd1;
    outline: none;
  }
  .login-form input:focus{ border-color: rgba(0,123,255,.55); box-shadow: 0 0 0 3px rgba(0,123,255,.12); }
  .login-form button{
    font: inherit;
    font-weight: 700;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(0,0,0,.16);
    background: #fff;
    cursor: pointer;
  }
  .login-form button:hover{ background: rgba(0,0,0,.04); }
  .login-err{ margin-bottom:10px; color:#b00020; font-weight:700; }
</style>
</head>
<body>

<main class="app login-wrap">
  <div class="page-block">
    <div class="card card--wide">
      <div class="card__title">Login</div>
      <div class="card__body">
        <?php if ($err !== ''): ?>
          <div class="login-err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <img src="/_inc/img/logo.png" width="360" height="77" alt="HCM EDV">
        <form method="post" action="/login/" class="login-form" autocomplete="on">
          <input name="user" placeholder="Benutzer" autocomplete="username" required>
          <input name="pass" type="password" placeholder="Passwort" autocomplete="current-password" required>
          <button type="submit">Anmelden</button>
        </form>
      </div>
    </div>
  </div>

    <footer class="app-footer">
    CRM 2.0
  </footer>
</main>

</body>
</html>
