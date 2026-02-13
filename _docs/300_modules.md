# 300_modules.md
# CRM V2 – MODULARCHITEKTUR

Status: Verbindliche Moduldefinition  
Zweck: Einheitliche Integration neuer Trigger / Systeme

------------------------------------------------------------
------------------------------------------------------------

# 1. WAS IST EIN MODUL?

Ein Modul ist ein Adapter zwischen:

Externer Quelle (Trigger)
und
CRM Event-System

Beispiele:

- pbx
- teamviewer
- m365
- camera
- user (manuelle Erstellung)

Ein Modul erzeugt Patches.
Es erzeugt niemals direkt Events.

------------------------------------------------------------
------------------------------------------------------------

# 2. MODUL-BESTANDTEILE

Ein Modul besteht aus:

1) settings_<modul>.php
2) secrets_<modul>.php (optional)
3) rules_<modul>.php
4) optional api_<modul>_*.php
5) optional raw_store (nur Debug)

------------------------------------------------------------
------------------------------------------------------------

# 3. MODULVERANTWORTUNG

Ein Modul darf:

- Triggerdaten entgegennehmen
- Patch erzeugen
- timing setzen
- refs setzen
- display vorbereiten
- meta.<modul> befüllen

Ein Modul darf NICHT:

- workflow.state setzen
- Priorität definieren
- Event schließen
- Event archivieren
- events.json direkt schreiben
- events.json direkt lesen

------------------------------------------------------------
------------------------------------------------------------

# 4. PATCH-DEFINITION

Ein Modul erzeugt ausschließlich ein PATCH.

Beispiel:

{
  "event_source": "teamviewer",
  "event_type": "remote",
  "timing": {
    "started_at": 1736000000,
    "ended_at": 1736000300,
    "duration_sec": 300
  },
  "refs": [
    { "ns": "teamviewer", "id": "12345" }
  ],
  "meta": {
    "teamviewer": { ... }
  }
}

Kein workflow im Patch.
Kein created_at.
Kein updated_at.

Diese werden vom Writer gesetzt.

------------------------------------------------------------
------------------------------------------------------------

# 5. WRITER-INTERAKTION

Module übergeben Patches an:

CRM_EventGenerator::upsert()

Nur der Writer darf:

- neues Event erzeugen
- workflow.state default setzen (open)
- created_at setzen
- updated_at setzen
- Merge durchführen
- idempotent prüfen

------------------------------------------------------------
------------------------------------------------------------

# 6. ENRICH-PHASE

Optional:

rules_enrich.php

Darf:

- customer anhand KN anreichern
- display-Daten ergänzen
- zusätzliche meta-Daten hinzufügen

Darf NICHT:

- workflow verändern
- state ableiten

------------------------------------------------------------
------------------------------------------------------------

# 7. MODUL-DATENSPEICHER

Technische Modul-Daten:

<crm_data>/<modul>/

Beispiel:

<crm_data>/teamviewer/
<crm_data>/pbx/

Kundendaten:

<kunden_storage>/<KN>/<modul>/<jahr>/<typ>/

Strikte Trennung.

------------------------------------------------------------
------------------------------------------------------------

# 8. RAW-STORE (OPTIONAL)

Nur für Debug oder Replay.

Beispiel:

<crm_data>/teamviewer/raw_current.json

RAW-Store ist:

- kein Persistenzsystem
- kein Archiv
- keine Geschäftslogik

------------------------------------------------------------
------------------------------------------------------------

# 9. API-ENDPOINTS

api_<modul>_*.php dürfen:

- Reader aufrufen
- Writer aufrufen

Dürfen NICHT:

- events.json direkt lesen
- events.json direkt schreiben
- Pfade kennen

------------------------------------------------------------
------------------------------------------------------------

# 10. IDPOTENZ

Jedes Modul muss refs[] korrekt setzen.

refs[] ist der einzige stabile Merge-Anker.

Ohne refs ist kein sauberes Upsert möglich.

------------------------------------------------------------
------------------------------------------------------------

# 11. NEUES MODUL HINZUFÜGEN

Schritte:

1) settings_<modul>.php erstellen
2) optional secrets_<modul>.php
3) rules_<modul>.php mit RULES_<MODUL>_BuildPatch()
4) optional API-Endpoint
5) Patch via Writer upsert()
6) Test mit minimalem Event

------------------------------------------------------------
------------------------------------------------------------

# 12. HARTE REGELN

- Module sind Trigger-Adapter
- Module sind zustandslos
- Module besitzen keine Workflow-Logik
- Module speichern keine Events
- Module interpretieren keine Fachzustände
- Module schreiben nur Patches

------------------------------------------------------------
------------------------------------------------------------
