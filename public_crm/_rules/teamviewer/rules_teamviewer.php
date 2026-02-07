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
 *
 * Normierung (TeamViewer):
 * - raw.support_session_type bleibt int (RAW unverändert)
 * - meta.teamviewer._norm.support_type wird String (kanonisch):
 *   - support_session_type = 1  => devicename gefüllt ? 'remote' : 'adhoc'
 *   - support_session_type = 2  => 'meeting'
 *   - support_session_type = 3  => 'file'
 *   - support_session_type = 4  => 'vpn'
 *   - support_session_type = 6  => 'web'
 *   - sonst => 'unknown'
 */

function RULES_TEAMVIEWER_BuildPatch(array $tv): array
{
    $devicename = (string)($tv['devicename'] ?? $tv['deviceName'] ?? $tv['device_name'] ?? '');
    $groupname  = (string)($tv['groupname'] ?? $tv['groupName'] ?? $tv['group_name'] ?? $tv['group'] ?? '');
    $notes      = (string)($tv['notes'] ?? $tv['note'] ?? $tv['description'] ?? '');

    // Title: bevorzugt DeviceName, sonst fallback
    $title = $devicename !== '' ? $devicename : 'Fernwartung';

    // Zeitstempel: TeamViewer liefert i.d.R. start_date/end_date (ISO Zulu)
    $startedAt = RULES_TEAMVIEWER_ParseTimestamp($tv['start_date'] ?? $tv['startTime'] ?? $tv['started_at'] ?? 0);
    $endedAt   = RULES_TEAMVIEWER_ParseTimestamp($tv['end_date']   ?? $tv['endTime']   ?? $tv['ended_at']   ?? 0);

    // KN Extraktion (best-effort): "#10032" oder "KN 10032" aus groupname/notes
    $kn = null;
    $hay = trim($groupname . "\n" . $notes);
    if ($hay !== '' && preg_match('/(?:#|kn\s*)(\d{4,6})/i', $hay, $m)) {
        $kn = (string)$m[1];
    }

    // Support-Type Normierung
    $rawType = (int)($tv['support_session_type'] ?? $tv['supportSessionType'] ?? 0);
    $supportType = RULES_TEAMVIEWER_NormalizeSupportType($rawType, $devicename);

    // Tags (minimal + ableitung)
    $tags = ['teamviewer'];
    if ($supportType === 'adhoc') {
        $tags[] = 'adhoc';
    } elseif ($supportType === 'web') {
        $tags[] = 'web';
    }

    // Ref: Session-ID für Idempotenz
    $sid = (string)($tv['id'] ?? $tv['sessionId'] ?? $tv['session_id'] ?? '');
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
            'tags'     => array_values(array_unique($tags)),
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
                ],
            ],
        ],
    ];

    // Nulls entfernen
    $patch['display'] = array_filter($patch['display'], fn($v) => $v !== null);
    $patch['timing']  = array_filter($patch['timing'],  fn($v) => $v !== null);

    return $patch;
}

/*
 * RULES_TEAMVIEWER_ParseTimestamp
 * Akzeptiert int (epoch) oder ISO-String und liefert epoch seconds (UTC).
 */
function RULES_TEAMVIEWER_ParseTimestamp(mixed $v): int
{
    if (is_int($v)) {
        return $v > 0 ? $v : 0;
    }

    if (is_numeric($v)) {
        $n = (int)$v;
        return $n > 0 ? $n : 0;
    }

    $s = trim((string)$v);
    if ($s === '') {
        return 0;
    }

    $ts = strtotime($s);
    return $ts !== false ? (int)$ts : 0;
}

/*
 * RULES_TEAMVIEWER_NormalizeSupportType
 * Mapping gemäß Vorgabe:
 * - 1 => remote|adhoc (devicename gefüllt => remote, sonst adhoc)
 * - 2 => meeting
 * - 3 => file
 * - 4 => vpn
 * - 6 => web
 * - default => unknown
 */
function RULES_TEAMVIEWER_NormalizeSupportType(int $rawType, string $devicename): string
{
    switch ($rawType) {
        case 1:
            return (trim($devicename) !== '') ? 'remote' : 'adhoc';

        case 2:
            return 'meeting';

        case 3:
            return 'file';

        case 4:
            return 'vpn';

        case 6:
            return 'web';

        default:
            return 'unknown';
    }
}
