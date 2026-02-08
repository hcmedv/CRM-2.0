<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_lib/events/crm_events_write.php
 *
 * Zweck (minimal / Core):
 * - Zentrale Schreibstelle für den Event-Store (JSON)
 * - Upsert per event_id ODER refs[{ns,id}] (Idempotenz)
 * - Rekursiver Patch-Merge
 *   - ASSOC Arrays: rekursiv mergen
 *   - LIST (numerisch/indexed): komplett ersetzen
 * - Lock + atomisches Schreiben (shared-hosting safe)
 * - KEINE Enrichment- oder Connector-Logik
 *
 * Abhängigkeiten:
 * - bootstrap.php (CRM_MOD_CFG, CRM_MOD_PATH)
 *
 * Modul:
 * - events
 *
 * Schnittstelle:
 * - CRM_EventGenerator::upsert(string $eventSource, string $eventType, array $patch): array
 *
 * Hinweis:
 * - meta.ui.title_user === true wird respektiert (display.title wird nicht überschrieben).
 */

final class CRM_EventGenerator
{
    public static function upsert(string $eventSource, string $eventType, array $patch): array
    {
        return CRM_Events_Write::upsert($eventSource, $eventType, $patch);
    }
}

final class CRM_Events_Write
{
    public static function upsert(string $eventSource, string $eventType, array $patch): array
    {
        // Guard: erlaubte Sources/Types aus Settings
        if (!self::isAllowedSource($eventSource)) {
            return self::out(false, 'source_not_allowed', ['event_source' => $eventSource]);
        }
        if (!self::isAllowedType($eventType)) {
            return self::out(false, 'type_not_allowed', ['event_type' => $eventType]);
        }

        $storeFile = self::storeFile('filename_store', 'events.json');

        // Store laden (inkl. Lock)
        [$store, $lock] = self::loadStore($storeFile);

        $isNew = false;
        $idx   = -1;

        try
        {
            $idx = self::findEventIndex($store, $patch);

            if ($idx === -1) {
                $event   = self::buildNewEvent($eventSource, $eventType, $patch);
                $store[] = $event;
                $idx     = count($store) - 1;
                $isNew   = true;
            } else {
                $store[$idx] = self::mergeEvent($store[$idx], $patch);
            }

            $store = self::applyLimits($store);

            self::writeStore($storeFile, $store);
        }
        finally
        {
            self::unlock($lock);
        }

        return self::out(true, null, [
            'written' => true,
            'is_new'  => $isNew,
            'index'   => $idx,
            'event'   => $store[$idx] ?? null,
        ]);
    }

    /* =========================
     * Core Helpers
     * ========================= */

