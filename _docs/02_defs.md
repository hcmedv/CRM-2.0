# DEFS – Zentrale Definitionen

## Zweck
Zentrale, fachliche Definitionen für Events.
Single Source of Truth für **Writer** und **UI**.
Keine Hardcodings im Code.

---

## Enthält
- Event-States
- Priorities
- Types
- Categories
- Labels
- Farben / UI-Metadaten

---

## Enthält NICHT
- Pfade, URLs, Feature-Flags (→ CONFIG)
- Merge-Logik
- Modul-spezifische Fakten
- Laufzeitwerte

---

## Regeln
- Änderungen sind **fachlich**
- Jede Erweiterung (neuer State/Type) erfolgt **hier zuerst**
- Writer und UI lesen ausschließlich aus DEFS

---

## Definitionen (Struktur)

### Event States
- `open`
- `work`
- `waiting`
- `closed`
- `archiv`

Regeln:
- State-Übergänge werden **nicht** hier validiert
- Nur erlaubte Werte

---

### Priorities
- `low`
- `normal`
- `high`
- `urgent`

---

### Event Types
- `pbx`
- `remote`
- `service`
- `camera`
- erweiterbar

---

### Categories
- `allgemein`
- `support`
- `wartung`
- `installation`
- erweiterbar

---

### UI-Definitionen
- Farben je State
- optionale Icons
- optionale Labels

(UI nutzt diese Werte, definiert sie aber nicht selbst)

---

## Ziel
- Einheitliche Begriffe
- Vorhersehbares Verhalten
- Erweiterbarkeit ohne Refactor
