<?php
declare(strict_types=1);

/*
 * totp_poc.php
 * Single-File PoC: Passwort + TOTP (RFC6238) + QR INLINE (Data-URI) via phpqrcode (GD)
 */

######## SETTINGS ##########################################################################################################################
define('POC_PASSWORD', 'test123');                         // nur Test
define('POC_SECRET_B32', 'JBSWY3DPEHPK3PXP');              // Base32 Secret (fest)
define('TOTP_ISSUER', 'PoC-Web');
define('TOTP_LABEL', 'user@test');
define('TOTP_DIGITS', 6);
define('TOTP_PERIOD', 30);
define('TOTP_WINDOW', 1);                                  // +/- 30s


require_once __DIR__ . '/../_inc/bootstrap.php';
#require_once CRM_ROOT . '/_inc/auth.php';
#CRM_Auth_RequireLogin();

#echo CRM_ROOT; #/www/htdocs/w010b08d/crm.hcmedv.de/public_crm
define('PHPQRCODE_QRLIB', CRM_ROOT . '/_lib/_phpqrcode/qrlib.php');



######## MAIN #############################################################################################################################


$action = (string)($_GET['a'] ?? '');
$msg = '';

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ?');
    exit;
}

if ($action === 'login') {
    $pw = trim((string)($_POST['pw'] ?? ''));
    if (!hash_equals(POC_PASSWORD, $pw)) {
        $msg = 'Passwort falsch.';
    } else {
        $_SESSION['pw_ok'] = true;
        header('Location: ?');
        exit;
    }
}

if ($action === 'verify') {
    if (empty($_SESSION['pw_ok'])) {
        $msg = 'Erst Passwort.';
    } else {
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $ok = FN_TotpVerify(POC_SECRET_B32, $code, TOTP_WINDOW, TOTP_PERIOD, TOTP_DIGITS);
        if ($ok) {
            $_SESSION['totp_ok'] = true;
            header('Location: ?');
            exit;
        } else {
            $msg = 'TOTP falsch.';
        }
    }
}

$pwOk   = !empty($_SESSION['pw_ok']);
$totpOk = !empty($_SESSION['totp_ok']);

$uri     = FN_BuildOtpAuthUri(TOTP_ISSUER, TOTP_LABEL, POC_SECRET_B32, TOTP_DIGITS, TOTP_PERIOD);
$qrData  = FN_QrDataUriPng($uri);

?>
<!doctype html>
<meta charset="utf-8">
<title>TOTP PoC (Single File)</title>
<style>
  body{font-family:system-ui;margin:24px;max-width:820px}
  .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:0 0 16px 0}
  .row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start}
  input{padding:10px 12px;border:1px solid #ccc;border-radius:10px}
  button{padding:10px 12px;border:1px solid #ccc;border-radius:10px;background:#f6f6f6;cursor:pointer}
  .msg{padding:10px 12px;border:1px solid #eee;border-radius:10px;background:#fafafa;margin:0 0 16px 0}
  code{word-break:break-all}
</style>

<h2>TOTP PoC (Single File, QR intern)</h2>

<?php if ($msg !== ''): ?>
  <div class="msg"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
<?php endif; ?>

<div class="card">
  <div><b>Status</b></div>
  <div>Passwort: <?= $pwOk ? 'OK' : 'NEIN' ?></div>
  <div>TOTP: <?= $totpOk ? 'OK' : 'NEIN' ?></div>
  <div style="margin-top:10px"><a href="?a=logout">Logout/Reset</a></div>
</div>

<div class="row">
  <div class="card" style="flex:1;min-width:300px">
    <div style="margin:0 0 10px 0"><b>1) Passwort</b></div>
    <form method="post" action="?a=login">
      <input type="password" name="pw" >
      <button type="submit">Prüfen</button>
    </form>
    <div style="margin-top:10px;font-size:12px;opacity:.75">Test-Passwort: <?=htmlspecialchars(POC_PASSWORD, ENT_QUOTES, 'UTF-8')?></div>
  </div>

  <div class="card" style="flex:1;min-width:300px">
    <div style="margin:0 0 10px 0"><b>2) Authenticator einrichten</b></div>

    <?php if ($qrData !== ''): ?>
      <img alt="QR" style="width:260px;height:260px;border:1px solid #ccc;border-radius:12px" src="<?=$qrData?>">
    <?php else: ?>
      <div>QR konnte nicht erzeugt werden (Pfad/Lib prüfen).</div>
    <?php endif; ?>

    <div style="margin-top:10px;font-size:12px;opacity:.75">
      Microsoft Authenticator: Konto hinzufügen → „Anderes (Google)“ → QR scannen.
    </div>

    <details style="margin-top:10px">
      <summary>otpauth URI anzeigen</summary>
      <code><?=htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')?></code>
    </details>

    <details style="margin-top:10px">
      <summary>Secret (Base32) anzeigen</summary>
      <code><?=htmlspecialchars(POC_SECRET_B32, ENT_QUOTES, 'UTF-8')?></code>
    </details>
  </div>
</div>

<div class="card">
  <div style="margin:0 0 10px 0"><b>3) TOTP prüfen</b></div>
  <form method="post" action="?a=verify">
    <input name="code" inputmode="numeric" placeholder="123456" maxlength="6">
    <button type="submit">Prüfen</button>
  </form>
</div>

<?php
######## FUNCTIONS #########################################################################################################################
function FN_QrDataUriPng(string $data): string
{
    if (!is_file(PHPQRCODE_QRLIB)) { return ''; }

    // Deprecated/Warnings beim PNG-Rendern unterdrücken (sonst kaputtes PNG)
    $oldDisplay = ini_get('display_errors');
    $oldLevel   = error_reporting();

    ini_set('display_errors', '0');
    error_reporting($oldLevel & ~E_DEPRECATED);

    require_once PHPQRCODE_QRLIB;

    // Buffer sauber
    while (ob_get_level() > 0) { @ob_end_clean(); }

    ob_start();
    QRcode::png($data, null, QR_ECLEVEL_M, 6, 2);
    $png = ob_get_clean();

    // Restore
    error_reporting($oldLevel);
    ini_set('display_errors', (string)$oldDisplay);

    if (!is_string($png) || $png === '') { return ''; }

    return 'data:image/png;base64,' . base64_encode($png);
}




function FN_BuildOtpAuthUri(string $issuer, string $label, string $secretB32, int $digits = 6, int $period = 30): string
{
    $issuerEnc = rawurlencode($issuer);
    $labelEnc  = rawurlencode($label);

    return 'otpauth://totp/' . $issuerEnc . ':' . $labelEnc
        . '?secret=' . rawurlencode($secretB32)
        . '&issuer=' . $issuerEnc
        . '&algorithm=SHA1'
        . '&digits=' . (int)$digits
        . '&period=' . (int)$period;
}

function FN_TotpVerify(string $secretB32, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
{
    $code = preg_replace('/\D+/', '', $code);
    if (strlen($code) !== $digits) return false;

    $now = time();
    $counterNow = (int)floor($now / $period);

    for ($i = -$window; $i <= $window; $i++) {
        $expected = FN_Hotp($secretB32, $counterNow + $i, $digits);
        if (hash_equals($expected, $code)) return true;
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
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $chunk = substr($bits, $i, 8);
        if (strlen($chunk) < 8) break;
        $out .= chr(bindec($chunk));
    }

    return $out;
}
