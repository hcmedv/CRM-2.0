<?php
declare(strict_types=1);

/*
  Datei: /public/api/crm/api_crm_events_commit.php
  Zweck:
  - UI-Commit Endpoint (auth/CSRF geschützt)
  - Nimmt Draft-Patch + optional state-change entgegen
  - Validiert Minimalbedingungen (z.B. state, patch-Form)
  - Create ODER Update:
      - Update: event_id gesetzt
      - Create: event_id leer -> Writer erzeugt neue event_id (idempotent via refs)
  - KN-Pflicht bei Statuswechsel: Patch -> fallback bestehendes Event (events.json)
  - Kamera-Create (event_source=camera, event_type=doc):
      - KN Pflicht
      - meta.doc.camera.session + meta.doc.camera.items[] Pflicht
      - refs{camera:session} + timing.* Default
      - display.title/subtitle Default (damit Final-Validation erfüllt ist)
  - Kamera Finalize (Option B):
      - Upload bleibt in CAMERA_TMP_DIR
      - Nach erfolgreichem Writer: Move nach CAMERA_DATA_DIR/<KN>/...
      - Patch Event: meta.doc.camera.items => store.* (keine tmp URLs)
      - OPTIONAL ROBUST: Items nach Writer#1 aus events.json neu laden (falls später Enrichment im Writer passiert)
  - Antwort: { ok:true, event_id:"...", written:true, created:true|false }
*/

require_once __DIR__ . '/../../_inc/bootstrap.php';

define('CRM_EVENTS_WRITE_LIB_MODE', true);
require_once __DIR__ . '/api_crm_events_write.php';

CRM_RequireAuthApi();
CRM_SessionEnsure();

header('Content-Type: application/json; charset=utf-8');

function RESP(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function STR($v): string
{
    return trim((string)($v ?? ''));
}

function IS_ASSOC(array $a): bool
{
    return array_keys($a) !== range(0, count($a) - 1);
}

function GET_PATCH_VAL(array $patch, array $path): string
{
    $ref = $patch;
    foreach ($path as $k) {
        if (!is_array($ref) || !array_key_exists($k, $ref)) { return ''; }
        $ref = $ref[$k];
    }
    return STR($ref);
}

function GET_PATCH_ARR(array $patch, array $path): ?array
{
    $ref = $patch;
    foreach ($path as $k) {
        if (!is_array($ref) || !array_key_exists($k, $ref)) { return null; }
        $ref = $ref[$k];
    }
    return is_array($ref) ? $ref : null;
}

function PATCH_SET(array &$patch, array $path, $value): void
{
    $ref =& $patch;
    $n = count($path);

    for ($i = 0; $i < $n; $i++) {

        $k = $path[$i];

        if ($i === $n - 1) {
            $ref[$k] = $value;
            return;
        }

        if (!isset($ref[$k]) || !is_array($ref[$k])) {
            $ref[$k] = [];
        }

        $ref =& $ref[$k];
    }
}

function PATH_EVENTS_JSON(): string
{
    if (function_exists('CFG_PATH')) {

        $p = (string)CFG_PATH('EVENTS_JSON');
        if ($p !== '' && file_exists($p)) { return $p; }

        $p2 = (string)CFG_PATH('CRM_EVENTS_JSON');
        if ($p2 !== '' && file_exists($p2)) { return $p2; }

        $p3 = (string)CFG_PATH('EVENTS_STORE_JSON');
        if ($p3 !== '' && file_exists($p3)) { return $p3; }
    }

    $root = dirname(__DIR__, 2); // /public
    return $root . '/data/events/events.json';
}

function LOAD_EVENT_BY_ID(string $eventId): ?array
{
    $path = PATH_EVENTS_JSON();
    if ($path === '' || !is_file($path)) { return null; }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') { return null; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { return null; }

    $events = $j['events'] ?? $j['items'] ?? $j;
    if (!is_array($events)) { return null; }

    foreach ($events as $e) {
        if (!is_array($e)) { continue; }
        if (STR($e['event_id'] ?? '') === $eventId) { return $e; }
    }

    return null;
}

// ===== Kamera Finalize (Option B) ============================================

function FN_SafeToken(string $s, int $maxLen = 64): string
{
    $s = trim($s);
    if ($s === '') { return ''; }
    $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
    return substr($s, 0, $maxLen);
}

function FN_EnsureDir(string $dir): bool
{
    if (is_dir($dir)) { return true; }
    @mkdir($dir, 0775, true);
    return is_dir($dir);
}

function FN_IsPathInside(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if ($path === '' || $root === '') { return false; }
    return (strpos($path . '/', $root . '/') === 0);
}

function FN_RmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }

    $it = new DirectoryIterator($dir);
    foreach ($it as $f) {
        if ($f->isDot()) { continue; }
        $p = $f->getPathname();
        if ($f->isDir()) {
            FN_RmTree($p);
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }

    @rmdir($dir);
}

