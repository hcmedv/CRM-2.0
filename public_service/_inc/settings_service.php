<?php
declare(strict_types=1);

/*
 * Global SERVICE
 * Datei: /public_service/_inc/settings_service.php
 *
 * Zweck:
 * - Zentrale Service-Settings (Labels, Polling, Office-Hours)
 * - Zugriff auf CRM-Settings / DEFS (lesend)
 */

$PUBLIC_SERVICE = dirname(__DIR__);          // /public_service
$BASE           = dirname($PUBLIC_SERVICE);  // Projekt-Root

return [

    // -------------------------------------------------
    // Zugriff auf globale CRM Konfiguration (lesend)
    // -------------------------------------------------
    'getSettings' => function() use ($BASE) : array {
        static $settings = null;
        if ($settings === null) {
            $file = $BASE . '/config/settings_crm.php';
            $settings = is_file($file) ? (array)require $file : [];
        }
        return (array)$settings;
    },

    // -------------------------------------------------
    // Zugriff auf DEFS (lesend)
    // -------------------------------------------------
    'getDefs' => function() use ($BASE) : array {
        static $defs = null;
        if ($defs === null) {
            $file = $BASE . '/config/defs/defs_events.php';
            $defs = is_file($file) ? (array)require $file : [];
        }
        return (array)$defs;
    },

    // -------------------------------------------------
    // SERVICE Status-Konfiguration
    // -------------------------------------------------
    'status' => [

        // Polling Intervall (ms)
        'poll_ms' => 10000,

        // Office-Hours: außerhalb => state='off' erzwingen
        'office_hours' => [
            'enabled' => true,
            'days'    => [1,2,3,4,5],   // Mo-Fr (JS: 1..7)
            'open'    => '08:00',
            'close'   => '17:00',
        ],

        // Sonderregel:
        // - innerhalb Office-Hours soll CRM manual_state="off" NICHT als "Geschlossen" erscheinen,
        //   sondern als "Unterwegs" (aka "nicht im Büro / nicht verfügbar, aber nicht 'geschlossen'")
        'office_hours_off_maps_to' => 'away', // 'away' | 'busy' | 'online' | 'off'

        // Chips (Anzeige)
        'chips' => [
            'online' => ['label' => 'Geöffnet'],
            'busy'   => ['label' => 'Beschäftigt'],
            'away'   => ['label' => 'Abwesend'],
            'off'    => ['label' => 'Geschlossen'],
        ],

        // Texte pro effective_state
        'texts' => [
            'online' => ['title' => 'Online',          'text' => 'Fernwartung aktuell möglich.'],
            'busy'   => ['title' => 'Beschäftigt',     'text' => 'Eingeschränkt verfügbar.'],
            'away'   => ['title' => 'Abwesend',       'text' => 'Antwort ggf. verzögert.'],
            'off'    => ['title' => 'Geschlossen', 'text' => 'Bitte Kontaktformular nutzen.'],
        ],
    ],
];
