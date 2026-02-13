<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/bericht_einsatz/crm_bericht_einsatz_send.php
 * Zweck:
 * - Bericht Einsatz: PDF erzeugen
 * - do=pdf  -> nur Browser (kein Versand)
 * - do=doc  -> Versand (SMTP über M365) + optional Browser (je nach Settings)
 *
 * Settings:
 * - /config/bericht_einsatz/settings_bericht_einsatz.php   (return ['bericht_einsatz' => [...]];)
 * - /config/bericht_einsatz/secrets_bericht_einsatz.php    (return ['bericht_einsatz' => ['smtp_user'=>..,'smtp_pass'=>..]];)
 */

if (!defined('LOG_CHANNEL')) {
    define('LOG_CHANNEL', 'crm_bericht_einsatz_send.php');
}



// File-Guard: verhindert Doppel-Include (z.B. durch bootstrap/router)
if (defined('CRM_BE_SEND_LOADED')) { return; }
define('CRM_BE_SEND_LOADED', true);

// Include-Guard: verhindert doppelte Includes (Router/Preload/Tests) ohne function_exists-Hacks
if (defined('CRM_BERICHT_EINSATZ_SEND_INCLUDED')) {
    return;
}
define('CRM_BERICHT_EINSATZ_SEND_INCLUDED', true);
$MDO = 'bericht_einsatz';

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';

/* Session fallback */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* Login erzwingen */
CRM_Auth_RequireLogin();

/* CSRF optional (je nach Projektstand) */
if (function_exists('CRM_CsrfRequireForWrite')) {
    CRM_CsrfRequireForWrite(false);
} elseif (function_exists('CRM_Csrf_RequireForWrite')) {
    CRM_Csrf_RequireForWrite(false);
}

######## LOGGING #########################################################################################################################

function FN_LogInfo(string $code, array $ctx = []): void
{
    if (function_exists('CRM_LogInfo')) {
        CRM_LogInfo($code, $ctx);
        return;
    }
    error_log('[INFO][' . LOG_CHANNEL . '][' . $code . '] ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function FN_LogError(string $code, array $ctx = []): void
{
    if (function_exists('CRM_LogError')) {
        CRM_LogError($code, $ctx);
        return;
    }
    error_log('[ERROR][' . LOG_CHANNEL . '][' . $code . '] ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

######## HELPERS #########################################################################################################################

function FN_WantsHtml(): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept === '') { return true; }
    if (stripos($accept, 'text/html') !== false) { return true; }
    if (stripos($accept, 'application/xhtml+xml') !== false) { return true; }
    return false;
}

function FN_RenderErrorPage(int $code, string $title, string $msg): void
{
    http_response_code($code);

    FN_LogError('render_error_page', ['code' => $code, 'title' => $title, 'msg' => $msg]);

    if (!FN_WantsHtml()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $title . "\n" . $msg;
        exit;
    }

    require_once CRM_ROOT . '/_inc/page_top.php';
    echo '<div class="crm-page"><main class="page">';
    echo '<div class="card card--wide">';
    echo '<div class="card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="card__body">';
    echo '<div class="muted">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="actions" style="margin-top:12px; justify-content:flex-start;">';
    echo '<a class="crm-btn" href="/bericht_einsatz/">Zurück</a>';
    echo '</div></div></div></main></div>';
    require_once CRM_ROOT . '/_inc/page_bottom.php';
    exit;
}

function FN_Clean(string $s): string
{
    $s = trim($s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    return $s;
}

function FN_StripHeaderLine(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function FN_FormatDateDE(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') { return '-'; }

    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if ($dt instanceof DateTime) {
        return $dt->format('d.m.Y');
    }
    return $iso;
}

function FN_PdfDateISO(string $iso): string
{
    $iso = trim($iso);
    if ($iso !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) { return $iso; }
    return date('Y-m-d');
}

function FN_PdfOneLine(string $s): string
{
    $s = trim($s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    $s = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $s);
    return $s;
}

function FN_BuildPdfMeta(array $data): array
{
    $iso = FN_PdfDateISO((string)($data['datum_iso'] ?? ''));
    $de  = FN_FormatDateDE($iso);

    $kunde = FN_PdfOneLine((string)($data['kunde'] ?? ''));
    $mit   = FN_PdfOneLine((string)($data['mitarbeiter'] ?? ''));
    $title = FN_PdfOneLine((string)($data['title'] ?? ''));

    $filename = $iso . '_Bericht_Einsatz.pdf';
    $pdfTitle = 'Bericht Einsatz – ' . $de;

    $subject = $title !== '' ? $title : ('Bericht Einsatz vom ' . $de);
    if (function_exists('mb_strlen') && mb_strlen($subject, 'UTF-8') > 160) {
        $subject = mb_substr($subject, 0, 160, 'UTF-8') . '…';
    } elseif (strlen($subject) > 160) {
        $subject = substr($subject, 0, 160) . '…';
    }

    $kw = array_filter([
        'Bericht Einsatz',
        $iso,
        ($kunde !== '' ? $kunde : null),
        ($mit !== '' ? $mit : null),
    ]);
    $keywords = implode(', ', $kw);

    $author  = ($mit !== '' ? $mit : 'HCM EDV');
    $creator = 'CRM Bericht Einsatz';

    return [
        'filename' => $filename,
        'title'    => $pdfTitle,
        'subject'  => $subject,
        'keywords' => $keywords,
        'author'   => $author,
        'creator'  => $creator,
    ];
}

function FN_RenderTemplate(string $tpl, array $vars): string
{
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . strtoupper((string)$k) . '}', (string)$v, $tpl);
    }
    return $tpl;
}

function FN_ParseRecipientList(string $primary, string $more): array
{
    $all = trim($primary);

    $more = trim($more);
    if ($more !== '') {
        $all .= ($all !== '' ? ',' : '') . $more;
    }

    $all = str_replace(["\r\n", "\n", "\r", ";"], ",", $all);

    $parts = array_filter(array_map('trim', explode(",", $all)));

    $out = [];
    foreach ($parts as $mail) {
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($mail)] = $mail;
        }
    }

    return array_values($out);
}