/**
 * Finalisiert Kamera-Session: verschiebt tmp → data und liefert umgeschriebene Items:
 * - Ziel: CAMERA_DATA_DIR/<KN>/(thumb/)...
 * - Dateinamen: KN_<KN>_<YYYY-MM-DD>_<rand>.jpg und *_t.jpg
 * - Items bekommen store{...} und verlieren full/thumb tmp-URLs
 */
function FN_CameraFinalizeAfterWriter(string $kn, string $session, array $items, string $nowIso): array
{
    $kn      = FN_SafeToken($kn, 32);
    $session = FN_SafeToken($session, 80);

    if ($kn === '' || $session === '') {
        return [false, [], 'finalize_bad_kn_or_session'];
    }

    $tmpRoot  = function_exists('CFG_PATH') ? (string)CFG_PATH('CAMERA_TMP_DIR') : '';
    $dataRoot = function_exists('CFG_PATH') ? (string)CFG_PATH('CAMERA_DATA_DIR') : '';

    $tmpRoot  = rtrim(str_replace('\\', '/', $tmpRoot), '/');
    $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');

    if ($tmpRoot === '' || $dataRoot === '') {
        return [false, [], 'finalize_dir_missing'];
    }

    // Upload-Endpunkt kann so liegen:
    // A) tmpRoot/<session>
    // B) tmpRoot/camera/<session>
    $srcDirA = $tmpRoot . '/' . $session;
    $srcDirB = $tmpRoot . '/camera/' . $session;

    if (is_dir($srcDirA)) { $srcDir = $srcDirA; }
    elseif (is_dir($srcDirB)) { $srcDir = $srcDirB; }
    else { return [false, [], 'finalize_tmp_session_not_found']; }

    $dstDir      = $dataRoot . '/' . $kn;
    $dstThumbDir = $dstDir . '/thumb';

    if (!FN_EnsureDir($dstDir) || !FN_EnsureDir($dstThumbDir)) {
        return [false, [], 'finalize_data_dir_create_failed'];
    }

    if (!FN_IsPathInside($srcDir, $tmpRoot) || !FN_IsPathInside($dstDir, $dataRoot)) {
        return [false, [], 'finalize_path_safety_failed'];
    }

    $date = substr($nowIso, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

    $randHex = function (int $bytes = 4): string {
        try { return bin2hex(random_bytes($bytes)); } catch (Throwable $e) { return bin2hex(random_bytes(3)); }
    };

    $rewritten = [];

    foreach ($items as $it) {
        if (!is_array($it)) { continue; }

        $full  = (string)($it['full'] ?? '');
        $thumb = (string)($it['thumb'] ?? '');

        $fullFile  = basename((string)(parse_url($full, PHP_URL_PATH) ?? ''));
        $thumbFile = basename((string)(parse_url($thumb, PHP_URL_PATH) ?? ''));

        if ($fullFile === '' || $thumbFile === '') {
            return [false, [], 'finalize_bad_item_filenames'];
        }

        $srcFull  = $srcDir . '/' . $fullFile;
        $srcThumb = $srcDir . '/thumb/' . $thumbFile;

        if (!is_file($srcFull) || !is_file($srcThumb)) {
            return [false, [], 'finalize_file_missing'];
        }

        $r = $randHex(4); // 8 hex
        $newBase  = 'KN_' . $kn . '_' . $date . '_' . $r;
        $newFull  = $newBase . '.jpg';
        $newThumb = $newBase . '_t.jpg';

        $dstFull  = $dstDir . '/' . $newFull;
        $dstThumb = $dstThumbDir . '/' . $newThumb;

        $ok1 = @rename($srcFull, $dstFull);
        if (!$ok1) {
            $ok1 = @copy($srcFull, $dstFull);
            if ($ok1) { @unlink($srcFull); }
        }

        $ok2 = @rename($srcThumb, $dstThumb);
        if (!$ok2) {
            $ok2 = @copy($srcThumb, $dstThumb);
            if ($ok2) { @unlink($srcThumb); }
        }

        if (!$ok1 || !$ok2) {
            return [false, [], 'finalize_move_failed'];
        }

        $it['store'] = [
            'kind'      => 'camera',
            'kn'        => $kn,
            'session'   => $session,
            'full'      => $newFull,
            'thumb'     => $newThumb,
            'finalized' => true,
            'moved_at'  => $nowIso,
        ];

        unset($it['full'], $it['thumb']);

        $rewritten[] = $it;
    }

    // tmp session komplett entfernen (inkl. meta.jsonl etc.)
    FN_RmTree($srcDir);

    return [true, $rewritten, ''];
}

// -----------------------------------------------------------------------------
// 0) Input (GENAU 1x lesen)
// -----------------------------------------------------------------------------
$raw = (string)file_get_contents('php://input');
$in  = [];
if (trim($raw) !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) { $in = $j; }
}

