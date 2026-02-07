# 310_modules_integration.md

## Modul-Integration (Settings & Secrets)

Dieses Dokument beschreibt die **technische Einbindung von Modulen** im CRM.
Der Fokus liegt auf:

- sauberer Modul-Isolation
- fehlertolerantem Laden (Best-Effort)
- klarer Trennung von **Settings** und **Secrets**
- Debug-Steuerung pro Modul

---

## Grundprinzip

Module werden **zentral über die Hauptkonfiguration aktiviert**:

```php
// config/settings_crm.php
return [
    'modules' => [
        'pbx'            => false,
        'teamviewer'     => false,
        'camera'         => false,
        'm365'           => false,
        'service_report' => false,
        'cti'            => true,
        'cron'           => false,
    ],
];

Nur Module mit true werden vom Bootstrap berücksichtigt.

Verzeichnisstruktur eines Moduls

Jedes Modul besitzt einen eigenen Konfigurationsordner unterhalb von /config:

/config/<modul>/
├─ settings_<modul>.php   // Pflicht (Konfiguration)
└─ secrets_<modul>.php    // Pflicht (Zugangsdaten)


Beispiel (CTI):

/config/cti/
├─ settings_cti.php
└─ secrets_cti.php

settings_<modul>.php

enthält ausschließlich Konfiguration

wird versioniert

darf keine Zugangsdaten enthalten

Keys sind lowercase + snake_case

Beispiel:

<?php
declare(strict_types=1);

return [
    'cti' => [
        'sipgate' => [
            'debug' => true,
            'enabled' => true,
            'api_base' => 'https://api.sipgate.com/v2',
            'secret_key' => 'sipgate_cti',
            'allowed_devices' => ['e0', 'e3', 'e5'],
            'default_device'  => 'e0',
            'use_sipgate_default_device' => true,
        ],
    ],
];


secrets_<modul>.php

enthält nur Secrets

wird nicht versioniert

wird pro Modul isoliert geladen

Keys sind lowercase + snake_case

Beispiel:

<?php
declare(strict_types=1);

return [
    'sipgate_cti' => [
        'token_id'     => '…',
        'token_secret' => '…',
    ],
];



Laden der Module (Bootstrap)

Der Bootstrap lädt automatisch für jedes aktivierte Modul:

/config/<modul>/settings_<modul>.php

/config/<modul>/secrets_<modul>.php

Eigenschaften:

Best-Effort: fehlende Dateien erzeugen nur Log-Einträge

kein Fatal Error

CRM bleibt lauffähig

Settings und Secrets werden getrennt gehalten

Zugriff im Code
Settings
$cti = CRM_CFG('cti', []);

$sec = CRM_SECRET('sipgate_cti');
$tokenId = $sec['token_id'] ?? '';


Debug pro Modul

Debug wird pro Modul über die Settings gesteuert:

'debug' => true


Beispielhafte Verwendung im Code:

if ($debug) {
    log_cti('dial_request_start', [...]);
}


Logs landen modulbezogen unter:

/log/<modul>_YYYY-MM-DD.log

Fehlertoleranz / Sicherheit

Fehlende Moduleinstellungen stoppen nicht das CRM

Defekte Secrets betreffen nur das jeweilige Modul

Globale Secrets-Dateien werden vermieden

Module können einzeln deaktiviert werden

Ziel dieser Architektur

Saubere Modul-Isolation

Sichere Entwicklung neuer Module

Kein Seiteneffekt auf produktive Funktionen

Klare Trennung von Konfiguration und Zugangsdaten

Wartbare, skalierbare Struktur


