# 400_runtime.md
# CRM V2 – RUNTIME & EVENT STORE

Status: Verbindliche Laufzeitdefinition

------------------------------------------------------------
# 1. EVENTS STORE

events.json liegt unter:

<crm_data>/events.json

Zugriff ausschließlich über:

- crm_events_read.php
- crm_events_write.php

------------------------------------------------------------
# 2. READER

crm_events_read.php darf:

- lesen
- filtern
- suchen

Reader darf nicht schreiben.

------------------------------------------------------------
# 3. WRITER

crm_events_write.php darf:

- neue Events erzeugen
- workflow.state default setzen (open)
- created_at setzen
- updated_at setzen
- Merge durchführen
- atomar speichern

workflow.state Default ("open") wird ausschließlich bei Neuanlage gesetzt,
niemals bei Merge/Update.

------------------------------------------------------------
# 4. PATCH-ANFORDERUNG

Ein neues Event darf nur erzeugt werden, wenn mindestens eine ref vorhanden ist.

Patches ohne refs dürfen kein neues Event erzeugen.

------------------------------------------------------------
# 5. MERGE-PRINZIP

Merge basiert ausschließlich auf:

refs[].ns + refs[].id

------------------------------------------------------------
# 6. MERGE-REGELN

workflow:
- bleibt unverändert
- darf nicht durch Patch überschrieben werden

timing:
- fehlende Werte dürfen ergänzt werden
- bestehende Werte dürfen erweitert werden
- keine fachliche Interpretation

display:
- darf ergänzt werden
- darf nicht vollständig gelöscht werden

meta:
- meta.<source> darf überschrieben werden
- andere meta-Bereiche bleiben unberührt

refs:
- werden nicht gelöscht
- neue refs können ergänzt werden

------------------------------------------------------------
# 7. API-LAYER

API ist reine Transportschicht.

API darf:

- Reader verwenden
- Writer verwenden

API darf NICHT:

- events.json direkt lesen
- events.json direkt schreiben
- workflow verändern
- timing manipulieren
- Patch fachlich interpretieren

------------------------------------------------------------
# 8. TRANSAKTION

Writer muss:

- atomar speichern
- temporäre Datei verwenden
- rename() nutzen
- Race Conditions vermeiden

------------------------------------------------------------