function FN_ArrayMergeDeep(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
            $a[$k] = FN_ArrayMergeDeep($a[$k], $v);
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

function FN_LoadModCfg(string $mod): array
{
    $root = dirname(CRM_ROOT);

    $settingsFile = $root . '/config/' . $mod . '/settings_' . $mod . '.php';
    $secretsFile  = $root . '/config/' . $mod . '/secrets_' . $mod . '.php';

    $settings = (is_file($settingsFile) ? (array)require $settingsFile : []);
    $secrets  = (is_file($secretsFile)  ? (array)require $secretsFile  : []);

    $cfg = (array)($settings[$mod] ?? []);
    $sec = (array)($secrets[$mod] ?? []);

    if (!isset($cfg['backend'])) { $cfg['backend'] = []; }
    if (!isset($cfg['backend']['smtp'])) { $cfg['backend']['smtp'] = []; }

    // SMTP Credentials aus secrets mergen
    if (empty($cfg['backend']['smtp']['user']) && !empty($sec['smtp_user'])) {
        $cfg['backend']['smtp']['user'] = (string)$sec['smtp_user'];
    }
    if (empty($cfg['backend']['smtp']['pass']) && !empty($sec['smtp_pass'])) {
        $cfg['backend']['smtp']['pass'] = (string)$sec['smtp_pass'];
    }

    return $cfg;
}

function FN_ResolveActionCfg(array $cfg, string $do): array
{
    $do = strtolower(trim($do));
    if ($do !== 'pdf' && $do !== 'doc') { $do = 'pdf'; }

    $defaults = (array)($cfg['defaults'] ?? []);
    $actions  = (array)($cfg['actions'] ?? []);
    $over     = (array)($actions[$do] ?? []);

    // Ergebnis: TOP-LEVEL = defaults + action override
    $out = $cfg;
    unset($out['defaults'], $out['actions']);

    foreach ($defaults as $k => $v) { $out[$k] = $v; }
    foreach ($over as $k => $v) { $out[$k] = $v; }

    // do=pdf ist immer browser-only (sicher)
    if ($do === 'pdf') {
        $out['output_mode']      = 'browser';
        $out['send_to_customer'] = false;
        $out['send_to_internal'] = false;
    }

    if (!empty($out['internal_only'])) {
        $out['send_to_customer'] = false;
    }

    // normalize output_mode
    $om = strtolower(trim((string)($out['output_mode'] ?? 'both')));
    if ($om !== 'browser' && $om !== 'mail' && $om !== 'both') { $om = 'both'; }
    $out['output_mode'] = $om;

    return $out;
}

function FN_CfgBool(array $cfg, string $k, bool $def): bool
{
    return (isset($cfg[$k]) ? (bool)$cfg[$k] : $def);
}

function FN_CfgStr(array $cfg, string $k, string $def = ''): string
{
    $v = (string)($cfg[$k] ?? $def);
    return $v;
}

function FN_LogEnabled(array $cfg, string $eventKey, bool $default = true): bool
{
    $log = (array)($cfg['log'] ?? []);
    $enabled = (bool)($log['enabled'] ?? true);
    if (!$enabled) { return false; }

    $events = (array)($log['events'] ?? []);
    if (!array_key_exists($eventKey, $events)) { return $default; }

    return (bool)$events[$eventKey];
}

######## SMTP ############################################################################################################################

function FN_SmtpSendRaw(string $host, int $port, string $secure, string $user, string $pass, string $envFrom, string $rcptTo, string $data, array $logCtx = []): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 20);
    if (!$fp) {
        FN_LogError('smtp_connect_failed', $logCtx + ['host'=>$host,'port'=>$port,'errno'=>$errno,'errstr'=>$errstr]);
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = function() use ($fp): array {
        $lines = [];
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) { break; }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 4 && $line[3] === ' ') { break; }
        }
        $code = 0;
        if (count($lines) > 0 && preg_match('/^(\d{3})\b/', $lines[0], $m)) { $code = (int)$m[1]; }
        return [$code, $lines];
    };

    $cmd = function(string $c) use ($fp): void {
        fwrite($fp, $c . "\r\n");
    };

    $expect = function(array $want, string $step) use ($read, $logCtx): bool {
        [$code, $lines] = $read();
        if (!in_array($code, $want, true)) {
            FN_LogError('smtp_unexpected_reply', $logCtx + ['step'=>$step,'code'=>$code,'lines'=>$lines]);
            return false;
        }
        return true;
    };

    if (!$expect([220], 'greeting')) { fclose($fp); return false; }

    $heloName = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    $cmd('EHLO ' . $heloName);
    if (!$expect([250], 'ehlo1')) { fclose($fp); return false; }

    $secure = strtolower(trim($secure));
    if ($secure === 'tls' || $secure === 'starttls') {
        $cmd('STARTTLS');
        if (!$expect([220], 'starttls')) { fclose($fp); return false; }

        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            FN_LogError('smtp_starttls_crypto_failed', $logCtx);
            fclose($fp);
            return false;
        }

        $cmd('EHLO ' . $heloName);
        if (!$expect([250], 'ehlo2')) { fclose($fp); return false; }
    }

    $cmd('AUTH LOGIN');
    if (!$expect([334], 'auth_login')) { fclose($fp); return false; }

    $cmd(base64_encode($user));
    if (!$expect([334], 'auth_user')) { fclose($fp); return false; }

    $cmd(base64_encode($pass));
    if (!$expect([235], 'auth_pass')) { fclose($fp); return false; }

    $cmd('MAIL FROM:<' . $envFrom . '>');
    if (!$expect([250], 'mail_from')) { fclose($fp); return false; }

    $cmd('RCPT TO:<' . $rcptTo . '>');
    if (!$expect([250, 251], 'rcpt_to')) { fclose($fp); return false; }

    $cmd('DATA');
    if (!$expect([354], 'data')) { fclose($fp); return false; }

    // dot-stuff + terminate
    $data = preg_replace('/^\./m', '..', $data);
    $data = rtrim($data, "\r\n") . "\r\n";
    fwrite($fp, $data . "\r\n.\r\n");
    if (!$expect([250], 'data_end')) { fclose($fp); return false; }

    $cmd('QUIT');
    $expect([221], 'quit');

    fclose($fp);
    return true;
}

