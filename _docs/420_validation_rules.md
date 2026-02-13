# 420_validation_rules.md
# CRM V2 – SCHEMA VALIDIERUNG

## Ziel

Klare Definition wann ein Event gültig ist.

---

# 1. Mindestanforderungen

Ein Event ist gültig wenn vorhanden:

- event_type
- event_source
- workflow.state
- timing (Block)
- event_id (nach Writer)

---

# 2. Hard Fail Bedingungen

Persistenz wird abgebrochen wenn:

- workflow fehlt
- workflow.state fehlt
- timing fehlt
- event_type fehlt
- event_source fehlt

---

# 3. Auto-Korrekturen (nur Writer)

Der Writer darf automatisch ergänzen:

- workflow.state = open (bei Neuerstellung)
- timing = {} (falls nicht vorhanden)

---

# 4. Keine Toleranz

Keine stillschweigende Umbenennung von:
- status -> state
- camelCase -> snake_case

Fehlerhafte Keys führen zu Fehler.
