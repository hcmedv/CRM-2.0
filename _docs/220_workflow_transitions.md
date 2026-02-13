# 210_workflow_transitions.md
# CRM V2 – WORKFLOW STATE TRANSITIONS (VERBINDLICH)

## Ziel

Definition erlaubter Zustandsübergänge.

workflow.state ist kontrolliert.
Nicht jeder Zustand darf in jeden anderen wechseln.

Diese Regeln verhindern:

- unlogische State-Sprünge
- Trigger-induzierte State-Änderungen
- Inkonsistente Auswertungen

------------------------------------------------------------
------------------------------------------------------------

# 1. CORE STATES

open
work
waiting
closed
archiv

------------------------------------------------------------
------------------------------------------------------------

# 2. ERLAUBTE ÜBERGÄNGE

## 2.1 open →

Erlaubt:

- work
- waiting
- closed
- archiv

------------------------------------------------------------

## 2.2 work →

Erlaubt:

- waiting
- closed
- archiv
- open (nur manuell, z.B. Reaktivierung)

------------------------------------------------------------

## 2.3 waiting →

Erlaubt:

- work
- closed
- archiv
- open (manuell)

------------------------------------------------------------

## 2.4 closed →

Erlaubt:

- work (Wiedereröffnung)
- archiv

Nicht erlaubt:

- waiting (direkt)
- open (direkt ohne Reaktivierungslogik)

------------------------------------------------------------

## 2.5 archiv →

Erlaubt:

- work (bewusste Reaktivierung)
- open (bewusst)

Archiv ist kein finaler Zustand,
aber ein passiver.

------------------------------------------------------------
------------------------------------------------------------

# 3. VERBOTENE ÜBERGÄNGE

Unabhängig vom aktuellen Zustand:

NICHT ERLAUBT:

- State-Änderung durch Trigger
- State-Änderung durch Polling
- State-Änderung durch Enrichment
- State-Änderung durch Merge

workflow.state darf nur geändert werden durch:

- Benutzeraktion
- explizite Workflow-Regel im CRM Core

------------------------------------------------------------
------------------------------------------------------------

# 4. DEFAULT

Neues Event:

workflow.state = open

------------------------------------------------------------
------------------------------------------------------------

# 5. WORKLOAD-ENTKOPPLUNG

State-Änderung darf NICHT abhängen von:

- timing.started_at
- timing.ended_at
- duration_sec
- session_end
- call_hangup

Beispiele (verboten):

- ended_at vorhanden → state = closed
- duration_sec > 0 → state = work

------------------------------------------------------------
------------------------------------------------------------

# 6. ERWEITERBARE STATES

Wenn zusätzliche States eingeführt werden:

- Müssen Übergänge explizit definiert werden
- Müssen dokumentiert werden
- Dürfen Core-Logik nicht brechen

------------------------------------------------------------
------------------------------------------------------------

# 7. VALIDIERUNG

Beim State-Wechsel prüfen:

- Ist Ziel-State erlaubt?
- Ist Übergang laut Matrix erlaubt?
- Erfolgt Wechsel durch berechtigte Aktion?

Ungültiger Übergang → Hard Fail

------------------------------------------------------------
------------------------------------------------------------
