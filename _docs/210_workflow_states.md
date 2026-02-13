# 200_workflow_states.md
# CRM V2 – WORKFLOW STATES

Status: Verbindliche Definition  
Zweck: Festlegung der fachlichen Zustände eines Events

------------------------------------------------------------
------------------------------------------------------------

# 1. GRUNDPRINZIP

workflow.state beschreibt den fachlichen Bearbeitungszustand eines Events.

workflow.state ist:

- verpflichtend
- fachlich
- benutzergetrieben
- trigger-unabhängig

Trigger (PBX, TeamViewer, M365, etc.) dürfen workflow.state niemals setzen oder verändern.

------------------------------------------------------------
------------------------------------------------------------

# 2. PFLICHTFELD

Jedes Event muss enthalten:

"workflow": {
  "state": "<value>"
}

Ohne workflow.state ist ein Event ungültig.

Der Begriff lautet ausschließlich:

state

Nicht zulässig:

status

------------------------------------------------------------
------------------------------------------------------------

# 3. STANDARD-STATE

Beim Erstellen eines neuen Events setzt der Writer:

state = "open"

Dieser Default wird nur beim erstmaligen Erzeugen gesetzt.

------------------------------------------------------------
------------------------------------------------------------

# 4. ZULÄSSIGE STATES (EMPFOHLENES MINIMUM)

open  
work  
waiting  
closed  
archiv  

Die konkrete Liste kann erweitert werden, muss aber zentral definiert bleiben.

------------------------------------------------------------
------------------------------------------------------------

# 5. BEDEUTUNG DER STATES

open  
→ Neu eingegangen, noch nicht aktiv bearbeitet.

work  
→ Aktiv in Bearbeitung.

waiting  
→ Warten auf Kunde, Rückruf, Material, etc.

closed  
→ Fachlich abgeschlossen.

archiv  
→ Historisch abgeschlossen, nicht mehr aktiv sichtbar.

------------------------------------------------------------
------------------------------------------------------------

# 6. WER DARF STATES ÄNDERN?

workflow.state darf geändert werden durch:

- Benutzer (UI)
- definierte CRM-Funktionen
- administrative Aktionen

workflow.state darf NICHT geändert werden durch:

- Trigger
- timing
- duration_sec
- Hangup
- Session-Ende
- Merge
- Enrich
- API ohne Benutzerkontext

------------------------------------------------------------
------------------------------------------------------------

# 7. STATE ≠ TRIGGER-ZUSTAND

Wichtiger Grundsatz:

Ein technischer Zustand eines Triggers ist kein Workflow-State.

Beispiele:

Session beendet ≠ closed  
Call hangup ≠ closed  
duration_sec > 0 ≠ abgeschlossen  

workflow.state ist ausschließlich eine fachliche Entscheidung.

------------------------------------------------------------
------------------------------------------------------------

# 8. WORKFLOW UND WORKLOAD

workflow.state steuert:

- Filter
- UI-Darstellung
- Abrechnungslogik
- Sichtbarkeit

workflow.state wird nicht aus timing berechnet.

Workload entsteht aus:

- timing
- optional worklog
- CRM-Rundungslogik

------------------------------------------------------------
------------------------------------------------------------

# 9. MERGE-REGEL

Beim Merge:

- workflow.state bleibt unverändert
- neuer Patch darf workflow.state nicht überschreiben
- Trigger dürfen state nicht zurücksetzen

------------------------------------------------------------
------------------------------------------------------------

# 10. ERWEITERUNG VON STATES

Neue States dürfen:

- nur zentral ergänzt werden
- dokumentiert werden
- keine implizite Logik enthalten

Keine automatische State-Ableitung.

------------------------------------------------------------
------------------------------------------------------------

# 11. ARCHITEKTUR-GARANTIE

Durch diese Trennung ist sichergestellt:

- Trigger beeinflussen keine Fachzustände
- Workflow bleibt unter Benutzerkontrolle
- Events sind stabile Bearbeitungseinheiten
- Timing ist rein technisch

------------------------------------------------------------
------------------------------------------------------------
