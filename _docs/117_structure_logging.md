# 117 – Structure: Logging

## Ziel

- Einheitliche, verlässliche Log-Ausgaben
- Keine „versteckten“ Fehler mehr
- Saubere Trennung nach Modulen
- Debugging ohne Code-Änderungen möglich
- Logs sind **lesbar**, **filterbar** und **nachvollziehbar**

---

## Grundprinzip

- Zentrales Logging-System
- Modulbezogene Log-Dateien
- Log-Level steuerbar über Settings
- Keine direkten `echo` / `var_dump` im Produktivbetrieb

---

## Log-Verzeichnisse

Basis:
- `/log/`

Struktur:
- `/log/crm/`
- `/log/events/`
- `/log/login/`
- `/log/stammdaten/`
- `/log/service_report/`
- `/log/system/`

Optional:
- `/log/archive/` (rotierte Logs)

---

## Log-Dateinamen

Schema:
- `<modul>.log`
- optional: `<modul>-YYYY-MM-DD.log`

Beispiele:
- `events.log`
- `login.log`
- `system.log`

---

## Log-Level

Standardisierte Level:

- `debug`   → nur Entwicklung
- `info`    → normale Abläufe
- `warning` → ungewöhnlich, aber lauffähig
- `error`   → Fehler mit Abbruch
- `critical`→ Systemfehler

---

## Steuerung über Settings

### Global (`settings_crm.php`)

- globales `debug`
- Standard-Log-Level
- Log-Pfad

### Modul (`settings_<modul>.php`)

- eigenes `debug`
- eigenes Log-Level
- optional eigenes Log-File

**Regel:**
Modul-Settings überschreiben globale Settings.

---

## Log-Inhalt (Format)

Pflichtfelder pro Eintrag:

- Timestamp (ISO 8601)
- Log-Level
- Modul
- Message
- Context (Array / JSON)

Beispiel (logisch):

- Zeit: 2026-02-04T14:32:10Z
- Level: error
- Modul: events
- Message: Event konnte nicht gespeichert werden
- Context:
  - event_id
  - user_id
  - request_id

---

## Request-ID

- Jede Anfrage erhält eine eindeutige Request-ID
- Wird:
  - im Log geführt
  - an Subsysteme weitergereicht
- Erlaubt Nachverfolgung über mehrere Logs hinweg

---

## Fehler-Logging

### PHP-Fehler

- Alle Errors werden abgefangen
- Keine Ausgabe direkt im Browser (außer DEV)
- Jeder Error landet im Log

### Exceptions

- Jede ungefangene Exception wird geloggt
- Stacktrace nur im DEV-Modus vollständig

---

## DEV vs. PROD

### DEV

- Fehler sichtbar im Browser
- Zusätzlich vollständiger Log-Eintrag
- Debug-Logs aktiv

### PROD

- Generische Fehlermeldung im Browser
- Details ausschließlich im Log
- Debug-Logs deaktiviert

---

## Was **nicht** erlaubt ist

- `var_dump()` im Produktivcode
- `die()` ohne Logging
- Logs ohne Modul-Zuordnung
- Logs ohne Kontext bei Fehlern

---

## Verantwortung

- Core:
  - Initialisiert Logger
  - Stellt Logger-Funktion bereit

- Modul:
  - Nutzt Logger
  - Entscheidet über Log-Level
  - Liefert Kontextdaten

---

## Ergebnis

- Vorhersagbares Debugging
- Keine „Geisterfehler“ mehr
- Klare Verantwortung pro Modul
- Logs sind Werkzeug, kein Müllcontainer
