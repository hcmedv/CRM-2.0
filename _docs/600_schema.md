# Event-Schema (v2 – Draft)

## Ziel
Einheitliches, lesbares und aggregierbares Event-Format.
Stabil für Merge, UI und spätere Auswertungen.

---

## Root
- `event_id`
- `event_type`
- `event_source`
- `created_at`
- `updated_at`

---

## display
- `title`
- `subtitle`
- `tags[]`
- `company`
- `name`
- `phone`
- `customer { number, company, name }`

Zweck:
- Anzeige
- Keine Geschäftslogik

---

## workflow
- `state`
- `priority`
- `type`
- `category`
- `note`

Zweck:
- Fachlicher Status
- Steuerung von UI & Auswertungen

---

## timing
- `started_at`
- `ended_at`
- `duration_sec`

Zweck:
- Zeitliche Einordnung
- Basis für Worklog / Reports

---

## refs[]
- `{ ns, id }`

Zweck:
- Idempotenz
- Externe Referenzen (PBX, TV, etc.)

---

## worklog[]
- `{ source, minutes, note, at }`

Zweck:
- Arbeitszeit
- Abrechnung / Nachweise

---

## meta
- `merge`
- `history`
- `sources`
- `ui`
- `debug`

Zweck:
- Technische Nachvollziehbarkeit
- Keine Anzeige für Kunden

---

## Regeln
- Snake_case
- Keine Pflichtfelder ohne fachlichen Grund
- Erweiterbar ohne Schema-Bruch
