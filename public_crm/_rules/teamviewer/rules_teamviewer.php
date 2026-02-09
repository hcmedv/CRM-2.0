<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_rules/teamviewer/rules_teamviewer.php
 *
 * Zweck:
 * - TeamViewer RAW Session -> CRM Event Patch
 *
 * Export:
 * - RULES_TEAMVIEWER_BuildPatch(array $tv): array
 */

function RULES_TEAMVIEWER_BuildPatch(array $tv): array
{
    $devicename = (string)($tv['devicename'] ?? $tv['deviceName'] ?? '');
    $group      = (string)($tv['groupname'] ?? $tv['groupName'] ?? $tv['group'] ?? '');
    $notesRaw   = (string)($tv['notes'] ?? '');

    // Titel
    $title = $devicename;
    if ($title === '') {
        $title = (string)($tv['subject'] ?? $tv['description'] ?? 'Fernwartung');
    }
    if ($title === '') {
        $title = 'Fernwartung';
    }

    // Zeiten (TeamViewer liefert meist ISO-Zulu)
    $startedAt = 0;
    $endedAt   = 0;

    $startIso = (string)($tv['start_date'] ?? $tv['startDate'] ?? '');
    $endIso   = (string)($tv['end_date'] ?? $tv['endDate'] ?? '');

    if ($startIso !== '') {
        $ts = strtotime($startIso);
        if ($ts !== false) { $startedAt = (int)$ts; }
    }
    if ($endIso !== '') {
        $ts = strtotime($endIso);
        if ($ts !== false) { $endedAt = (int)$ts; }
    }

    // KN Extraktion (best-effort): "#10032" oder "KN 10032"
    $kn = null;
    $hay = $group . "\n" . $notesRaw . "\n" . (string)($tv['description'] ?? '');
    if (preg_match('/(?:#|kn\s*)(\d{4,6})/i', $hay, $m)) {
        $kn = $m[1];
    }

    // -------------------------------------------------
    // Tags & Notes Normalisierung
    // Regeln:
    // - Tag-Zeile nur wenn erste Zeile mindestens ein '#' enthält
    // - Wenn keine Tag-Zeile: komplette Notes bleiben Notes
    // - Wenn Tag-Zeile: Notes sind nur Zeile 2+
    // -------------------------------------------------

    $notesNorm = str_replace(["\r\n", "\r"], "\n", (string)$notesRaw);

    $firstLine = $notesNorm;
    $restLines = '';

    $nlPos = strpos($notesNorm, "\n");
    if ($nlPos !== false) {
        $firstLine = substr($notesNorm, 0, $nlPos);
        $restLines = substr($notesNorm, $nlPos + 1);
    }

    $hasTagLine = (strpos($firstLine, '#') !== false);

    // ---- TAGS (nur wenn Tag-Zeile) ----
    $tagsNorm = [];
    if ($hasTagLine) {
        if (preg_match_all('/#\s*([^#]+)/', $firstLine, $mm)) {
            foreach ($mm[1] as $tag) {
                $tag = trim((string)$tag);
                if ($tag === '') { continue; }

                $tag = preg_replace('/\s+/', ' ', $tag);
                $tag = mb_strtolower($tag);

                // KN / reine Nummern-Hashtags nicht als Tag übernehmen
                if (preg_match('/^\d{4,6}$/', $tag)) { continue; }

                $tagsNorm[] = $tag;
            }
        }
        $tagsNorm = array_values(array_unique($tagsNorm));
    }

    // ---- NOTES Quelle abhängig von Tag-Zeile ----
    $notesSource = $hasTagLine ? (string)$restLines : (string)$notesNorm;
    $notesClean  = trim($notesSource);

    if ($notesClean !== '') {
        $notesClean = str_replace(["\r\n", "\r"], "\n", $notesClean);
        $notesClean = preg_replace("/[ \t]+/", " ", $notesClean);
        $notesClean = preg_replace("/\n{2,}/", "\n", $notesClean);
        $notesClean = trim($notesClean);
    }

    // Support-Type Normalisierung:
    $supportSessionType = (int)($tv['support_session_type'] ?? $tv['supportSessionType'] ?? 0);

    $supportType = 'unknown';
    switch ($supportSessionType) {
        case 1:
            $supportType = ($devicename !== '') ? 'remote' : 'adhoc';
            break;
        case 2:
            $supportType = 'meeting';
            break;
        case 3:
            $supportType = 'file';
            break;
        case 4:
            $supportType = 'vpn';
            break;
        case 6:
            $supportType = 'web';
            break;
        default:
            $supportType = 'unknown';
            break;
    }

    // Display-Tags (UI): immer teamviewer
    $displayTags = ['teamviewer'];
    $displayTags = array_values(array_unique($displayTags));

    // Geräte/Session-ID als Ref (Idempotenz)
    $sid = (string)($tv['id'] ?? $tv['sessionId'] ?? '');
    $refs = [];
    if ($sid !== '') {
        $refs[] = ['ns' => 'teamviewer', 'id' => $sid];
    }

    $patch = [
        'event_source' => 'teamviewer',
        'event_type'   => 'remote',
        'display' => [
            'title'    => $title,
            'subtitle' => '',
            'tags'     => $displayTags,
            'customer' => $kn ? ['number' => $kn] : null,
        ],
        'timing' => [
            'started_at'   => $startedAt > 0 ? $startedAt : null,
            'ended_at'     => $endedAt > 0 ? $endedAt : null,
            'duration_sec' => ($startedAt > 0 && $endedAt > 0 && $endedAt >= $startedAt) ? ($endedAt - $startedAt) : null,
        ],
        'refs' => $refs,
        'meta' => [
            'teamviewer' => [
                'raw' => $tv,
                '_norm' => [
                    'kn'           => $kn,
                    'devicename'   => $devicename,
                    'support_type' => $supportType,
                    'tags'         => $tagsNorm,
                    'notes'        => $notesClean,
                ],
            ],
        ],
    ];

    // Nulls entschärfen
    $patch['display'] = array_filter($patch['display'], fn($v) => $v !== null);
    $patch['timing']  = array_filter($patch['timing'], fn($v) => $v !== null);

    // _norm: leere Strings/Nulls entfernen (tags als Array behalten, aber leer entfernen)
    if (isset($patch['meta']['teamviewer']['_norm']) && is_array($patch['meta']['teamviewer']['_norm'])) {
        $n = $patch['meta']['teamviewer']['_norm'];

        foreach (['kn','devicename','support_type','notes'] as $k) {
            if (!array_key_exists($k, $n)) { continue; }
            if ($n[$k] === null) { unset($n[$k]); continue; }
            if (is_string($n[$k]) && trim($n[$k]) === '') { unset($n[$k]); continue; }
        }

        if (isset($n['tags']) && is_array($n['tags']) && count($n['tags']) === 0) {
            unset($n['tags']);
        }

        $patch['meta']['teamviewer']['_norm'] = $n;
    }

    return $patch;
}


