<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_lib/commit/crm_commit_validate.php
 *
 * Zweck:
 * - Zentrale Validierung/Normalisierung für api_crm_events_commit.php
 * - Commit bleibt "dumm": Auth + Input + Validate + Writer + (optional) PostWrite
 *
 * Rückgabe:
 * [
 *   'ok'          => bool,
 *   'status'      => int,              // HTTP Status (bei ok=false)
 *   'error'       => string,           // Fehlercode
 *   'message'     => string,           // Kurztext
 *   'event_id'    => string,           // ggf. aus Input (Update) oder '' (Create)
 *   'event_source'=> string,
 *   'event_type'  => string,
 *   'patch'       => array,            // normalisiert
 *   'ctx'         => array,            // Modul-Kontext (z.B. camera kn/session)
 *   'post_write'  => callable|null,    // fn(string $eventId, string $eventSource, string $eventType, array $ctx): array
 * ]
 */

if (!function_exists('CRM_CommitValidate')) {

    function CRM_CommitValidate(array $in): array
    {
        $eventId = CRM_Commit_STR($in['event_id'] ?? '');
        $state   = CRM_Commit_STR($in['workflow_state'] ?? '');
        $patch   = $in['patch'] ?? null;

        if (!is_array($patch) || !CRM_Commit_IS_ASSOC($patch)) {
            return CRM_Commit_OUT(false, 400, 'bad_request', 'patch must be assoc object');
        }

        $isCreate = ($eventId === '');

        // workflow.state
        if ($state !== '') {
            CRM_Commit_PATCH_SET($patch, ['workflow', 'state'], $state);
        } elseif ($isCreate) {
            CRM_Commit_PATCH_SET($patch, ['workflow', 'state'], 'open');
        }

        // meta.write_origin + title_user
        $nowIso = date('c');
        $patch['meta'] = (isset($patch['meta']) && is_array($patch['meta'])) ? $patch['meta'] : [];
        $patch['meta']['write_origin'] = 'ui';

        $titlePatch = CRM_Commit_STR(CRM_Commit_ARR_GET($patch, ['display', 'title']));
        if ($titlePatch !== '') {
            $patch['meta']['ui'] = (isset($patch['meta']['ui']) && is_array($patch['meta']['ui'])) ? $patch['meta']['ui'] : [];
            $patch['meta']['ui']['title_user']    = true;
            $patch['meta']['ui']['title_user_at'] = $nowIso;
        }

        // source/type
        $eventSource = '';
        $eventType   = '';
        $ctx         = [];
        $postWrite   = null;

        if ($isCreate) {

            $eventSource = strtolower(CRM_Commit_STR($patch['event_source'] ?? ''));
            $eventType   = strtolower(CRM_Commit_STR($patch['event_type'] ?? ''));

            if ($eventSource === '' || $eventType === '') {
                return CRM_Commit_OUT(false, 400, 'bad_request', 'event_source/event_type required for create');
            }

        } else {

            $cur = CRM_Commit_LoadEventById($eventId);
            if (!is_array($cur)) {
                return CRM_Commit_OUT(false, 404, 'not_found', 'event not found');
            }

            $eventSource = strtolower(CRM_Commit_STR($cur['event_source'] ?? ''));
            $eventType   = strtolower(CRM_Commit_STR($cur['event_type'] ?? ''));

            if ($eventSource === '' || $eventType === '') {
                return CRM_Commit_OUT(false, 500, 'server_error', 'stored event missing source/type');
            }

            // Writer findet Update über event_id im Patch
            $patch['event_id'] = $eventId;
        }

        // Modul-spezifisch: camera/doc
        if ($eventSource === 'camera' && $eventType === 'doc') {

            $kn = CRM_Commit_STR(CRM_Commit_ARR_GET($patch, ['display', 'customer', 'number']));
            if ($kn === '' || strlen($kn) < 3) {
                return CRM_Commit_OUT(false, 400, 'bad_request', 'display.customer.number required');
            }

            $session = CRM_Commit_STR(CRM_Commit_ARR_GET($patch, ['meta', 'doc', 'camera', 'session']));
            if ($session === '') {
                return CRM_Commit_OUT(false, 400, 'bad_request', 'meta.doc.camera.session required');
            }

            $items = CRM_Commit_ARR_GET($patch, ['meta', 'doc', 'camera', 'items']);
            if (!is_array($items) || count($items) < 1) {
                return CRM_Commit_OUT(false, 400, 'bad_request', 'meta.doc.camera.items[] required');
            }

            // timing aus items.ts
            [$minTs, $maxTs] = CRM_Commit_CameraExtractMinMaxTs($items);
            CRM_Commit_PATCH_SET($patch, ['timing', 'started_at'], $minTs);
            CRM_Commit_PATCH_SET($patch, ['timing', 'ended_at'],   $maxTs);
            CRM_Commit_PATCH_SET($patch, ['timing', 'duration_sec'], max(1, $maxTs - $minTs));

            // refs camera/session sicherstellen
            CRM_Commit_CameraEnsureRef($patch, $session);

            // display defaults
            if (CRM_Commit_STR(CRM_Commit_ARR_GET($patch, ['display', 'title'])) === '') {
                CRM_Commit_PATCH_SET($patch, ['display', 'title'], 'Dokumentation: #' . $kn);
            }
            if (CRM_Commit_STR(CRM_Commit_ARR_GET($patch, ['display', 'subtitle'])) === '') {
                CRM_Commit_PATCH_SET($patch, ['display', 'subtitle'], 'Kunde #' . $kn . ' · Kamera-Dokumentation');
            }

            $ctx = [
                'kn'      => $kn,
                'session' => $session,
                'now_iso' => $nowIso,
            ];

            // PostWrite: finalize tmp -> data und Patch2 schreiben
            $postWrite = 'CRM_Commit_CameraFinalizePostWrite';
        }

        return CRM_Commit_OUT(true, 200, '', '', [
            'event_id'     => $eventId,
            'event_source' => $eventSource,
            'event_type'   => $eventType,
            'patch'        => $patch,
            'ctx'          => $ctx,
            'post_write'   => $postWrite,
        ]);
    }

    /* =========================
     * PostWrite: Camera Finalize
     * ========================= */

    function CRM_Commit_CameraFinalizePostWrite(string $eventId, string $eventSource, string $eventType, array $ctx): array
    {
        if ($eventSource !== 'camera' || $eventType !== 'doc') {
            return ['ok' => true];
        }

        $kn      = CRM_Commit_STR($ctx['kn'] ?? '');
        $session = CRM_Commit_STR($ctx['session'] ?? '');
        $nowIso  = CRM_Commit_STR($ctx['now_iso'] ?? date('c'));

        if ($eventId === '' || $kn === '' || $session === '') {
            return ['ok' => false, 'error' => 'camera_ctx_missing'];
        }

        $stored = CRM_Commit_LoadEventById($eventId);
        if (!is_array($stored)) {
            return ['ok' => false, 'error' => 'event_reload_failed'];
        }

        $alreadyFinalized = (bool)($stored['meta']['doc']['camera']['finalized'] ?? false);
        if ($alreadyFinalized === true) {
            return ['ok' => true, 'skipped' => 'already_finalized'];
        }

        $itemsFromStore = $stored['meta']['doc']['camera']['items'] ?? null;
        $items = (is_array($itemsFromStore) && count($itemsFromStore) > 0) ? $itemsFromStore : [];

        if (count($items) < 1) {
            return ['ok' => false, 'error' => 'camera_items_missing'];
        }

        [$okFin, $finalItems, $finErr] = CRM_Commit_CameraFinalizeFiles($kn, $session, $items, $nowIso);
        if (!$okFin) {
            return ['ok' => false, 'error' => 'camera_finalize_failed', 'message' => $finErr];
        }

        // Patch2 (nur rel; thumbs ohne "thumb/")
        $patch2 = [
            'event_id' => $eventId,
            'meta' => [
                'doc' => [
                    'camera' => [
                        'finalized'    => true,
                        'finalized_at' => $nowIso,
                        'items'        => $finalItems,
                        'store'        => ['rel' => $kn],
                    ],
                ],
            ],
        ];

        $r2 = CRM_EventGenerator::upsert($eventSource, $eventType, $patch2);
        if (!is_array($r2) || ($r2['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'camera_finalize_patch_failed', 'ctx' => $r2];
        }

        return ['ok' => true];
    }

    /**
     * Kamera Finalize:
     * - src: CRM_MOD_PATH('camera','tmp') . '/<tmp_dir>/<session>/...'
     * - dst: CRM_MOD_PATH('camera','data') . '/<KN>/...'
     * - items input dürfen tmp-path ODER File-API URLs enthalten (wir ziehen immer den "file"-Param/ basename)
     * - return items mit:
     *   full  => "<file>.jpg"
     *   thumb => "<file>_t.jpg"     (OHNE "thumb/")
     */
    function CRM_Commit_CameraFinalizeFiles(string $kn, string $session, array $items, string $nowIso): array
    {
        $kn      = CRM_Commit_SafeToken($kn, 32);
        $session = CRM_Commit_SafeToken($session, 80);
        if ($kn === '' || $session === '') {
            return [false, [], 'bad_kn_or_session'];
        }

        $baseTmp  = rtrim(str_replace('\\', '/', (string)CRM_MOD_PATH('camera', 'tmp')), '/');
        $baseData = rtrim(str_replace('\\', '/', (string)CRM_MOD_PATH('camera', 'data')), '/');
        if ($baseTmp === '' || $baseData === '') {
            return [false, [], 'base_dirs_missing'];
        }

        $uploadCfg = (array)CRM_MOD_CFG('camera', 'upload', []);
        $tmpSubdir = trim((string)($uploadCfg['tmp_dir'] ?? 'camera'));
        $tmpSubdir = trim($tmpSubdir, '/');

        $srcDirA = $baseTmp . '/' . $session;
        $srcDirB = ($tmpSubdir !== '') ? ($baseTmp . '/' . $tmpSubdir . '/' . $session) : '';

        if (is_dir($srcDirA)) {
            $srcDir = $srcDirA;
        } elseif ($srcDirB !== '' && is_dir($srcDirB)) {
            $srcDir = $srcDirB;
        } else {
            error_log('[CAM FINALIZE] tmp session not found: A=' . $srcDirA . ' B=' . $srcDirB);
            return [false, [], 'tmp_session_not_found'];
        }

        $dstDir = rtrim($baseData . '/' . $kn, '/');
        $dstT   = $dstDir . '/thumb';

        if (!CRM_Commit_EnsureDir($dstDir) || !CRM_Commit_EnsureDir($dstT)) {
            return [false, [], 'data_dir_create_failed'];
        }

        $date = substr($nowIso, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

        $out = [];

        foreach ($items as $it) {
            if (!is_array($it)) { continue; }

            $fullIn  = (string)($it['full'] ?? '');
            $thumbIn = (string)($it['thumb'] ?? '');

            $fullFile  = CRM_Commit_CameraExtractFile($fullIn);
            $thumbFile = CRM_Commit_CameraExtractFile($thumbIn);

            if ($fullFile === '' || $thumbFile === '') {
                return [false, [], 'bad_item_filenames'];
            }

            $srcFull  = $srcDir . '/' . $fullFile;
            $srcThumb = $srcDir . '/thumb/' . $thumbFile;

            if (!is_file($srcFull) || !is_file($srcThumb)) {
                error_log('[CAM FINALIZE] missing src files: full=' . $srcFull . ' thumb=' . $srcThumb);
                return [false, [], 'src_file_missing'];
            }

            $r = CRM_Commit_RandHex(4);
            $base = "KN_{$kn}_{$date}_{$r}";
            $dstFull  = "{$dstDir}/{$base}.jpg";
            $dstThumb = "{$dstT}/{$base}_t.jpg";

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

            if (!$ok1 || !$ok2 || !is_file($dstFull) || !is_file($dstThumb)) {
                error_log('[CAM FINALIZE] move failed: dstFull=' . $dstFull . ' dstThumb=' . $dstThumb);
                return [false, [], 'move_failed'];
            }

            $it['store'] = [
                'kind'      => 'camera',
                'kn'        => $kn,
                'session'   => $session,
                'full'      => basename($dstFull),
                'thumb'     => basename($dstThumb),
                'finalized' => true,
                'moved_at'  => $nowIso,
            ];

            $it['full']  = basename($dstFull);
            $it['thumb'] = basename($dstThumb); // OHNE "thumb/"

            $out[] = $it;
        }

        // tmp aufräumen (best effort)
        CRM_Commit_RmTree($srcDir);

        return [true, $out, ''];
    }

    function CRM_Commit_CameraExtractFile(string $v): string
    {
        $v = trim((string)$v);
        if ($v === '') { return ''; }

        $u = @parse_url($v);
        if (is_array($u) && isset($u['query'])) {
            $q = [];
            @parse_str((string)$u['query'], $q);
            if (is_array($q) && isset($q['file'])) {
                $v = (string)$q['file'];
            } else {
                $v = (string)($u['path'] ?? $v);
            }
        }

        $v = basename(str_replace('\\', '/', $v));
        $v = preg_replace('/[^0-9A-Za-z_\-\.]/', '', $v) ?? '';
        $v = substr($v, 0, 180);

        if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', $v)) { return ''; }
        return $v;
    }

    function CRM_Commit_CameraEnsureRef(array &$patch, string $session): void
    {
        $refs = CRM_Commit_ARR_GET($patch, ['refs']);
        if (!is_array($refs)) { $refs = []; }

        $has = false;
        foreach ($refs as $r) {
            if (!is_array($r)) { continue; }
            if (CRM_Commit_STR($r['ns'] ?? '') === 'camera' && CRM_Commit_STR($r['id'] ?? '') === $session) {
                $has = true;
                break;
            }
        }

        if (!$has) {
            $refs[] = ['ns' => 'camera', 'id' => $session];
            CRM_Commit_PATCH_SET($patch, ['refs'], array_values($refs));
        }
    }

    function CRM_Commit_CameraExtractMinMaxTs(array $items): array
    {
        $minTs = 0;
        $maxTs = 0;

        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $t = (int)($it['ts'] ?? 0);
            if ($t <= 0) { continue; }
            if ($minTs === 0 || $t < $minTs) { $minTs = $t; }
            if ($maxTs === 0 || $t > $maxTs) { $maxTs = $t; }
        }

        if ($minTs <= 0) { $minTs = time(); }
        if ($maxTs <= 0) { $maxTs = $minTs; }
        if ($maxTs <= $minTs) { $maxTs = $minTs + 1; }

        return [$minTs, $maxTs];
    }

    /* =========================
     * Store Load (read-only)
     * ========================= */

    function CRM_Commit_LoadEventsStoreFile(): string
    {
        $dir = rtrim((string)CRM_MOD_PATH('events', 'data'), '/');
        if ($dir === '') { return ''; }

        $files = (array)CRM_MOD_CFG('events', 'files', []);
        $fn = (string)($files['filename_store'] ?? 'events.json');
        $fn = trim($fn) !== '' ? $fn : 'events.json';

        return $dir . '/' . $fn;
    }

    function CRM_Commit_LoadEventById(string $eventId): ?array
    {
        $path = CRM_Commit_LoadEventsStoreFile();
        if ($path === '' || !is_file($path)) { return null; }

        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') { return null; }

        $j = json_decode($raw, true);
        if (!is_array($j)) { return null; }

        foreach ($j as $e) {
            if (!is_array($e)) { continue; }
            if (CRM_Commit_STR($e['event_id'] ?? '') === $eventId) { return $e; }
        }

        return null;
    }

    /* =========================
     * Small helpers (lokal)
     * ========================= */

    function CRM_Commit_STR($v): string
    {
        return trim((string)($v ?? ''));
    }

    function CRM_Commit_IS_ASSOC(array $a): bool
    {
        return array_keys($a) !== range(0, count($a) - 1);
    }

    function CRM_Commit_ARR_GET(array $a, array $path)
    {
        $ref = $a;
        foreach ($path as $k) {
            if (!is_array($ref) || !array_key_exists($k, $ref)) { return null; }
            $ref = $ref[$k];
        }
        return $ref;
    }

    function CRM_Commit_PATCH_SET(array &$patch, array $path, $value): void
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

    function CRM_Commit_SafeToken(string $s, int $maxLen = 64): string
    {
        $s = trim($s);
        if ($s === '') { return ''; }
        $s = preg_replace('/[^0-9A-Za-z_\-]/', '', $s) ?? '';
        return substr($s, 0, $maxLen);
    }

    function CRM_Commit_EnsureDir(string $dir): bool
    {
        if (is_dir($dir)) { return true; }
        @mkdir($dir, 0775, true);
        return is_dir($dir);
    }

    function CRM_Commit_RandHex(int $bytes = 4): string
    {
        try { return bin2hex(random_bytes($bytes)); } catch (Throwable $e) { return bin2hex(random_bytes(3)); }
    }

    function CRM_Commit_RmTree(string $dir): void
    {
        if (!is_dir($dir)) { return; }

        $it = new DirectoryIterator($dir);
        foreach ($it as $f) {
            if ($f->isDot()) { continue; }
            $p = $f->getPathname();
            if ($f->isDir()) {
                CRM_Commit_RmTree($p);
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }

    function CRM_Commit_OUT(bool $ok, int $status, string $error, string $message, array $extra = []): array
    {
        $base = [
            'ok'      => $ok,
            'status'  => $status,
            'error'   => $error,
            'message' => $message,
        ];
        return array_merge($base, $extra);
    }
}
?>