function FN_SendLnMailWithPdfSmtp(array $cfg, string $to, string $subject, string $bodyText, string $pdfData, string $attachName, array $extraHeaders = []): bool
{
    $backend = (array)($cfg['backend'] ?? []);
    $smtp    = (array)($backend['smtp'] ?? []);

    $enabled = (bool)($smtp['enabled'] ?? false);
    $host    = trim((string)($smtp['host'] ?? ''));
    $port    = (int)($smtp['port'] ?? 587);
    $secure  = trim((string)($smtp['secure'] ?? 'tls'));
    $user    = trim((string)($smtp['user'] ?? ''));
    $pass    = (string)($smtp['pass'] ?? '');

    if (!$enabled || $host === '' || $port <= 0 || $user === '' || $pass === '') {
        FN_LogError('smtp_config_incomplete', [
            'enabled' => $enabled,
            'host'    => $host,
            'port'    => $port,
            'user'    => ($user !== ''),
            'pass'    => ($pass !== ''),
        ]);
        return false;
    }

    $from    = trim((string)($cfg['mail_from'] ?? ''));
    $fromN   = trim((string)($cfg['mail_from_name'] ?? ''));
    $replyTo = trim((string)($cfg['mail_reply_to'] ?? ''));
    $envFrom = trim((string)($cfg['mail_envelope_from'] ?? ''));

    $to      = FN_StripHeaderLine($to);
    $subject = FN_StripHeaderLine($subject);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        FN_LogError('smtp_invalid_to', ['to' => $to]);
        return false;
    }
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        FN_LogError('smtp_invalid_from', ['from' => $from]);
        return false;
    }

    // Office365: envelope-from idealerweise = smtp user
    if ($envFrom === '' || !filter_var($envFrom, FILTER_VALIDATE_EMAIL)) {
        $envFrom = $user;
    }

    $attachName = FN_StripHeaderLine(trim((string)$attachName));
    if ($attachName === '') { $attachName = 'Bericht_Einsatz.pdf'; }

    // Header-Encoding (minimal, RFC2047) für Umlaute/UTF-8
    $encHeader = static function (string $v): string {
        $v = trim($v);
        if ($v === '') { return ''; }
        // enthält Non-ASCII?
        if (preg_match('/[^\x20-\x7E]/', $v) === 1) {
            $b64 = base64_encode($v);
            return '=?UTF-8?B?' . $b64 . '?=';
        }
        return $v;
    };

    $boundary = 'be_' . bin2hex(random_bytes(12));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';

    $fromLine = ($fromN !== '' ? ($encHeader(FN_StripHeaderLine($fromN)) . " <{$from}>") : $from);
    $headers[] = 'From: ' . $fromLine;

    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . FN_StripHeaderLine($replyTo);
    }

    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $encHeader($subject);

    // Technische Standard-Header (hilfreich bei SMTP RAW)
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-z0-9\.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) . '>';

    foreach ($extraHeaders as $hk => $hv) {
        $hk = FN_StripHeaderLine(trim((string)$hk));
        $hv = FN_StripHeaderLine(trim((string)$hv));
        if ($hk !== '' && $hv !== '') { $headers[] = $hk . ': ' . $hv; }
    }

    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    // Body (CRLF sauber)
    $bodyText = str_replace(["\r\n", "\r"], "\n", (string)$bodyText);
    $bodyText = str_replace("\n", "\r\n", $bodyText);

    $mime  = '';
    $mime .= '--' . $boundary . "\r\n";
    $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $mime .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $mime .= $bodyText . "\r\n\r\n";

    $mime .= '--' . $boundary . "\r\n";
    $mime .= 'Content-Type: application/pdf; name="' . $attachName . '"' . "\r\n";
    $mime .= "Content-Transfer-Encoding: base64\r\n";
    $mime .= 'Content-Disposition: attachment; filename="' . $attachName . '"' . "\r\n\r\n";
    $mime .= chunk_split(base64_encode($pdfData), 76, "\r\n");
    $mime .= "\r\n--" . $boundary . "--\r\n";

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $mime;

    $logCtx = [
        'to'       => $to,
        'subject'  => $subject,
        'host'     => $host,
        'port'     => $port,
        'env_from' => $envFrom,
        'from'     => $from,
    ];

    FN_LogInfo('smtp_send_try', $logCtx);

    $ok = FN_SmtpSendRaw($host, $port, $secure, $user, $pass, $envFrom, $to, $data, $logCtx);

    if ($ok) {
        FN_LogInfo('smtp_send_ok', $logCtx);
        return true;
    }

    FN_LogError('smtp_send_failed', $logCtx);
    return false;
}



######## GUARDS ##########################################################################################################################

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    FN_RenderErrorPage(405, 'Method not allowed', 'Bitte das Formular über „Bericht Einsatz“ absenden.');
}

######## ACTION / SETTINGS ###############################################################################################################

$do = strtolower(trim((string)($_POST['do'] ?? 'pdf')));
if ($do !== 'pdf' && $do !== 'doc') { $do = 'pdf'; }

$cfgBase = FN_LoadModCfg($MDO);
$cfg     = FN_ResolveActionCfg($cfgBase, $do);

if (FN_LogEnabled($cfg, 'action_resolved', true)) {
    FN_LogInfo('send_start', [
        'do'            => $do,
        'output_mode'   => (string)($cfg['output_mode'] ?? ''),
        'send_customer' => (bool)($cfg['send_to_customer'] ?? false),
        'send_internal' => (bool)($cfg['send_to_internal'] ?? false),
        'internal_only' => (bool)($cfg['internal_only'] ?? false),
    ]);
}

######## TMP #############################################################################################################################

