<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/login/totp_setup.php
 * Zweck (PoC):
 * - Zeigt den QR-Code (TOTP) für den aktuell eingeloggten User an
 * - Liest 2fa / 2fa_secret aus /data/login/mitarbeiter.json
 * - KEIN Speichern, KEIN Enforce, nur Anzeige zum Einrichten am Handy
 *
 * Voraussetzungen:
 * - phpqrcode liegt unter: /public_crm/_lib/_phpqrcode/*
 */

require_once dirname(__DIR__) . '/_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

/*
if (!CRM_Auth_IsLoggedIn()) {
    header('Location: /login/');
    exit;
}
*/



define('TOTP_ISSUER', 'HCM EDV CRM 2.0'); // Anzeige in Authenticator
define('TOTP_PERIOD', 30);
define('TOTP_DIGITS', 6);

// Pfad zur phpqrcode lib
define('PHPQRCODE_QRLIB', CRM_ROOT . '/_lib/_phpqrcode/qrlib.php');

$u     = (array)($_SESSION['crm_user'] ?? []);
$uname = (string)($u['user'] ?? $u['name'] ?? '');
$label = $uname !== '' ? $uname : 'crm-user';

$rec = null;
$data = json_decode((string)@file_get_contents(CRM_LOGIN_FILE), true);
if (is_array($data)) {
    foreach ($data as $row) {
        if ((string)($row['user'] ?? '') === (string)($u['user'] ?? '')) {
            $rec = is_array($row) ? $row : null;
            break;
        }
    }
}

$twofaEnabled = (bool)($rec['2fa'] ?? false);
$secret       = (string)($rec['2fa_secret'] ?? '');

$err = '';
$uri = '';
$qr  = '';

if (!$twofaEnabled) {
    $err = '2FA ist für diesen Benutzer nicht aktiv (mitarbeiter.json: "2fa": true).';
} elseif ($secret === '') {
    $err = 'Kein 2FA Secret hinterlegt (mitarbeiter.json: "2fa_secret").';
} else {
    $uri = FN_BuildOtpAuthUri(TOTP_ISSUER, $label, $secret, TOTP_DIGITS, TOTP_PERIOD);
    $qr  = FN_QrDataUriPng($uri);
    if ($qr === '') {
        $err = 'QR konnte nicht erzeugt werden (phpqrcode Pfad / Deprecated Output).';
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2FA Setup</title>
<link rel="stylesheet" href="/_inc/assets/crm.css">
<style>
  .wrap{ max-width: 520px; margin: 24px auto; }
  .box{ display:grid; gap: 12px; }
  .err{ color:#b00020; font-weight:700; }
  .hint{ opacity:.85; }
  .qrimg{ width: 280px; height: 280px; border:1px solid rgba(0,0,0,.16); border-radius:12px; }
  code{ word-break: break-all; font-size: 12px; }
</style>
</head>
<body>

<main class="app wrap">
  <div class="page-block">
    <div class="card card--wide">
      <div class="card__title">2FA Setup</div>
      <div class="card__body box">

        <?php if ($err !== ''): ?>
          <div class="err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
        <?php else: ?>
          <div class="hint">
            Microsoft Authenticator: Konto hinzufügen → „Anderes (Google)“ → QR scannen.
          </div>
          <img class="qrimg" alt="QR" src="<?= htmlspecialchars($qr, ENT_QUOTES) ?>">

          <details>
            <summary>Secret (Base32) anzeigen</summary>
            <code><?= htmlspecialchars($secret, ENT_QUOTES) ?></code>
          </details>

          <details>
            <summary>otpauth URI anzeigen</summary>
            <code><?= htmlspecialchars($uri, ENT_QUOTES) ?></code>
          </details>
        <?php endif; ?>

        <div>
          <a href="/index.php">Zurück</a>
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
