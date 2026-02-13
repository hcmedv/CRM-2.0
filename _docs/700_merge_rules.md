# 700_merge_rules.md
# CRM V2 – MERGE REGELN (verbindlich)

## Zweck

Regelt das Zusammenführen bestehender Events mit neuen Triggerdaten.

---

# 1. Merge-Anker

Merge erfolgt ausschließlich über:

refs[]
  - ns
  - id

Gleiche ns + id = gleiches Event.

---

# 2. Feldregeln

## 2.1 Trigger-Felder (dürfen überschrieben werden)

- timing
- meta.<source>.*
- refs (ergänzend)

---

## 2.2 User-Felder (dürfen NICHT überschrieben werden)

- workflow.state
- workflow.priority
- workflow.type
- workflow.category
- display.title (wenn vom User geändert)
- manuelle worklog Einträge

---

## 2.3 Display-Regel

display darf durch Enrich verbessert werden,
aber nicht gegen explizite User-Änderungen überschrieben werden.

---

# 3. Verboten

- workflow.state aus Triggerdaten neu setzen
- Event schließen weil Trigger endet
- User-Felder bei jedem Poll überschreiben

---

# 4. Idempotenz

Mehrfach identische Triggerdaten dürfen
keine Event-Duplikate erzeugen.