// -----------------------------------------------------------------------------
// CSRF bevorzugt aus Header, fallback aus JSON
// -----------------------------------------------------------------------------
$csrf = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrf = trim((string)$_SERVER['HTTP_X_CSRF_TOKEN']);
} elseif (isset($_SERVER['HTTP_X_CSRF'])) {
    $csrf = trim((string)$_SERVER['HTTP_X_CSRF']);
}
if ($csrf === '' && isset($in['csrf'])) {
    $csrf = trim((string)$in['csrf']);
}
if ($csrf !== '') {
    $_POST['csrf'] = $csrf;
}
CRM_CsrfRequireForWrite(true);

// -----------------------------------------------------------------------------
// 1) Input Felder
// -----------------------------------------------------------------------------
$eventId = STR($in['event_id'] ?? '');
$state   = STR($in['workflow_state'] ?? ''); // optional
$patch   = $in['patch'] ?? null;            // assoc patch

if (!is_array($patch) || !IS_ASSOC($patch)) {
    RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'patch must be assoc object'], 400);
}

$isCreate = ($eventId === '');

// -----------------------------------------------------------------------------
// 2) Validierung (State)
// -----------------------------------------------------------------------------
$allowedStates = ['open','work','waiting','closed','hidden','archiv'];

if ($state !== '' && !in_array($state, $allowedStates, true)) {
    RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'invalid workflow_state'], 400);
}

// -----------------------------------------------------------------------------
// 3) Create-Branch (Kamera-Dokumentation über Commit)
// -----------------------------------------------------------------------------
$cameraCtx = null;

