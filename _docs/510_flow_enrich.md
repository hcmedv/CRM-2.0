510_flow_enrich

# CRM Events – Kurz­dokumentation (Referenzstand PBX + TeamViewer)

## 1. Ziel & Grundprinzip

Dieses System verarbeitet **externe Ereignisse** (PBX-Anrufe, TeamViewer-Sessions) zu **konsistenten CRM-Events**.

**Ziele**
- Idempotente Event-Erstellung (keine Duplikate)
- Nachträgliche Anreicherung ohne Datenverlust
- Klare Verantwortlichkeiten zwischen Processing, Rules, Enrich und Writer
- Erweiterbarkeit (Merge, Worklog, UI)

---

## 2. Event-Lifecycle (Übersicht)

```
RAW Event
  ↓
process_*          (Quelle-spezifisch, sammelt Zustände)
  ↓
rules_*            (Patch-Aufbau)
  ↓
rules_common       (Normalisierung)
  ↓
rules_enrich       (Anreicherung / Fallbacks)
  ↓
writer             (Validierung + idempotentes Upsert)
  ↓
events.json
```

---

## 3. Komponenten & Verantwortlichkeiten

### 3.1 process_* (PBX / TeamViewer)

**Aufgabe**
- Entgegennahme von Rohdaten
- Zusammenführen mehrerer Zustände (z. B. PBX: `newcall → answer → hangup`)
- Übergabe eines vollständigen Patch-Objekts

**Wichtige Regeln**
- Keys dürfen **ergänzt**, aber **nicht überschrieben** werden
- Besonders `timing`:
  - `started_at` nie entfernen oder leeren
  - `ended_at` nur ergänzen
  - `duration_sec` erst berechnen, wenn beide Zeiten existieren

---

### 3.2 rules_* (source-spezifisch)

Beispiele:
- `rules_pbx.php`
- `rules_teamviewer.php`

**Aufgabe**
- Rohdaten → semantischer Patch
- Setzt:
  - `event_source`
  - `event_type`
  - `display.*` (Titel, Basis-Tags, evtl. KN)
  - `timing.*` (wenn direkt verfügbar)
  - `refs[]` (für Idempotenz)
  - `meta.<source>.raw`

**Keine**
- Kontaktauflösung
- Kundenauflösung
- Fallback-Logik

---

### 3.3 rules_common

**Aufgabe**
- Technische Normalisierung
  - Telefonformate
  - Tags
  - Array-Helfer

Keine fachliche Logik.

---

### 3.4 rules_enrich (zentrale Fachlogik)

**Aufgabe**
- Fachliche Anreicherung ohne Datenverlust

#### KN-Ermittlung – Priorität
1. Bereits im Patch (z. B. TeamViewer Groupname `#10005`)
2. TeamViewer Adhoc: `deviceid → teamviewer_adhoc_map.json`
3. Phone-Fallback: `phone → kunden_phone_map.json`

Ergebnis:
```json
"display": {
  "customer": { "number": "10111" },
  "customer_source": "teamviewer_device_map"
}
```

#### Kunden-Anreicherung
- Datenquelle: `kunden.json`
- Ergänzt Anzeigeinformationen:

```json
"display": {
  "customer": {
    "number": "10111",
    "company": "Planungsbüro Zemelka GmbH",
    "name": "Elmar Zemelka"
  },
  "name": "Elmar Zemelka"
}
```

---

### 3.5 Writer

**Aufgabe**
- Schema-Validierung
- Idempotentes Upsert
- Kein fachliches Umschreiben von Daten

**Idempotenz über `refs`:**
```json
{ "ns": "pbx", "id": "<call_id>" }
```
oder
```json
{ "ns": "teamviewer", "id": "<session_id>" }
{ "ns": "teamviewer_device", "id": "<deviceid>" }
```

---

## 4. Timing-Modell

### PBX
- Mehrere Events liefern Teilinformationen
- Endzustand muss enthalten:
```json
"timing": {
  "started_at": <ts>,
  "ended_at": <ts>,
  "duration_sec": <diff>
}
```

**Regel**
- Kein Zustand darf bestehende Timing-Werte überschreiben oder löschen

### TeamViewer
- `start_date` / `end_date` liegen vollständig vor
- Timing wird direkt gesetzt

---

## 5. Mapping-Dateien

### kunden_phone_map.json
```json
"<phone_digits>": "<customer_no>"
```

### teamviewer_adhoc_map.json
```json
"<deviceid>": {
  "customer_no": "10111",
  "label": "...",
  "source": "user"
}
```

Diese Dateien erlauben fachliche Erweiterungen ohne Codeänderung.

---

## 6. Aktueller Referenzstand

- TeamViewer remote + adhoc: stabil
- PBX Timing korrekt aggregiert
- Kunden- und Kontaktanreicherung konsistent
- Keine Writer-Seiteneffekte

---

## 7. Bewusst offen (Next Steps)

- Event-Merge (PBX ↔ TeamViewer)
- State-Historie
- Worklog-Ableitung
- UI-Timeline

---

**Empfehlung**
Diesen Stand als Referenz einfrieren und weitere Arbeiten ausschließlich inkrementell darauf aufbauen.
