# Projektstruktur & Zuständigkeiten

## Ziel
Klare Trennung zwischen internem CRM, Kunden-Portal und technischer Infrastruktur.
Nachvollziehbarkeit zwischen Git, FTP und Subdomains.

---

## Verzeichnisstruktur (Root)

- `data/`
  - Persistente Daten (Events, JSON, Metadaten)
  - **Kein Webzugriff**

- `log/`
  - Laufzeit-Logs
  - **Kein Webzugriff**

- `tmp/`
  - Temporäre Dateien, Uploads, Sessions
  - **Kein Webzugriff**

---

## Webroots / Subdomains

### `public_crm/`
- Internes **CRM-System**
- Event-Verwaltung (Erstellen, Bearbeiten, Enrichment)
- Mitarbeiter-UI
- Schreibend
- Geschützt (Auth)

### `public_service/`
- **Kunden-Portal**
- Read-only Zugriff auf CRM-Ergebnisse
- Downloads (z. B. Kamera-Bilder, Serviceberichte)
- Aktionen wie TV-Session starten
- Keine direkte Schreiblogik

---

## APIs
- Technische Endpunkte
- Kein Kunden-UI
- Getrennt von `public_crm` und `public_service`
- Zugriff nur für Systeme / Services

---

## Leitregel
Alles, was **schreibt oder merged**, gehört **nicht** ins Kunden-Portal.
Alles, was **angezeigt oder geladen** wird, basiert auf vorbereiteten CRM-Daten.
