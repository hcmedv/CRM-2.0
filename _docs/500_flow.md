# 500_flow.md
# CRM V2 – SYSTEM FLOW

Status: Verbindliche Ablaufdefinition

------------------------------------------------------------
# 1. ABLAUF

Trigger
→ rules_<modul>_BuildPatch()
→ optional Enrich
→ Writer (upsert)
→ events.json
→ Reader
→ API
→ UI

------------------------------------------------------------
# 2. TRIGGER

Trigger liefern Rohdaten.

Trigger dürfen:

- timing setzen
- refs setzen
- meta.<source> befüllen

Trigger dürfen niemals workflow.state setzen.

------------------------------------------------------------
# 3. PATCH

Patch enthält:

- event_source
- event_type
- timing
- refs
- meta

Patch enthält nicht:

- workflow
- created_at
- updated_at

Patches ohne refs dürfen kein neues Event erzeugen.

------------------------------------------------------------
# 4. WRITER

Writer:

- sucht Event via refs[]
- merged oder erstellt neu
- setzt workflow.state bei Neu auf open

------------------------------------------------------------
# 5. API-PRINZIP

API ist reine Transport- und Zugriffsschicht.

API enthält:

- keine Geschäftslogik
- keine Workflow-Logik
- keine Merge-Logik
- keine Dateizugriffe

------------------------------------------------------------
# 6. WORKLOAD

Workload entsteht aus:

- timing
- optional worklog

Session-Ende ist kein Workflow-Übergang.

------------------------------------------------------------