$tmpDir = (string)(defined('CRM_TMP_DIR') ? CRM_TMP_DIR : (CRM_ROOT . '/tmp'));
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
    FN_RenderErrorPage(500, 'Interner Fehler', 'TMP-Verzeichnis nicht beschreibbar: ' . $tmpDir);
}

######## TCPDF ###########################################################################################################################

require_once CRM_ROOT . '/_lib/_tcpdf/tcpdf.php';

define('PDF_COLOR_R', 0x25);
define('PDF_COLOR_G', 0x40);
define('PDF_COLOR_B', 0x8e);

define('PDF_MARGIN_L', 15);
define('PDF_MARGIN_T', 12);
define('PDF_MARGIN_R', 15);
define('PDF_MARGIN_B', 14);

function FN_DrawSectionHeader(TCPDF $pdf, string $title): void
{
    $pdf->Ln(2);
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pageW = $pdf->getPageWidth();
    $usableW = $pageW - PDF_MARGIN_L - PDF_MARGIN_R;

    $barH = 6;

    $pdf->SetFillColor(PDF_COLOR_R, PDF_COLOR_G, PDF_COLOR_B);
    $pdf->Rect($x, $y, $usableW, $barH, 'F');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 11);

    $pdf->SetXY($x + 2, $y);
    $pdf->Cell($usableW - 4, $barH, $title, 0, 0, 'L', false);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($y + $barH + 2);
    $pdf->SetFont('helvetica', '', 10);

function FN_AddContinuationContext(TCPDF $pdf, array $ctx): void
{
    // Kopf rechts
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, 'Bericht Einsatz (Fortsetzung)', 0, 1, 'R');
    $pdf->Ln(1);

    // Kurzinfo: Auftraggeber + Auftrag
    $kundeLines = array_filter([
        ($ctx['kunde_firma'] !== '' ? $ctx['kunde_firma'] : ''),
        ($ctx['kunde_inhaber'] !== '' ? 'Inhaber/GF: ' . $ctx['kunde_inhaber'] : ''),
        ($ctx['kunde_ap'] !== '' ? 'Ansprechpartner: ' . $ctx['kunde_ap'] : ''),
        ($ctx['kunden_nummer'] !== '' ? 'KundenNr: ' . $ctx['kunden_nummer'] : ''),
        ($ctx['kunde_mail'] !== '' ? 'Mail: ' . $ctx['kunde_mail'] : ''),
    ]);

    $auftragLines = array_filter([
        ($ctx['datum_de'] !== '' ? 'Datum: ' . $ctx['datum_de'] : ''),
        ($ctx['title'] !== '' ? 'Titel: ' . $ctx['title'] : ''),
    ]);

    $pdf->SetFont('helvetica', '', 9);
    if (count($kundeLines) > 0) {
        $pdf->MultiCell(0, 4.2, implode("\n", $kundeLines), 0, 'L', false, 1);
    }
    if (count($auftragLines) > 0) {
        $pdf->Ln(1);
        $pdf->MultiCell(0, 4.2, implode("\n", $auftragLines), 0, 'L', false, 1);
    }

    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 10);
}

function FN_EnsureSpaceOrNewPage(TCPDF $pdf, float $needH, ?callable $onNewPage = null): void
{
    $pageH = $pdf->getPageHeight();
    $limit = $pageH - $pdf->getBreakMargin();
    $y     = $pdf->GetY();

    if (($y + $needH) > $limit) {
        $pdf->AddPage();
        if (is_callable($onNewPage)) {
            $onNewPage();
        }
    }
}

}

function FN_DrawCheckbox(TCPDF $pdf, float $x, float $y, float $size, bool $checked): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $size, $size, 'D');

    if ($checked) {
        $pdf->SetLineWidth(0.6);
        $pdf->Line($x + ($size * 0.18), $y + ($size * 0.55), $x + ($size * 0.42), $y + ($size * 0.78));
        $pdf->Line($x + ($size * 0.42), $y + ($size * 0.78), $x + ($size * 0.82), $y + ($size * 0.22));
        $pdf->SetLineWidth(0.2);
    }
}

function FN_ImageSizeFit(string $pngPath, float $maxW, float $maxH): array
{
    $info = @getimagesize($pngPath);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return [$maxW, $maxH];
    }

    $wPx = (float)$info[0];
    $hPx = (float)$info[1];
    if ($wPx <= 0 || $hPx <= 0) {
        return [$maxW, $maxH];
    }

    $ratio = $hPx / $wPx;

    $w = $maxW;
    $h = $w * $ratio;

    if ($h > $maxH) {
        $h = $maxH;
        $w = $h / $ratio;
    }

    return [$w, $h];
}

function FN_PlaceSignature(TCPDF $pdf, string $pngPath, float $x, float $y, float $boxW, float $boxH): void
{
    if (!is_file($pngPath)) { return; }

    [$w, $h] = FN_ImageSizeFit($pngPath, $boxW, $boxH);

    $x2 = $x + (($boxW - $w) / 2.0);
    $y2 = $y + (($boxH - $h) / 2.0);

    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Rect($x, $y, $boxW, $boxH, 'D');
    $pdf->SetDrawColor(0, 0, 0);

    $pdf->Image($pngPath, $x2, $y2, $w, $h, 'PNG');
}

function FN_SaveDataUrlPng(string $dataUrl, string $path): bool
{
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '') { return false; }

    if (preg_match('~^data:image\/png(?:;[^,]*)?;base64,(.+)$~i', $dataUrl, $m) !== 1) {
        return false;
    }

    $b64 = (string)($m[1] ?? '');
    if ($b64 === '') { return false; }

    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') { return false; }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        if (!is_dir($dir)) { return false; }
    }

    $tmp = $path . '.tmp';

    if (@file_put_contents($tmp, $bin, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, $path);
}


######## POST PARSING ####################################################################################################################

$kundenNummer   = FN_Clean((string)($_POST['kunden_nummer'] ?? ''));