if ($isCreate) {

    if ($state === '') { $state = 'open'; }

    $src = strtolower(GET_PATCH_VAL($patch, ['event_source']));
    $typ = strtolower(GET_PATCH_VAL($patch, ['event_type']));

    $isCameraDoc = ($src === 'camera' && $typ === 'doc');

    if ($isCameraDoc) {

        $kn = GET_PATCH_VAL($patch, ['display','customer','number']);
        if ($kn === '' || strlen($kn) < 3) {
            RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'customer number required'], 400);
        }

        $session = GET_PATCH_VAL($patch, ['meta','doc','camera','session']);
        if ($session === '') {
            $session = GET_PATCH_VAL($patch, ['meta','camera','session']);
        }
        if ($session === '') {
            RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'meta.doc.camera.session required'], 400);
        }

        $items = GET_PATCH_ARR($patch, ['meta','doc','camera','items']);
        if (!is_array($items) || count($items) < 1) {
            $items = GET_PATCH_ARR($patch, ['meta','camera','items']);
        }
        if (!is_array($items) || count($items) < 1) {
            RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'meta.doc.camera.items[] required'], 400);
        }

        $minTs = 0;
        $maxTs = 0;

        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $t = (int)($it['ts'] ?? $it['created_ts'] ?? 0);
            if ($t <= 0) { continue; }
            if ($minTs === 0 || $t < $minTs) { $minTs = $t; }
            if ($maxTs === 0 || $t > $maxTs) { $maxTs = $t; }
        }

        if ($minTs <= 0) { $minTs = time(); }
        if ($maxTs <= 0) { $maxTs = $minTs; }
        if ($maxTs <= $minTs) { $maxTs = $minTs + 1; }

        $dur = $maxTs - $minTs;
        if ($dur < 1) { $dur = 1; }

        PATCH_SET($patch, ['timing','started_at'], $minTs);
        PATCH_SET($patch, ['timing','ended_at'], $maxTs);
        PATCH_SET($patch, ['timing','duration_sec'], $dur);

        $refs = GET_PATCH_ARR($patch, ['refs']);
        $hasCameraRef = false;

        if (is_array($refs)) {
            foreach ($refs as $r) {
                if (!is_array($r)) { continue; }
                if (STR($r['ns'] ?? '') === 'camera' && STR($r['id'] ?? '') === $session) { $hasCameraRef = true; break; }
            }
        } else {
            $refs = [];
        }

        if ($hasCameraRef === false) {
            $refs[] = ['ns' => 'camera', 'id' => $session];
            PATCH_SET($patch, ['refs'], array_values($refs));
        }

        $title = GET_PATCH_VAL($patch, ['display','title']);
        if ($title === '') {
            PATCH_SET($patch, ['display','title'], 'Dokumentation: #' . $kn);
        }

        $sub = GET_PATCH_VAL($patch, ['display','subtitle']);
        if ($sub === '') {
            PATCH_SET($patch, ['display','subtitle'], 'Kunde #' . $kn . ' · Kamera-Dokumentation');
        }

        $cameraCtx = [
            'kn'      => $kn,
            'session' => $session,
            'items'   => $items,
        ];
    }
}

// -----------------------------------------------------------------------------
// 4) Update-Branch: KN-Pflicht bei Statuswechsel (wie bisher)
// -----------------------------------------------------------------------------
if ($isCreate === false) {

    if ($state !== '' && $state !== 'open' && $state !== 'hidden') {

        $kn = GET_PATCH_VAL($patch, ['display','customer','number']);

        if ($kn === '') {
            $cur = LOAD_EVENT_BY_ID($eventId);
            if (is_array($cur)) {
                $kn = STR($cur['display']['customer']['number'] ?? '');
            }
        }

        if ($kn === '') {
            RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'customer number required before status change'], 400);
        }

        if (strlen($kn) < 3) {
            RESP(['ok' => false, 'error' => 'bad_request', 'message' => 'invalid customer number'], 400);
        }
    }
}

// -----------------------------------------------------------------------------
// 5) Patch anreichern (UI-Origin + title_user setzen)
// -----------------------------------------------------------------------------
$nowIso = date('c');

