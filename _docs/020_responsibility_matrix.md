# 020_responsibility_matrix.md
# Verantwortlichkeiten im CRM V2

------------------------------------------------------------
# Trigger Adapter (rules_<module>.php)

Darf:
- timing setzen
- refs setzen
- display vorbereiten
- meta.<source> füllen

Darf NICHT:
- workflow.state setzen
- Event schließen
- Priorität setzen
- Archivieren

------------------------------------------------------------
# Enrich (rules_enrich.php)

Darf:
- display.customer anreichern
- zusätzliche meta-Daten ergänzen

Darf NICHT:
- workflow.state verändern

------------------------------------------------------------
# Writer (crm_events_write.php)

Darf:
- Neues Event erzeugen
- workflow.state Default setzen (open)
- Merge durchführen
- updated_at setzen

Darf NICHT:
- Triggerdaten interpretieren
- state aus timing ableiten

------------------------------------------------------------
# Reader (crm_events_read.php)

Darf:
- events.json lesen
- Filtern
- Suchen

Darf NICHT:
- Schreiben
- Patchen

------------------------------------------------------------
# API Layer (api_*)

Darf:
- Reader aufrufen
- Writer aufrufen

Darf NICHT:
- events.json direkt lesen
- Dateipfade kennen

------------------------------------------------------------
# UI / Benutzer

Darf:
- workflow.state ändern
- worklog hinzufügen
- Prioritäten setzen

------------------------------------------------------------
# Speicherstruktur

Events:
_crm_data/events.json

Kundendaten:
_kunden/<KN>/<modul>/<jahr>/<typ>/

Strikte Trennung.