$kundeFirma     = FN_Clean((string)($_POST['kunde_firma'] ?? ''));
$kundeInhaber   = FN_Clean((string)($_POST['kunde_inhaber'] ?? ''));
$kundeStr       = FN_Clean((string)($_POST['kunde_strasse'] ?? ''));
$kundePlzOrt    = FN_Clean((string)($_POST['kunde_plzort'] ?? ''));
$kundeTel       = FN_Clean((string)($_POST['kunde_telefon'] ?? ''));
$kundeAP        = FN_Clean((string)($_POST['kunde_ansprechpartner'] ?? ''));
$kundeMail      = FN_Clean((string)($_POST['kunde_auftragsmail'] ?? ''));
$kundeMailsMore = (string)($_POST['kunde_emails'] ?? '');

$title          = FN_Clean((string)($_POST['title'] ?? ''));
$beschreibung    = trim((string)($_POST['beschreibung'] ?? ''));

$allgDatum      = FN_Clean((string)($_POST['date'] ?? ''));
$allgZeitH      = FN_Clean((string)($_POST['drive_h'] ?? ''));
$allgKm         = FN_Clean((string)($_POST['drive_km'] ?? ''));
$allgMit        = FN_Clean((string)($_POST['employee'] ?? ''));

$dasiStatus     = FN_Clean((string)($_POST['dasi_status'] ?? ''));
$dasiHinweis    = trim((string)($_POST['dasi_hinweise'] ?? ''));

$eDat   = $_POST['e_dat'] ?? [];
$eStart = $_POST['e_start'] ?? [];
$eEnd   = $_POST['e_end'] ?? [];
$eDur   = $_POST['e_dur'] ?? [];
$eMit   = $_POST['e_mit'] ?? [];
if (!is_array($eDat))   { $eDat = []; }
if (!is_array($eStart)) { $eStart = []; }
if (!is_array($eEnd))   { $eEnd = []; }
if (!is_array($eDur))   { $eDur = []; }
if (!is_array($eMit))   { $eMit = []; }

$tTxt = $_POST['t_txt'] ?? [];
if (!is_array($tTxt)) { $tTxt = []; }

$mArt = $_POST['m_artno'] ?? [];
$mNam = $_POST['m_name'] ?? [];
$mQty = $_POST['m_qty'] ?? [];
$mUni = $_POST['m_unit'] ?? [];
if (!is_array($mArt)) { $mArt = []; }
if (!is_array($mNam)) { $mNam = []; }
if (!is_array($mQty)) { $mQty = []; }
if (!is_array($mUni)) { $mUni = []; }

$abnahmeStatus  = FN_Clean((string)($_POST['ab_status'] ?? ''));
$abBemerkung    = trim((string)($_POST['ab_bemerkung'] ?? ''));

$sigMainName    = FN_Clean((string)($_POST['sig_main_name'] ?? ''));
$sigDasiName    = FN_Clean((string)($_POST['sig_dasi_name'] ?? ''));

if ($sigMainName === '') { $sigMainName = ($kundeAP !== '' ? $kundeAP : $kundeInhaber); }
if ($sigDasiName === '') { $sigDasiName = ($kundeInhaber !== '' ? $kundeInhaber : $kundeAP); }

$sigMainData    = (string)($_POST['sig_main_data'] ?? '');
$sigDasiData    = (string)($_POST['sig_dasi_data'] ?? '');

$datum          = ($allgDatum !== '' ? $allgDatum : date('Y-m-d'));

$sumMinutes = 0;
foreach ($eDur as $v) {
    $n = (int)preg_replace('/[^0-9]/', '', (string)$v);
    if ($n > 0) { $sumMinutes += $n; }
}

######## SIGNATURES ######################################################################################################################

$sigMainPng = $tmpDir . '/sig_main_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
$sigDasiPng = $tmpDir . '/sig_dasi_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';

$hasSigMain = false;
$hasSigDasi = false;

if ($sigMainData !== '') { $hasSigMain = FN_SaveDataUrlPng($sigMainData, $sigMainPng); }
if ($sigDasiData !== '') { $hasSigDasi = FN_SaveDataUrlPng($sigDasiData, $sigDasiPng); }

######## PDF #############################################################################################################################

$meta = FN_BuildPdfMeta([
    'datum_iso'   => $datum,
    'kunde'       => $kundeFirma,
    'mitarbeiter' => $allgMit,
    'title'       => $title,
]);

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator($meta['creator']);
$pdf->SetAuthor($meta['author']);
$pdf->SetTitle($meta['title']);
$pdf->SetSubject($meta['subject']);
$pdf->SetKeywords($meta['keywords']);

$pdf->SetMargins(PDF_MARGIN_L, PDF_MARGIN_T, PDF_MARGIN_R);
$pdf->SetAutoPageBreak(true, PDF_MARGIN_B);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 6, 'Bericht Einsatz', 0, 1, 'R');
$pdf->Ln(1);

$pageW   = $pdf->getPageWidth();
$usableW = $pageW - PDF_MARGIN_L - PDF_MARGIN_R;
$x0      = PDF_MARGIN_L;

FN_DrawSectionHeader($pdf, 'AUFTRAGGEBER');

$pdf->SetFont('helvetica', '', 10);
$leftLines = array_filter([
    ($kundeInhaber !== '' ? 'Inhaber/GF: ' . $kundeInhaber : ''),
    ($kundeAP      !== '' ? 'Ansprechpartner: ' . $kundeAP : ''),
    ($kundeStr     !== '' ? $kundeStr : ''),
    ($kundePlzOrt  !== '' ? $kundePlzOrt : ''),
    ($kundeTel     !== '' ? 'Tel: ' . $kundeTel : ''),
]);
$pdf->MultiCell($usableW, 4.8, (count($leftLines) ? implode("\n", $leftLines) : '-'), 0, 'L', false, 1);
$pdf->Ln(2);

FN_DrawSectionHeader($pdf, 'DATENSICHERUNG');

$colGap = 8.0;
$colWL  = ($usableW * 0.55);
$colWR  = $usableW - $colWL - $colGap;

