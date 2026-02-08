<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/login/totp.php
 * Zweck:
 * - 2FA (TOTP) Schritt nach Passwort-Login
 * - Nur für bereits eingeloggte User (PW ok), aber ohne 2FA-Flag
 * - Liest 2fa/2fa_secret aus /data/login/mitarbeiter.json
 *
 * Hinweis:
 * - APIs bleiben außen vor (Option 1).
 */

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

/*
 * Minimaler "PW-only" Guard:
 * - User muss eingeloggt sein (PW), aber 2FA darf noch fehlen.
 */
if (!CRM_Auth_IsLoggedIn()) {
    header('Location: /login/');
    exit;
}

/*
 * Falls noch nicht erfolgreich geprüft: Flag sauber auf false setzen (PoC)
 */
if (empty($_SESSION['crm_2fa_ok'])) {
    $_SESSION['crm_2fa_ok'] = false;
}

/*
 * Next Ziel (optional)
 */
$next = (string)($_GET['next'] ?? '/index.php');
if ($next === '' || $next[0] !== '/') { $next = '/index.php'; }

/*
 * User Record laden (aus mitarbeiter.json)
 */
$u     = (array)($_SESSION['crm_user'] ?? []);
$uname = (string)($u['user'] ?? '');

$rec = null;
$data = json_decode((string)@file_get_contents(CRM_LOGIN_FILE), true);
if (is_array($data)) {
    foreach ($data as $row) {
        if ((string)($row['user'] ?? '') === $uname) {
            $rec = is_array($row) ? $row : null;
            break;
        }
    }
}

$twofaEnabled = (bool)($rec['2fa'] ?? false);
$secret       = (string)($rec['2fa_secret'] ?? '');

if (!$twofaEnabled || $secret === '') {
    // User braucht kein 2FA -> direkt "durchwinken"
    $_SESSION['crm_2fa_ok'] = true;
    header('Location: ' . $next);
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
    if (FN_TotpVerify($secret, $code, 1, 30, 6)) {
        $_SESSION['crm_2fa_ok'] = true;
        header('Location: ' . $next);
        exit;
    } else {
        $err = 'Code ungültig';
        $_SESSION['crm_2fa_ok'] = false;
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2FA</title>
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
  .login-hint{ margin: 6px 0 12px 0; opacity:.8; }
</style>
</head>
<body>

<main class="app login-wrap">
  <div class="page-block">
    <div class="card card--wide">
      <div class="card__title">2FA</div>
      <div class="card__body">
        <?php if ($err !== ''): ?>
          <div class="login-err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <img src="/_inc/img/logo.png" width="360" height="77" alt="HCM EDV">

        <div class="login-hint">Bitte 6-stelligen Code aus dem Authenticator eingeben.</div>

        <form method="post" action="/login/totp?next=<?= htmlspecialchars(rawurlencode($next), ENT_QUOTES) ?>" class="login-form" autocomplete="off">
          <input name="code" inputmode="numeric" placeholder="123456" maxlength="6" required autofocus>
          <button type="submit">Bestätigen</button>
        </form>

        <div style="margin-top:10px">
          <a href="/login/logout.php">Abbrechen / Logout</a>
        </div>
      </div>
    </div>
  </div>

  <footer class="app-footer">
    CRM 2.0
  </footer>
</main>

</body>
</html>

<?php
/*
 * TOTP Prüfer (RFC6238) – aus PoC
 */
function FN_TotpVerify(string $secretB32, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
{
    $code = preg_replace('/\D+/', '', $code);
    if (strlen($code) !== $digits) { return false; }

    $now = time();
    $counterNow = (int)floor($now / $period);

    for ($i = -$window; $i <= $window; $i++) {
        $expected = FN_Hotp($secretB32, $counterNow + $i, $digits);
        if (hash_equals($expected, $code)) { return true; }
    }

    return false;
}

function FN_Hotp(string $secretB32, int $counter, int $digits = 6): string
{
    $key = FN_Base32Decode($secretB32);

    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);

    $offset = ord(substr($hash, -1)) & 0x0F;
    $part   = substr($hash, $offset, 4);

    $value = (unpack('N', $part)[1] & 0x7FFFFFFF);
    $mod   = 10 ** $digits;

    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

function FN_Base32Decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) { continue; }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $chunk = substr($bits, $i, 8);
        if (strlen($chunk) < 8) { break; }
        $out .= chr(bindec($chunk));
    }

    return $out;
}