$patch['meta'] = (isset($patch['meta']) && is_array($patch['meta'])) ? $patch['meta'] : [];
$patch['meta']['write_origin'] = 'ui';

$titlePatch = GET_PATCH_VAL($patch, ['display','title']);
if ($titlePatch !== '') {
    $patch['meta']['ui'] = (isset($patch['meta']['ui']) && is_array($patch['meta']['ui'])) ? $patch['meta']['ui'] : [];
    $patch['meta']['ui']['title_user'] = true;
    $patch['meta']['ui']['title_user_at'] = $nowIso;
}

// -----------------------------------------------------------------------------
// 6) Writer Call (+ Kamera Finalize Option B)
// -----------------------------------------------------------------------------
if (!function_exists('CRM_EVENTS_WRITER_APPLY_PATCH')) {
    RESP(['ok' => false, 'error' => 'server_error', 'message' => 'writer entrypoint missing'], 500);
}

try {

    // 6.1) Erstes Schreiben (Create/Update)
    $r = CRM_EVENTS_WRITER_APPLY_PATCH($eventId, $patch, $state, $nowIso);

    if (!is_array($r) || ($r['ok'] ?? false) !== true) {
        RESP(['ok'=>false,'error'=>'writer_failed','message'=>$r['message'] ?? 'writer_failed'], 500);
    }

    // 6.2) outEventId MUSS hier gesetzt werden
    $outEventId = STR($r['event_id'] ?? $eventId);
    if ($outEventId === '') {
        RESP(['ok'=>false,'error'=>'writer_failed','message'=>'writer did not return event_id'], 500);
    }

    $written = (bool)($r['written'] ?? true);
    $created = (bool)($r['is_new'] ?? $isCreate);

    // 6.3) Kamera-Finalize nur bei Create-Kamera + erfolgreich geschrieben
    if (is_array($cameraCtx) && $written === true) {

        $kn      = STR($cameraCtx['kn'] ?? '');
        $session = STR($cameraCtx['session'] ?? '');
        $items   = is_array($cameraCtx['items'] ?? null) ? $cameraCtx['items'] : [];

        // OPTIONAL ROBUST: Items nach Writer#1 aus Store neu laden (für spätere Writer-Enrichment-Schritte)
        $stored = LOAD_EVENT_BY_ID($outEventId);
        if (is_array($stored)) {
            $storedItems = $stored['meta']['doc']['camera']['items'] ?? null;
            if (!is_array($storedItems) || count($storedItems) < 1) {
                $storedItems = $stored['meta']['camera']['items'] ?? null; // legacy fallback
            }
            if (is_array($storedItems) && count($storedItems) > 0) {
                $items = $storedItems;
            }
        }

        [$okFin, $finalItems, $finErr] = FN_CameraFinalizeAfterWriter($kn, $session, $items, $nowIso);

        if (!$okFin) {
            RESP(['ok'=>false,'error'=>'camera_finalize_failed','message'=>$finErr], 500);
        }

        // 6.4) Event patchen: meta.doc.camera.items überschreiben + finalized marker
        $patch2 = [
            'meta' => [
                'doc' => [
                    'camera' => [
                        'finalized'    => true,
                        'finalized_at' => $nowIso,
                        'items'        => $finalItems,
                    ],
                ],
            ],
        ];

        $r2 = CRM_EVENTS_WRITER_APPLY_PATCH($outEventId, $patch2, '', $nowIso);

        if (!is_array($r2) || ($r2['ok'] ?? false) !== true) {
            RESP(['ok'=>false,'error'=>'camera_finalize_patch_failed','message'=>$r2['message'] ?? 'camera_finalize_patch_failed'], 500);
        }
    }

    RESP([
        'ok'       => true,
        'event_id' => $outEventId,
        'written'  => $written,
        'created'  => $created,
    ]);

} catch (Throwable $e) {

    RESP(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()], 500);
}