$xL = $x0;
$xR = $x0 + $colWL + $colGap;
$yBase = $pdf->GetY();

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY($xL, $yBase);
$pdf->Cell($colWL, 5, 'Bestätigung Datensicherung', 0, 2);
$pdf->SetFont('helvetica', '', 10);

$dasiMap = [
    'ok'                  => 'Datensicherung - aktuell, vorhanden & geprüft.',
    'not_present_execute' => 'Datensicherung - nicht vorhanden! Auftrag ausführen.',
    'before_start'        => 'Datensicherung - vor Auftragsbeginn ausführen.',
];
$dasiText = $dasiMap[$dasiStatus] ?? '';
$checked  = ($dasiStatus !== '' && $dasiText !== '');

$cbSize = 4.2;
$cbX    = $xL;
$cbY    = $pdf->GetY() + 0.6;

FN_DrawCheckbox($pdf, $cbX, $cbY, $cbSize, $checked);

$pdf->SetXY($xL + $cbSize + 2.0, $pdf->GetY());
$pdf->MultiCell($colWL - ($cbSize + 2.0), 4.8, ($dasiText !== '' ? $dasiText : '-'), 0, 'L', false, 1);

if (trim($dasiHinweis) !== '') {
    $pdf->SetX($xL + $cbSize + 2.0);
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->MultiCell($colWL - ($cbSize + 2.0), 4.4, 'Hinweis: ' . FN_Clean($dasiHinweis), 0, 'L', false, 1);
    $pdf->SetFont('helvetica', '', 10);
}

$yAfterLeft = $pdf->GetY();

$pdf->SetXY($xR, $yBase);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($colWR, 4.8, 'Unterschrift Auftraggeber (Datensicherung):', 0, 2);

$sigBoxH = 18.0;
$sigBoxX = $xR;
$sigBoxY = $pdf->GetY();

if ($hasSigDasi) {
    FN_PlaceSignature($pdf, $sigDasiPng, $sigBoxX, $sigBoxY, $colWR, $sigBoxH);
} else {
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Rect($sigBoxX, $sigBoxY, $colWR, $sigBoxH, 'D');
    $pdf->SetDrawColor(0, 0, 0);
}

$yAfterRight = $pdf->GetY();
$pdf->SetY(max($yAfterLeft, $yAfterRight) + 13.0);

FN_DrawSectionHeader($pdf, 'AUFTRAGSBESCHREIBUNG');
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 5, ($title !== '' ? $title . "\n" : '') . ($beschreibung !== '' ? $beschreibung : '-'), 0, 'L', false, 1);

FN_DrawSectionHeader($pdf, 'ALLGEMEINES');
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 5,
    'Datum: ' . ($allgDatum !== '' ? FN_FormatDateDE($allgDatum) : '-') . "\n" .
    'An-/Abfahrt (h): ' . ($allgZeitH !== '' ? $allgZeitH : '-') . '   Kilometer: ' . ($allgKm !== '' ? $allgKm : '-') . "\n" .
    'Mitarbeiter: ' . ($allgMit !== '' ? $allgMit : '-')
, 0, 'L', false, 1);

FN_DrawSectionHeader($pdf, 'EINSATZZEITEN');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(240, 243, 248);
$pdf->SetDrawColor(210, 215, 222);
$pdf->SetLineWidth(0.2);

$wD = 28;
$wS = 20;
$wE = 20;
$wM = 14;
$wP = $usableW - ($wD + $wS + $wE + $wM);

$pdf->Cell($wD, 7, 'Datum',  'B', 0, 'L', true);
$pdf->Cell($wS, 7, 'Beginn', 'B', 0, 'L', true);
$pdf->Cell($wE, 7, 'Ende',   'B', 0, 'L', true);
$pdf->Cell($wM, 7, 'Min',    'B', 0, 'R', true);
$pdf->Cell($wP, 7, 'Mitarbeiter', 'B', 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);

$hasE = false;
$z = false;
$rows = max(count($eDat), count($eStart), count($eEnd), count($eDur), count($eMit));
for ($i = 0; $i < $rows; $i++) {
    $d = FN_Clean((string)($eDat[$i] ?? ''));
    $s = FN_Clean((string)($eStart[$i] ?? ''));
    $e = FN_Clean((string)($eEnd[$i] ?? ''));
    $m = FN_Clean((string)($eDur[$i] ?? ''));
    $p = FN_Clean((string)($eMit[$i] ?? ''));

    if ($d === '' && $s === '' && $e === '' && $m === '' && $p === '') { continue; }

    $hasE = true;
    $fill = $z;
    $pdf->SetFillColor($fill ? 250 : 255, $fill ? 251 : 255, $fill ? 253 : 255);

    $lineH = 4.8;
    $pdf->MultiCell($wD, $lineH, ($d !== '' ? FN_FormatDateDE($d) : '-'), 'B', 'L', $fill, 0);
    $pdf->MultiCell($wS, $lineH, ($s !== '' ? $s : '-'), 'B', 'L', $fill, 0);
    $pdf->MultiCell($wE, $lineH, ($e !== '' ? $e : '-'), 'B', 'L', $fill, 0);
    $pdf->MultiCell($wM, $lineH, ($m !== '' ? $m : '-'), 'B', 'R', $fill, 0);
    $pdf->MultiCell($wP, $lineH, ($p !== '' ? $p : '-'), 'B', 'L', $fill, 1);

    $z = !$z;
}
if (!$hasE) {
    $pdf->Cell($usableW, 7, '-', 'B', 1, 'L', false);
}

$pdf->Ln(1);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'Gesamt: ' . (string)$sumMinutes . ' min', 0, 1, 'R');
$pdf->SetFont('helvetica', '', 10);

FN_DrawSectionHeader($pdf, 'TÄTIGKEITEN');
$taskLines = [];
foreach ($tTxt as $txt) {
    $txt = FN_Clean((string)$txt);
    if ($txt !== '') { $taskLines[] = '• ' . $txt; }
}
$pdf->MultiCell(0, 5, (count($taskLines) ? implode("\n", $taskLines) : '-'), 0, 'L', false, 1);