    private static function buildNewEvent(string $source, string $type, array $patch): array
    {
        $now = time();

        $base = [
            'event_id'     => self::uuid(),
            'event_source' => $source,
            'event_type'   => $type,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        // Patch darf event_source/event_type nicht „umdefinieren“
        unset($patch['event_source'], $patch['event_type'], $patch['created_at'], $patch['updated_at']);

        return self::arrayMergeDistinct($base, $patch);
    }

    private static function mergeEvent(array $current, array $patch): array
    {
        // meta.ui.title_user === true → display.title nicht überschreiben
        if (
            isset($current['meta']['ui']['title_user']) &&
            $current['meta']['ui']['title_user'] === true &&
            isset($patch['display']['title'])
        ) {
            unset($patch['display']['title']);
        }

        // Patch darf event_source/event_type nicht „umdefinieren“
        unset($patch['event_source'], $patch['event_type'], $patch['created_at']);

        $merged = self::arrayMergeDistinct($current, $patch);
        $merged['updated_at'] = time();

        return $merged;
    }

    private static function findEventIndex(array $store, array $patch): int
    {
        // a) event_id
        if (isset($patch['event_id']) && is_string($patch['event_id']) && $patch['event_id'] !== '') {
            $id = $patch['event_id'];
            foreach ($store as $i => $evt) {
                if (is_array($evt) && (($evt['event_id'] ?? null) === $id)) {
                    return (int)$i;
                }
            }
        }

        // b) refs[{ns,id}]
        if (isset($patch['refs']) && is_array($patch['refs'])) {
            foreach ($patch['refs'] as $ref) {
                if (!is_array($ref)) { continue; }
                $ns = (string)($ref['ns'] ?? '');
                $id = (string)($ref['id'] ?? '');
                if ($ns === '' || $id === '') { continue; }

                foreach ($store as $i => $evt) {
                    if (!is_array($evt)) { continue; }
                    foreach ((array)($evt['refs'] ?? []) as $eref) {
                        if (!is_array($eref)) { continue; }
                        if (
                            (string)($eref['ns'] ?? '') === $ns &&
                            (string)($eref['id'] ?? '') === $id
                        ) {
                            return (int)$i;
                        }
                    }
                }
            }
        }

        return -1;
    }

    /* =========================
     * Store / IO
     * ========================= */

    private static function storeFile(string $key, string $fallback): string
    {
        $dir = rtrim((string)CRM_MOD_PATH('events', 'data'), '/');

        $files = (array)CRM_MOD_CFG('events', 'files', []);
        $fn    = (string)($files[$key] ?? $fallback);
        $fn    = trim($fn) !== '' ? $fn : $fallback;

        return $dir . '/' . $fn;
    }

    private static function loadStore(string $file): array
    {
        self::ensureDir(dirname($file));

        $lock = @fopen($file . '.lock', 'c');
        if (is_resource($lock)) {
            @flock($lock, LOCK_EX);
        }

        if (!is_file($file)) {
            return [[], $lock];
        }

        $raw = (string)@file_get_contents($file);
        if (trim($raw) === '') {
            return [[], $lock];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $json = [];
        }

        // Erwartung: Store ist ein Array von Events
        // (wenn jemand mal ein Wrapper-Objekt schreibt, ignorieren wir es defensiv)
        if (!self::isList($json)) {
            $json = [];
        }

        return [$json, $lock];
    }

    private static function writeStore(string $file, array $data): void
    {
        self::ensureDir(dirname($file));

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (!is_string($json)) {
            return;
        }

        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        @file_put_contents($tmp, $json . PHP_EOL);
        @chmod($tmp, 0664);

        // Atomic rename (best effort)
        if (!@rename($tmp, $file)) {
            $dst = @fopen($file, 'wb');
            $src = @fopen($tmp, 'rb');
            if (is_resource($dst) && is_resource($src)) {
                @stream_copy_to_stream($src, $dst);
            }
            if (is_resource($src)) { @fclose($src); }
            if (is_resource($dst)) { @fclose($dst); }
            @unlink($tmp);
        }
    }

    private static function unlock($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /* =========================
     * Limits / Allowed
     * ========================= */

    private static function applyLimits(array $store): array
    {
        $limits   = (array)CRM_MOD_CFG('events', 'limits', []);
        $maxItems = (int)($limits['max_items'] ?? 0);

        if ($maxItems > 0 && count($store) > $maxItems) {
            $store = array_slice($store, -$maxItems);
        }

        return $store;
    }

    private static function isAllowedSource(string $src): bool
    {
        $allowed = (array)CRM_MOD_CFG('events', 'allowed', []);
        $list    = (array)($allowed['event_sources'] ?? []);
        return in_array($src, $list, true);
    }

    private static function isAllowedType(string $type): bool
    {
        $allowed = (array)CRM_MOD_CFG('events', 'allowed', []);
        $list    = (array)($allowed['event_types'] ?? []);
        return in_array($type, $list, true);
    }

    /* =========================
     * Utils
     * ========================= */

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private static function isList(array $a): bool
    {
        if ($a === []) { return true; }
        return array_keys($a) === range(0, count($a) - 1);
    }

    private static function arrayMergeDistinct(array $a, array $b): array
    {
        // Wenn $b eine LIST ist, ersetzt sie $a komplett (wichtig für refs/items/tags etc.)
        if (self::isList($b)) {
            return $b;
        }

        foreach ($b as $k => $v) {
            // numeric keys: setzen
            if (is_int($k)) {
                $a[$k] = $v;
                continue;
            }

            if (isset($a[$k]) && is_array($a[$k]) && is_array($v)) {
                // LIST ersetzt, ASSOC merged rekursiv
                $a[$k] = self::arrayMergeDistinct($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    private static function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function out(bool $ok, ?string $error = null, array $extra = []): array
    {
        return array_merge(['ok' => $ok, 'error' => $error], $extra);
    }
}
