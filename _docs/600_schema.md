# 600_schema.md
# CRM V2 – EVENT SCHEMA (VERBINDLICH)

Status: Normative Referenz

------------------------------------------------------------
# 1. ZIEL

Dieses Dokument definiert verbindlich:

- Event Root-Struktur
- Pflichtfelder
- Workflow-Regeln
- Trigger-Entkopplung
- Merge-Grundsätze
- Workload-Prinzip
- Benennungsregeln

------------------------------------------------------------
# 2. ARCHITEKTUR-GRUNDSATZ

Das Event ist der fachliche Container.

Trigger (pbx, teamviewer, m365, user, api, etc.) liefern ausschließlich technische Daten.

Trigger steuern niemals:

- workflow.state
- Priorität
- Archivierung
- fachliche Entscheidungen

Triggerdaten leben ausschließlich in:

meta.<source>.*

------------------------------------------------------------
# 3. VERBINDLICHE ROOT-STRUKTUR

Jedes Event muss enthalten:

- event_id
- event_type
- event_source
- created_at
- updated_at
- workflow
- timing

------------------------------------------------------------
# 4. EVENT_TYPE

event_type ist ein kontrollierter Wert.

Neue event_type-Werte dürfen nur additiv eingeführt werden  
und müssen zentral dokumentiert werden.

------------------------------------------------------------
# 5. WORKFLOW (PFLICHT)

workflow:
  state: open

Regeln:

- workflow.state ist verpflichtend
- Begriff ist ausschließlich "state"
- "status" ist ungültig
- state wird durch Benutzer bestimmt
- state wird niemals durch Trigger gesetzt

Details siehe:
200_workflow_states.md

------------------------------------------------------------
# 6. TIMING (PFLICHT)

timing:
  started_at
  ended_at
  duration_sec

Oder minimal:

timing: {}

Regeln:

- timing-Block muss existieren
- duration_sec nur wenn started_at + ended_at vorhanden
- timing beeinflusst niemals workflow.state
- timing darf leer sein (z.B. bei manuell erzeugten Events)

------------------------------------------------------------
# 7. WORKLOG

Optional:

worklog[]:
  - source
  - minutes
  - note
  - at

Worklog enthält explizite Arbeitszeiteinträge.

------------------------------------------------------------
# 8. WORKLOAD (WICHTIG)

Workload ist kein Event-Feld.

Workload bezeichnet die rechnerische oder fachliche Arbeitszeit,
die sich ergibt aus:

- timing
- optional worklog
- CRM-Rundungslogik

Workload darf nicht als separates JSON-Feld gespeichert werden.

------------------------------------------------------------
# 9. DISPLAY

Display dient ausschließlich der Anzeige.

Regeln:

- display.title ist verpflichtend
- keine Logik
- keine Statusentscheidungen

------------------------------------------------------------
# 10. REFS

refs[] ist der idempotente Merge-Anker.

Merge basiert ausschließlich auf:

refs[].ns + refs[].id

------------------------------------------------------------
# 11. ARCHIV

workflow.state="archiv" ist ein logischer Zustand.

Es findet keine automatische physische Verschiebung von Daten statt.

------------------------------------------------------------
# 12. BENENNUNGSREGELN

- snake_case für alle JSON-Keys
- keine camelCase-Keys
- kein workflow.status
- keine Dateipfade im Event

------------------------------------------------------------
# 13. VERBOTENE MUSTER

- workflow aus Triggerdaten ableiten
- Session-Ende = closed
- Hangup = abgeschlossen
- Hardcodierte Pfade
- Direktzugriff auf events.json
- workload als JSON-Feld speichern

------------------------------------------------------------