FN_DrawSectionHeader($pdf, 'MATERIAL');

$mw1 = 35;
$mw2 = 105;
$mw3 = 18;
$mw4 = 20;

$pdf->SetDrawColor(210, 215, 222);
$pdf->SetLineWidth(0.2);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(240, 243, 248);
$pdf->Cell($mw1, 7, 'Art.-Nr.',    'B', 0, 'L', true);
$pdf->Cell($mw2, 7, 'Bezeichnung', 'B', 0, 'L', true);
$pdf->Cell($mw3, 7, 'Menge',       'B', 0, 'R', true);
$pdf->Cell($mw4, 7, 'Einheit',     'B', 1, 'L', true);

$pdf->SetFont('helvetica', '', 10);

$hasMat = false;
$zebra  = false;

$mRows = max(count($mArt), count($mNam), count($mQty), count($mUni));
for ($i = 0; $i < $mRows; $i++) {
    $art = FN_Clean((string)($mArt[$i] ?? ''));
    $nam = FN_Clean((string)($mNam[$i] ?? ''));
    $qty = FN_Clean((string)($mQty[$i] ?? ''));
    $uni = FN_Clean((string)($mUni[$i] ?? ''));

    if ($art === '' && $nam === '' && $qty === '' && $uni === '') { continue; }
    $hasMat = true;

    $fill = $zebra;
    $pdf->SetFillColor($fill ? 250 : 255, $fill ? 251 : 255, $fill ? 253 : 255);

    $lineH = 4.8;

    $pdf->MultiCell($mw1, $lineH, ($art !== '' ? $art : '-'), 'B', 'L', $fill, 0);
    $pdf->MultiCell($mw2, $lineH, ($nam !== '' ? $nam : '-'), 'B', 'L', $fill, 0);
    $pdf->MultiCell($mw3, $lineH, ($qty !== '' ? $qty : '-'), 'B', 'R', $fill, 0);
    $pdf->MultiCell($mw4, $lineH, ($uni !== '' ? $uni : '-'), 'B', 'L', $fill, 1);

    $zebra = !$zebra;
}
if (!$hasMat) {
    $pdf->Cell($mw1 + $mw2 + $mw3 + $mw4, 7, '-', 'B', 1, 'L', false);
}

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2);

FN_DrawSectionHeader($pdf, 'ABNAHME / FREIGABE');

$abnMap = [
    'done' => 'Auftrag beendet, Dienstleistung überprüft & abgeschlossen.',
    'open' => 'Auftrag offen, weitere Dienstleistungen notwendig.',
];
$abnText = $abnMap[$abnahmeStatus] ?? '';
$abnChecked = ($abnahmeStatus !== '' && $abnText !== '');

$cbSize2 = 4.2;
$cbX2 = $x0;
$cbY2 = $pdf->GetY() + 0.6;
FN_DrawCheckbox($pdf, $cbX2, $cbY2, $cbSize2, $abnChecked);

$pdf->SetXY($x0 + $cbSize2 + 2.0, $pdf->GetY());
$pdf->MultiCell($usableW - ($cbSize2 + 2.0), 5, ($abnText !== '' ? $abnText : '-'), 0, 'L', false, 1);

$pdf->Ln(1.2);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->MultiCell($usableW, 4.5, 'Bemerkung / Hinweise:', 0, 'L', false, 1);
$pdf->SetFont('helvetica', '', 10);

$abBemerkung = strip_tags($abBemerkung);
$boxH = 18.0;

$curY = $pdf->GetY();
if (($curY + $boxH + 6) > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
    $pdf->AddPage();
}
$pdf->SetX($x0);
$pdf->SetDrawColor(200, 200, 200);
$pdf->SetLineWidth(0.25);
$pdf->SetCellPadding(1.2);
$pdf->MultiCell($usableW, $boxH, ($abBemerkung !== '' ? $abBemerkung : ' '), 1, 'L', false, 1);
$pdf->SetCellPadding(0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2);

$pdf->Ln(2.0);

$ctxContinuation = [
    'kunde_firma'   => $kundeFirma,
    'kunde_inhaber' => $kundeInhaber,
    'kunde_ap'      => $kundeAP,
    'kunden_nummer' => $kundenNummer,
    'kunde_mail'    => $kundeMail,
    'datum_de'      => FN_FormatDateDE($datum),
    'title'         => $title,
];

// Wenn die Unterschrift wegen Seitenumbruch alleine rutschen würde:
// -> neue Seite + Fortsetzungs-Header + Kurz-Kontext, dann erst Signaturblock.
FN_EnsureSpaceOrNewPage($pdf, 55.0, static function() use ($pdf, $ctxContinuation): void {
    FN_AddContinuationContext($pdf, $ctxContinuation);
});

FN_DrawSectionHeader($pdf, 'KUNDENUNTERSCHRIFT');
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 5, 'Name (Unterzeichner): ' . ($sigMainName !== '' ? $sigMainName : '-'), 0, 'L', false, 1);

$mainBoxW = $usableW;
$mainBoxH = 22.0;
$mainBoxX = $x0;
$mainBoxY = $pdf->GetY();

if ($hasSigMain) {
    FN_PlaceSignature($pdf, $sigMainPng, $mainBoxX, $mainBoxY, $mainBoxW, $mainBoxH);
} else {
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Rect($mainBoxX, $mainBoxY, $mainBoxW, $mainBoxH, 'D');
    $pdf->SetDrawColor(0, 0, 0);
}

$pdfData = $pdf->Output($meta['filename'], 'S');

if (FN_LogEnabled($cfg, 'pdf_render_ok', true)) {
    FN_LogInfo('pdf_render_ok', ['filename' => $meta['filename'], 'bytes' => strlen($pdfData)]);
}

######## VERSAND / OUTPUT ################################################################################################################

