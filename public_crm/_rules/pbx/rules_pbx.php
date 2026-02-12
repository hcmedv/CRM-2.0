<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_rules/pbx/rules_pbx.php
 *
 * Zweck:
 * - PBX (Sipgate) Event -> CRM Event Patch
 * - Nur Source-spezifisch (PBX). Anreicherung (Customer/PhoneMap/...) macht rules_enrich.php.
 *
 * Export:
 * - RULES_PBX_BuildPatch(array $pbx): array
 *
 * Erwartetes Input-Format (normalisiert aus api_crm_pbx_sipgate.php):
 * - provider, call_id, from, to, direction, state, received_at, timing{started_at, ended_at, duration_sec}
 */

function RULES_PBX_BuildPatch(array $pbx): array
{
    $provider   = RULES_PBX_Str($pbx['provider'] ?? 'sipgate');
    $callId     = RULES_PBX_Str($pbx['call_id'] ?? ($pbx['callId'] ?? ($pbx['origCallId'] ?? '')));
    $from       = RULES_PBX_Str($pbx['from'] ?? '');
    $to         = RULES_PBX_Str($pbx['to'] ?? '');
    $direction  = RULES_PBX_Lower($pbx['direction'] ?? '');
    $state      = RULES_PBX_Lower($pbx['state'] ?? ($pbx['event'] ?? ''));
    $receivedAt = RULES_PBX_Str($pbx['received_at'] ?? '');

    $timing = (isset($pbx['timing']) && is_array($pbx['timing'])) ? $pbx['timing'] : [];

    // 1) Wenn echte Zeiten vorhanden sind -> nutzen
    $startedAt = (int)($timing['started_at'] ?? 0);
    $endedAt   = (int)($timing['ended_at'] ?? 0);

    if ($startedAt <= 0) { $startedAt = RULES_PBX_IsoToTs($pbx['start_date'] ?? ($pbx['startDate'] ?? '')); }
    if ($endedAt   <= 0) { $endedAt   = RULES_PBX_IsoToTs($pbx['end_date']   ?? ($pbx['endDate']   ?? '')); }

    // 2) Fallback rein über received_at, aber STATE-basiert:
    //    - newcall/ringing -> started_at setzen
    //    - hangup          -> ended_at setzen
    //    - answer          -> nichts setzen (damit started_at nicht überschrieben wird)
    $receivedAtTs = RULES_PBX_IsoToTs($receivedAt);

    if ($startedAt <= 0 && in_array($state, ['newcall', 'ringing'], true)) {
        $startedAt = ($receivedAtTs > 0) ? $receivedAtTs : 0;
    }

    if ($endedAt <= 0 && in_array($state, ['hangup'], true)) {
        $endedAt = ($receivedAtTs > 0) ? $receivedAtTs : 0;
    }

    // duration nur, wenn beide Werte da und plausibel
    $duration = null;
    if ($startedAt > 0 && $endedAt > 0 && $endedAt >= $startedAt) {
        $duration = $endedAt - $startedAt;
        if ($duration <= 0) { $duration = 1; }
    }

    // Display
    $dirLabel = ($direction === 'in') ? 'In' : (($direction === 'out') ? 'Out' : 'Call');
    $peer     = ($direction === 'in') ? $from : (($direction === 'out') ? $to : ($from !== '' ? $from : $to));
    $peer     = ($peer !== '') ? $peer : 'unbekannt';

    $title = 'PBX: ' . $dirLabel . ' ' . $peer;

    $subtitleParts = [];
    if ($provider !== '') { $subtitleParts[] = strtoupper($provider); }
    if ($state !== '')    { $subtitleParts[] = $state; }
    $subtitle = implode(' · ', $subtitleParts);

    $tags = ['pbx'];
    if ($provider !== '')  { $tags[] = $provider; }
    if ($direction !== '') { $tags[] = $direction; }
    if ($state !== '')     { $tags[] = $state; }
    $tags = array_values(array_unique(array_filter($tags)));

    // Refs (Idempotenz)
    $refId = $callId !== ''
        ? $callId
        : sha1($provider . '|' . $from . '|' . $to . '|' . ($startedAt > 0 ? $startedAt : ($receivedAtTs > 0 ? $receivedAtTs : time())));

    $refs = [
        [
            'ns' => 'pbx',
            'id' => $refId,
        ],
    ];

    // Display phone (für rules_enrich phone-map)
    $displayPhone = '';
    if ($direction === 'in') {
        $displayPhone = $from;
    } elseif ($direction === 'out') {
        $displayPhone = $to;
    } else {
        $displayPhone = ($from !== '' ? $from : $to);
    }

    $patch = [
        'event_source' => 'pbx',
        'event_type'   => 'call',

        // 🔴 PFLICHT (v2): fachlicher Zustand ist NICHT trigger-abhängig
        'workflow' => [
            'state' => 'open',
        ],

        'display' => [
            'title'    => $title,
            'subtitle' => $subtitle,
            'tags'     => $tags,
            'phone'    => ($displayPhone !== '' ? $displayPhone : null),
        ],

        // WICHTIG: nur setzen, wenn vorhanden (damit Upsert nicht "zurücksetzt")
        'timing' => [
            'started_at'   => ($startedAt > 0 ? $startedAt : null),
            'ended_at'     => ($endedAt > 0 ? $endedAt : null),
            'duration_sec' => ($duration !== null ? $duration : null),
        ],

        'refs' => $refs,

        'meta' => [
            'pbx' => [
                'provider'    => ($provider !== '' ? $provider : 'sipgate'),
                'call_id'     => $refId,
                'state'       => $state,
                'received_at' => ($receivedAt !== '' ? $receivedAt : date('c')),
                'raw'         => $pbx,
            ],
        ],
    ];

    // Nulls entfernen (writer/validator bekommt sauberes Patch)
    if (isset($patch['display']) && is_array($patch['display'])) {
        $patch['display'] = array_filter($patch['display'], fn($v) => $v !== null);
    }
    if (isset($patch['timing']) && is_array($patch['timing'])) {
        $patch['timing'] = array_filter($patch['timing'], fn($v) => $v !== null);
    }

    return $patch;
}



/* ===================================================================================================================== */

function RULES_PBX_Str($v): string
{
    return trim((string)($v ?? ''));
}

function RULES_PBX_Lower($v): string
{
    return strtolower(trim((string)($v ?? '')));
}

function RULES_PBX_IsoToTs($iso): int
{
    $s = trim((string)($iso ?? ''));
    if ($s === '') { return 0; }
    $ts = strtotime($s);
    return ($ts === false) ? 0 : (int)$ts;
}
