# 000_start_here.md
# CRM V2 – START HERE

Dieses Dokument ist der Einstieg für neue Entwickler.

Lesereihenfolge:

1. 600_schema.md
2. 200_workflow_states.md
3. 400_runtime.md
4. 119_structure_data_layout.md
5. 300_modules.md

------------------------------------------------------------

# SYSTEMÜBERBLICK (KURZ)

Trigger (pbx, teamviewer, user, api, etc.)
        ↓
RULES_<module>_BuildPatch()
        ↓
(optional) RULES_ENRICH_Apply()
        ↓
CRM_EventGenerator::upsert()
        ↓
events.json
        ↓
Reader → API → UI

------------------------------------------------------------

# 10 HARTE REGELN

1. workflow.state ist Pflicht.
2. timing ist Pflicht.
3. Nur der Benutzer bestimmt workflow.state.
4. Trigger schreiben niemals workflow.
5. Trigger leben ausschließlich in meta.*
6. Nur crm_events_read.php und crm_events_write.php dürfen events.json anfassen.
7. APIs greifen niemals direkt auf events.json zu.
8. Keine Dateipfade im Event speichern.
9. Snake_case ist verpflichtend.
10. state ist state – niemals status.

------------------------------------------------------------

# MINIMALER TEST

Ein neues Event muss enthalten:

- event_id
- event_type
- event_source
- created_at
- updated_at
- workflow.state
- timing {}

Wenn das fehlt → ungültig.
