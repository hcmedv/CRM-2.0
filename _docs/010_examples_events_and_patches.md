# 010_examples_events_and_patches.md
# Beispiele – Event & Patch

------------------------------------------------------------
# 1. Minimal gültiges gespeichertes Event
------------------------------------------------------------

{
  "event_id": "01ABC...",
  "event_type": "remote",
  "event_source": "teamviewer",
  "created_at": 1736000000,
  "updated_at": 1736000000,
  "workflow": {
    "state": "open"
  },
  "timing": {},
  "display": {
    "title": "Fernwartung"
  },
  "refs": [],
  "meta": {}
}

------------------------------------------------------------
# 2. Minimaler Trigger-Patch
------------------------------------------------------------

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
    "teamviewer": {
      "raw": {}
    }
  }
}

WICHTIG:
Der Patch enthält KEIN workflow.

------------------------------------------------------------
# 3. Ungültige Beispiele
------------------------------------------------------------

❌ workflow.status statt state

❌ state aus ended_at ableiten

❌ Direktzugriff auf events.json

❌ Pfad wie "/_kunden/10032/..." im Event speichern

❌ timing komplett weglassen
