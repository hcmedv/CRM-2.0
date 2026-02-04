 # 114 – Bootstrap & Initialisierung (CRM)

## Ziel
Definition der **verbindlichen Initialisierungsreihenfolge** für alle CRM-Seiten.
Sicherstellen von:
- konsistentem Verhalten
- zuverlässigem Debugging
- einheitlicher Sicherheit (Session/Auth/CSRF)
- reproduzierbarer Laufzeit

---

## Grundprinzipien

- Es gibt **genau einen** Bootstrap-Einstiegspunkt
- Bootstrap wird **auf jeder internen Seite** geladen
- Keine Seite initialisiert eigene Globals
- Reihenfolge ist **fix** und darf nicht verändert werden
- Fehler müssen **sichtbar oder geloggt** sein – niemals „still“

---

## Einstiegspunkt

Pfad: /public_crm/_inc/bootstrap.php


Wird geladen:
- von jeder Seite
- vor jedem Template (`page_top.php`)
- vor jedem Modul-Code

---

## Initialisierungs-Reihenfolge (verbindlich)

### 1) Settings laden
- `config/settings_crm.php`
- `config/defs/*.php`
- modul-spezifische Settings (`config/<modul>/settings_<modul>.php`), **nur wenn Modul aktiv**

Regeln:
- keine Secrets
- keine Logik
- nur Konfiguration

---

### 2) Debug & Error Handling
- globaler Error-Handler
- globaler Exception-Handler

Verhalten:
- `debug = true`
  - Fehler + Stacktrace im Browser sichtbar
- `debug = false`
  - generische Fehlermeldung
  - vollständige Details im Log

Ziel:
- kein „weißer Bildschirm“
- keine manuelle Error-Ausgabe pro Datei nötig

---

### 3) Logger initialisieren
- zentrales Logging-System
- Standard-Logziel: `/log/`

Features:
- Log-Level (global + optional modulweise)
- Channel-Unterstützung (`core`, `<modul>`)
- strukturierte Logeinträge

Regeln:
- Logging ist **immer aktiv**
- Debug-Ausgaben niemals per `echo`

---

### 4) Session starten
- Session-Cookie-Parameter setzen
  - Secure
  - SameSite
- Session starten
- keine Ausgabe vor `session_start()`

Regeln:
- Session ist Grundlage für Auth & CSRF
- Session-ID wird beim Login erneuert

---

### 5) Auth-Layer vorbereiten
- Helper-Funktionen bereitstellen:
  - `require_login()`
  - `current_user()`
- Login-Prüfung erfolgt **pro Seite**
  - z. B. `/login` ist frei
  - interne Seiten erzwingen Login

Regeln:
- kein impliziter Login
- kein Zugriff ohne Session

---

### 6) CSRF vorbereiten
- Token pro Session generieren
- Helper:
  - `csrf_token()`
  - `csrf_verify()`

Regeln:
- alle schreibenden Aktionen benötigen CSRF
- Token wird an Frontend-Runtime übergeben

---

### 7) Runtime-Daten bauen
- kleine JSON-Struktur für Frontend

Enthält:
- `csrf`
- `user` (id, name, roles)
- `debug`
- `routes` / `base_urls`
- ggf. Versionsmarker (Cache-Busting)

Regeln:
- keine Secrets
- keine kompletten Configs
- nur leserelevante Daten

---

### 8) Globale Helper laden
- `helpers.php`

Inhalt:
- generische Hilfsfunktionen
- keine Modul-Logik
- keine Seiteneffekte

Modul-Helper werden **nicht** global geladen.

---

## Verbote

- kein Include von Bootstrap innerhalb von Modulen
- kein doppeltes Initialisieren
- keine Logik in `page_top.php`
- keine Konfiguration im Frontend

---

## Ergebnis

- deterministischer Seitenstart
- reproduzierbares Verhalten
- konsistentes Debugging
- saubere Sicherheitsbasis

Diese Bootstrap-Reihenfolge ist **verbindlich** für alle CRM-Seiten.