$outputMode   = strtolower(trim((string)($cfg['output_mode'] ?? 'both')));     // browser|mail|both
$sendCustomer = (bool)($cfg['send_to_customer'] ?? true);
$sendInternal = (bool)($cfg['send_to_internal'] ?? true);
$internalOnly = (bool)($cfg['internal_only'] ?? false);
if ($internalOnly) { $sendCustomer = false; }

$kundenNummerOut = ($kundenNummer !== '' ? $kundenNummer : '-');
$kundeOut       = ($kundeFirma !== '' ? $kundeFirma : ($kundeInhaber !== '' ? $kundeInhaber : '-'));
$kundeMailOut   = ($kundeMail !== '' ? $kundeMail : '-');

$vars = [
    'kunde'        => $kundeOut,
    'kundennummer' => $kundenNummerOut,
    'ap'           => ($kundeAP !== '' ? $kundeAP : '-'),
    'datum'        => FN_FormatDateDE($datum),
    'mail'         => $kundeMailOut,
    'min'          => (string)$sumMinutes,
    'signer_main'  => ($sigMainName !== '' ? $sigMainName : '-'),
    'inhaber'      => ($kundeInhaber !== '' ? $kundeInhaber : '-'),
    'sig_dasi'     => ($sigDasiName !== '' ? $sigDasiName : '-'),
];

$subjectPrefix = FN_StripHeaderLine(trim((string)($cfg['subject_prefix'] ?? '[Leistungsnachweis]')));
$subjectKunde  = $subjectPrefix . ' ' . FN_StripHeaderLine(($kundeFirma !== '' ? $kundeFirma : 'Bericht Einsatz'));
$subjectTech   = $subjectPrefix . ' INBOX ' . FN_StripHeaderLine(($kundeFirma !== '' ? $kundeFirma : 'Bericht Einsatz'));

$tplKunde = (string)($cfg['mail_body_kunde'] ?? "Guten Tag,\n\nanbei erhalten Sie den Leistungsnachweis als PDF.\n");
$tplTech  = (string)($cfg['mail_body_technik'] ?? "Leistungsnachweis Eingang\nKundenNr: {KUNDENNUMMER}\nKunde: {KUNDE}\nAP: {AP}\nDatum: {DATUM}\nEinsatzmin: {MIN}\n\nPDF anbei.");

$mailSummary = [
    'customer' => ['sent' => 0, 'errors' => []],
    'internal' => ['sent' => 0, 'errors' => []],
];

$doMail = (($outputMode === 'mail' || $outputMode === 'both') && $do === 'doc');

if ($doMail) {

    // Kunde
    if ($sendCustomer) {
        $toList = FN_ParseRecipientList((string)$kundeMail, (string)$kundeMailsMore);
        if (count($toList) > 0) {
            $bodyK = FN_RenderTemplate($tplKunde, $vars);
            foreach ($toList as $to) {
                $ok = FN_SendLnMailWithPdfSmtp($cfg, $to, $subjectKunde, $bodyK, $pdfData, $meta['filename']);
                if ($ok) { $mailSummary['customer']['sent']++; }
                else { $mailSummary['customer']['errors'][] = 'send_failed:' . strtolower($to); }
            }
        } else {
            if (FN_LogEnabled($cfg, 'mail_skipped', true)) {
                FN_LogInfo('customer_mail_skipped_no_recipients', ['kundeMail' => $kundeMail]);
            }
            $mailSummary['customer']['errors'][] = 'no_recipients';
        }
    } else {
        if (FN_LogEnabled($cfg, 'mail_skipped', true)) {
            FN_LogInfo('customer_mail_skipped', ['internal_only' => $internalOnly, 'send_customer' => $sendCustomer]);
        }
        $mailSummary['customer']['errors'][] = 'skipped';
    }

    // Intern
    if ($sendInternal) {
        $toTech = trim((string)($cfg['internal_ln_mail'] ?? ''));
        if ($toTech !== '' && filter_var($toTech, FILTER_VALIDATE_EMAIL)) {
            $bodyT = FN_RenderTemplate($tplTech, $vars);

            $extraHeaders = [];
            if (!empty($cfg['add_tech_headers'])) {
                $extraHeaders = [
                    'X-LN-Kundennr' => $kundenNummer,
                    'X-LN-Kunde'    => $kundeFirma,
                    'X-LN-Signer'   => $sigMainName,
                    'X-LN-AP'       => $kundeAP,
                    'X-LN-Datum'    => FN_PdfDateISO($datum),
                    'X-LN-Min'      => (string)$sumMinutes,
                    'X-LN-Mail'     => $kundeMail,
                    'X-LN-Inhaber'  => $kundeInhaber,
                    'X-LN-SigDasi'  => $sigDasiName,
                ];
            }

            $ok = FN_SendLnMailWithPdfSmtp($cfg, $toTech, $subjectTech, $bodyT, $pdfData, $meta['filename'], $extraHeaders);
            if ($ok) { $mailSummary['internal']['sent']++; }
            else { $mailSummary['internal']['errors'][] = 'send_failed'; }
        } else {
            FN_LogError('internal_mail_missing_recipient', ['internal_ln_mail' => $toTech]);
            $mailSummary['internal']['errors'][] = 'missing_internal_recipient';
        }
    } else {
        $mailSummary['internal']['errors'][] = 'skipped';
    }

    if (FN_LogEnabled($cfg, 'mail_send_ok', true) || FN_LogEnabled($cfg, 'mail_send_fail', true)) {
        FN_LogInfo('mail_summary', $mailSummary);
    }
}

if ($outputMode === 'browser' || $outputMode === 'both') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . FN_StripHeaderLine($meta['filename']) . '"');
    echo $pdfData;
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";
echo "customer_sent=" . (int)$mailSummary['customer']['sent'] . "\n";
echo "internal_sent=" . (int)$mailSummary['internal']['sent'] . "\n";
if (!empty($mailSummary['customer']['errors'])) { echo "customer_errors=" . implode(',', $mailSummary['customer']['errors']) . "\n"; }
if (!empty($mailSummary['internal']['errors'])) { echo "internal_errors=" . implode(',', $mailSummary['internal']['errors']) . "\n"; }
exit;
