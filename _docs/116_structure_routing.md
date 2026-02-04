# 116 – Structure: Routing

## Ziel

- Saubere, lesbare URLs ohne Dateinamen
- Zentrale Steuerung aller Requests
- Klare Trennung zwischen:
  - Seiten (HTML)
  - Aktionen (POST)
  - Daten (API / HTMX)
- Keine Weitergabe sensibler Daten über URLs

---

## Grundprinzip

- **Single Entry Point (Front Controller)**
- Alle Requests laufen über:
  - `/public_crm/index.php`
  - `/public_service/index.php`

Keine direkte Ausführung von:
- `*.php` über URL
- Moduldateien
- Include-Dateien

---

## URL-Design

### Beispiele

- `/` → Dashboard
- `/login` → Login-Seite
- `/events` → Event-Übersicht
- `/events/123` → Event-Detail (read)
- `/kunden` → Kundenübersicht
- `/kunden/10001` → Kunde anzeigen

**Keine:**
- `.php` in URLs
- Query-Parameter für Navigation
- POST-Daten in URLs

---

## Routing-Arten

### 1) Seiten-Routen (HTML)

- GET
- liefern vollständige Seiten
- Layout + Content

Beispiele:
- `/events`
- `/kunden`
- `/admin`

---

### 2) Aktions-Routen (Form / HTMX)

- POST
- verändern Daten
- liefern:
  - Redirect
  - Partial HTML
  - JSON (nur intern)

Beispiele:
- `/events/update`
- `/login/check`
- `/events/worklog/add`

---

### 3) Daten-Routen (intern)

- GET oder POST
- nur für JS / HTMX
- **nicht direkt verlinkt**

Beispiele:
- `/api/events/read`
- `/api/events/search`

---

## Routing-Auflösung (logisch)

1. Request kommt an
2. Session starten
3. Auth prüfen
4. Route ermitteln
5. Modul bestimmen
6. Controller ausführen
7. Response senden

---

## Modul-Zuordnung

- Jede Route gehört **genau einem Modul**
- Kein Cross-Routing

Beispiele:
- `/events/*` → Modul `events`
- `/kunden/*` → Modul `stammdaten`
- `/login/*` → Modul `login`

---

## Fehlerseiten

### 404 – Not Found
- Route unbekannt
- Modul nicht vorhanden

### 403 – Forbidden
- nicht eingeloggt
- fehlende Rechte

### 500 – Internal Error
- Exception
- PHP-Fehler
- Konfigurationsproblem

**Im DEV-Modus:**
- Fehler sichtbar im Browser

**Im PROD-Modus:**
- generische Fehlerseite
- Details nur im Log

---

## Sicherheitsregeln

- Routing entscheidet **vor** Modul-Logik
- Keine Moduldatei darf direkt aufgerufen werden
- CSRF-Check bei allen POST-Routen
- Login-Routen sind explizit freigegeben

---

## Abgrenzung

Routing:
- entscheidet *wohin*

Controller:
- entscheidet *was passiert*

Views:
- entscheiden *wie es aussieht*

---

## Ergebnis

- Einheitliches URL-Schema
- Keine offenen Angriffsflächen
- Erweiterbar ohne Umbau
- Klar verständlich für neue Module
