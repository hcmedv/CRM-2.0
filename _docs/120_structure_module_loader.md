# 020 – Structure: Runtime & Lifecycle

## Ziel

- Klar definierter Ablauf jeder Anfrage
- Einheitlicher Runtime-Lifecycle für **alle Seiten & Module**
- Keine impliziten Includes
- Vorhersagbares Verhalten (Debug, Auth, Routing, Rendering)

---

## Grundidee

Jede Anfrage durchläuft **immer** dieselbe Kette:

Request
→ Bootstrap
→ Settings
→ Debug/Error
→ Logger
→ Session
→ Auth
→ Routing
→ Modul
→ Rendering
→ Response

Kein Modul darf diese Reihenfolge umgehen.

---

## Einstiegspunkt (Entry Point)

### Öffentlich (CRM)
/public_crm/index.php


Aufgaben:
- Einziger PHP-Entry für CRM
- Kein HTML
- Kein Modul-Code

---

## 1) Bootstrap

Datei:/bootstrap/bootstrap.php



Aufgaben:
- PHP-Runtime initialisieren
- Autoload / Includes vorbereiten
- Grundlegende Konstanten setzen

Beispiele:
- UTF-8
- Zeitzone
- Memory-Limit (optional)

---

## 2) Settings laden

Reihenfolge:

1. `/config/settings_crm.php`
2. `/config/<modul>/settings_<modul>.php` (falls aktiv)

Regeln:
- Nur Konfiguration
- Keine Logik
- Keine Side-Effects

---

## 3) Debug & Error Handling

Zentral:
- Error-Reporting setzen
- Exception-Handler registrieren
- Shutdown-Handler registrieren

Steuerung:
- Globaler `debug`-Schalter
- Modul-Debug nur ergänzend

---

## 4) Logger initialisieren

- Log-Verzeichnisse prüfen
- Logger-Funktionen bereitstellen
- Keine Logs vor diesem Schritt

---

## 5) Session Handling

- `session_start()` genau **einmal**
- Session-Parameter zentral
- Kein Session-Start im Modul

---

## 6) Auth / Security

### Prüfung:
- Ist Login erforderlich?
- Ist Benutzer authentifiziert?
- Rollen / Rechte prüfen

Ort: /auth/auth.


Regeln:
- Kein Modul entscheidet selbst über Auth
- Modul bekommt nur:
  - user_id
  - roles
  - permissions

---

## 7) Routing

Datei: /router/router.php

Aufgaben:
- URL → Modul + Action
- Keine Business-Logik
- Keine Datenverarbeitung

Beispiel:

/events → module=events, action=index
/events/123 → module=events, action=detail
/login → module=login


---

## 8) Modul-Controller

Ort:

Aufgaben:
- Daten laden
- Berechtigungen prüfen (feingranular)
- View bestimmen

Regeln:
- Kein HTML
- Kein echo
- Keine globalen Includes

---

## 9) Rendering

### Page-Layout

- `page_top.php`
- Content
- `page_bottom.php`

Regeln:
- HTML nur hier
- CSS/JS über definierte Slots
- Module liefern nur Inhalte / Daten

---

## 10) Response

- HTML Response
- JSON Response (API)
- Fehlerseite (fallback)

Kein weiterer Code nach Ausgabe.

---

## Sonderfälle

### API-Endpoints

- Eigener Entry: /public_crm/api/index.php


e Lifecycle-Phasen
- Kein HTML-Rendering

---

## Verbote

- Includes im Modul „nach Gefühl“
- Session-Start im Modul
- Auth-Checks im Template
- Direktes Ausgeben von Fehlern

---

## Ergebnis

- Jede Seite ist vorhersagbar
- Debugging reproduzierbar
- Module sind isoliert
- Erweiterung ohne Chaos möglich

Dieses Dokument ist die **verbindliche Runtime-Referenz**.

