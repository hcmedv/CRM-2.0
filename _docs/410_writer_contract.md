# 410_writer_contract.md
# CRM V2 – WRITER CONTRACT (verbindlich)

## Zweck

Dieses Dokument definiert die verbindlichen Aufgaben und Garantien
von _lib/events/crm_events_write.php.

Der Writer ist die einzige Schreibstelle in events.json.

---

# 1. Exklusivität

Nur crm_events_write.php darf:

- events.json öffnen
- events.json verändern
- events.json speichern

Verboten:

- file_get_contents(events.json) außerhalb crm_events_read.php
- file_put_contents(events.json) außerhalb crm_events_write.php

---

# 2. Garantien des Writers

Der Writer garantiert beim Persistieren:

## 2.1 Root-Vollständigkeit

Folgende Root-Keys existieren IMMER im finalen Event:

- event_id
- event_type
- event_source
- created_at
- updated_at
- workflow
- timing

---

## 2.2 workflow

- workflow.state existiert immer
- Default bei Neuerstellung: "open"
- workflow wird NICHT aus Triggerdaten abgeleitet

---

## 2.3 timing

- timing-Block existiert immer
- mindestens: {}

---

## 2.4 created_at / updated_at

- created_at nur bei Neuerstellung gesetzt
- updated_at bei jeder erfolgreichen Änderung aktualisiert

---

## 2.5 Merge-Verhalten

- Merge erfolgt über refs
- idempotent
- keine Duplikate bei gleichem ns/id

---

## 2.6 Validierung

Writer prüft:

- event_type vorhanden
- event_source vorhanden
- workflow.state vorhanden
- timing Block vorhanden

Bei Verstoß:

- Hard Fail (kein Persist)

---

# 3. Was der Writer NICHT tut

- Keine Kundendateien speichern
- Keine Artefakte erzeugen
- Keine Business-Workflows aus Triggerdaten ableiten
- Keine UI-Logik enthalten
