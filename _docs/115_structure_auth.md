# 015 – Authentifizierung & Login-Struktur (CRM)

## Ziel
Definition einer **einfachen, sauberen und erweiterbaren Authentifizierungsbasis**
für das interne CRM (Mitarbeiterbereich).

Schwerpunkte:
- klare Trennung Mitarbeiter ↔ Kunden
- Session-basierte Sicherheit
- kein htaccess-Zwang
- später erweiterbar (Rollen, Rechte, Kunden-Login)

---

## Geltungsbereich

Diese Auth-Struktur gilt **ausschließlich für `public_crm`**.

- Mitarbeiter-Login → CRM intern
- Kunden-Zugänge → **separater Bereich**, später definiert
- Keine Vermischung von Benutzerarten

---

## Login-Modul

### Modulname
/login

### Login-Route

- eigene Seite
- kein Overlay
- kein Modal
- bewusst simpel gehalten

---

## Mitarbeiter-Daten

### Speicherort
/data/login/mitarbeiter.json


Liegt bewusst unter `/data/*`:
- nicht versioniert
- runtime-relevant
- analog zu anderen Stammdaten (Kunden etc.)

### Struktur (konzeptionell)
- `id`
- `name`
- `email`
- `username`
- `password_hash`
- `roles[]`
- `is_active`

Regeln:
- Passwort **nur als Hash**
- Klartext-Passwörter verboten
- Datei gilt als **interne Betriebsdatei**

---

## Login-Flow (vereinfacht)

1. Aufruf `/login`
2. Eingabe: Benutzername + Passwort
3. Prüfung:
   - Benutzer existiert
   - Benutzer ist aktiv
   - Passwort-Hash passt
4. Erfolg:
   - Session initialisieren
   - Session-ID regenerieren
   - Userdaten in Session speichern
5. Redirect:
   - Ziel: `/` (Dashboard)

---

## Session-Handling

- Session ist Pflicht für alle internen Seiten
- Session enthält:
  - `user_id`
  - `username`
  - `roles`
  - `csrf_token`
- Session-Timeout konfigurierbar
- Session wird beim Login erneuert

---

## Zugriffsschutz

### Interne Seiten (`public_crm/*`)
- Login **zwingend erforderlich**
- Zugriff ohne Session nicht erlaubt

### Login-Seite
- öffentlich erreichbar
- keine Session-Pflicht

---

## CSRF-Schutz

- CSRF-Token pro Session
- Token wird:
  - serverseitig geprüft
  - clientseitig (Runtime) bereitgestellt

Regeln:
- alle schreibenden Aktionen benötigen CSRF
- kein CSRF im Query-String
- Übergabe per Header oder POST-Body

---

## Konfiguration

### Login-Settings
Pfad: /config/login/settings_login.php


Enthält:
- Session-Timeout
- Cookie-Parameter
- CSRF-Einstellungen
- Pfad zur `mitarbeiter.json`
- optionale Debug-Flags

Keine Benutzer- oder Passwortdaten.

---

## Rollen & Rechte (Ausblick)

Aktueller Stand:
- Rollen vorhanden (`roles[]`)
- Rechte noch nicht ausgewertet

Zukunft:
- rollenbasierte Sichtbarkeit
- modulabhängige Berechtigungen
- Kunden-Login getrennt implementiert

---

## Sicherheit – Leitlinien

- keine htaccess-Abhängigkeit
- keine Credentials im Frontend
- keine sensiblen Daten in URLs
- kein POST-Zustand in Browser-History
- Debug-Ausgaben nur bei aktivem Debug

---

## Ergebnis

- einfache, robuste Login-Basis
- vollständig unter eigener Kontrolle
- klar dokumentiert
- bereit für spätere Erweiterung

Diese Auth-Struktur ist **verbindlich** für das CRM.

