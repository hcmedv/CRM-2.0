# 018 – Structure: Security

## Ziel

- Einheitliche, nachvollziehbare Sicherheitsstruktur
- Kein implizites Verhalten
- Trennung von Authentifizierung, Autorisierung und Schutzmechanismen
- Gleiche Regeln für alle Module

---

## Sicherheits-Ebenen (Reihenfolge)

1. Transport-Sicherheit (HTTPS)
2. Session
3. Authentifizierung (Login)
4. Autorisierung (Rollen/Rechte)
5. Request-Schutz (CSRF)
6. Ausgabe-Schutz (Escaping)

---

## Session-Handling

- PHP-Session zentral initialisiert
- Eine Session pro Benutzer
- Session enthält **keine** fachlichen Daten

Session-Inhalt (minimal):
- `user_id`
- `username`
- `roles[]`
- `login_ts`
- `last_activity_ts`

Regeln:
- Session wird nach Login erneuert (Session-ID-Rotation)
- Inaktive Sessions laufen ab (Timeout)
- Logout zerstört Session vollständig

---

## Authentifizierung (Login)

- Login nur über `/login`
- Zugang nur für CRM-Mitarbeiter
- Benutzer stammen aus:
  - `/data/login/mitarbeiter.json`

Benutzerfelder:
- id
- username
- password_hash
- email
- roles[]
- is_active

Regeln:
- Kein Klartext-Passwort
- Passwort-Hash ausschließlich serverseitig geprüft
- Login-Status wird **nicht** über URL gesteuert

---

## Autorisierung (Rollen)

- Rollen sind einfache Strings
- Beispiele:
  - `admin`
  - `staff`
  - `readonly`

Regeln:
- Jede Seite prüft benötigte Rolle
- Keine impliziten Rechte
- Fehlende Rechte → 403

---

## CSRF-Schutz

- Pflicht für **alle POST-Requests**
- Gilt für:
  - Formulare
  - HTMX-Requests
  - API-Aktionen (intern)

Mechanik:
- CSRF-Token pro Session
- Token wird:
  - im HTML eingebettet
  - oder im Header gesendet

Ungültiger Token:
- Request wird abgebrochen
- Log-Eintrag
- 403 Response

---

## Freigegebene Routen (Whitelist)

Ohne Login erreichbar:
- `/login`
- `/login/check`

Alle anderen Routen:
- Login-Pflicht

---

## Modul-Grenzen

- Module dürfen:
  - Auth-Status abfragen
  - Rollen prüfen
- Module dürfen **nicht**:
  - Sessions manipulieren
  - Login-Logik überschreiben

---

## Fehlerverhalten

- Sicherheitsfehler werden:
  - geloggt
  - nicht detailliert im Browser angezeigt
- Keine Preisgabe interner Informationen

---

## Abgrenzung

Security ist:
- Infrastruktur
- nicht Teil der Business-Logik
- nicht optional

---

## Ergebnis

- Einheitliches Sicherheitsmodell
- Vorhersehbares Verhalten
- Erweiterbar für spätere Anforderungen
- Keine versteckten Sonderfälle
