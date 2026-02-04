# 119 – Structure: Data Layout

## Ziel

- Zentrale, verlässliche Log- und Debug-Struktur
- Kein „ad-hoc echo / var_dump“
- Klare Trennung zwischen:
  - Entwickler-Debug
  - Betriebs-Logs
  - Sicherheitsrelevanten Logs
- Einheitlich für alle Module

---

## Grundprinzipien

- **Alles Wichtige wird geloggt**
- **Nichts Wichtiges wird nur im Browser ausgegeben**
- Debug-Ausgaben sind **schaltbar**
- Logs sind **textbasiert, lesbar, rotierbar**

---

## Log-Ebenen

Verwendete Level (angelehnt an PSR-3):

- `debug`   – Entwicklerdetails
- `info`    – normale Abläufe
- `notice`  – auffällige, aber gültige Zustände
- `warning` – unerwartet, aber weiter lauffähig
- `error`   – Fehler, Aktion fehlgeschlagen
- `critical`– sicherheits- oder systemkritisch

---

## Zentrale Log-Struktur

Basisverzeichnis: /log/

Unterteilung:

/log/
crm.log
auth.log
security.log
events.log
service_report.log
cron.log
error.log

Regeln:
- **Ein Logfile pro Modul**
- Zentrale Fehler zusätzlich in `error.log`
- Kein Wildwuchs neuer Logdateien

---

## Globale Logger-Funktion

- Eine zentrale Logger-Implementierung
- Zugriff über Helper-Funktion, z. B.:

```php
log_debug('events', 'Liste geladen', ['count' => 12]);
log_error('auth', 'Login fehlgeschlagen', ['user' => $username]);

Regeln:

Kein direkter file_put_contents im Modul

Log-Funktion entscheidet:

Datei

Format

Zeitstempel

Kontext
[2026-02-04 14:12:33] [events] [info] Event geladen id=01KG…
[2026-02-04 14:12:33] [auth] [warning] Login failed {"user":"xxx","ip":"1.2.3.4"}


Debug-Ausgabe (Browser)
Globaler Debug-Schalter

In settings_crm.php:

debug: true|false

Wirkung:

false:

Keine PHP-Fehler im Browser

Nur generische Fehlermeldungen

true:

PHP-Errors sichtbar

Debug-Overlays möglich

Debug-Ausgabe (Browser)
Globaler Debug-Schalter

In settings_crm.php:

debug: true|false

Wirkung:

false:

Keine PHP-Fehler im Browser

Nur generische Fehlermeldungen

true:

PHP-Errors sichtbar

Debug-Overlays möglich

Modul-Debug

Jedes Modul kann zusätzlich einen Debug-Schalter besitzen:

Beispiel:

/config/events/settings_events.php

'debug' => true


Regeln:

Modul-Debug erweitert nur Logging

Globales Debug bleibt Master-Schalter

PHP Error Handling

Zentral initialisiert (früh im Bootstrap):

set_error_handler

set_exception_handler

register_shutdown_function

Verhalten:

Fehler → Logfile

Fatal Error → error.log

Browser:

Debug an → Details

Debug aus → generische Seite

Was nicht erlaubt ist

echo, print_r, var_dump im Produktivcode

Debug-Ausgaben ohne Debug-Flag

Fehler „schlucken“

Security & Logging

Login-Fehler → auth.log

CSRF-Verstöße → security.log

Rechteverletzungen → security.log

Sensible Daten:

nie im Klartext loggen

keine Passwörter

keine Tokens

Ergebnis

Nachvollziehbare Abläufe

Schnelle Fehleranalyse

Saubere Trennung von Debug & Betrieb

Grundlage für spätere Monitoring-Tools
