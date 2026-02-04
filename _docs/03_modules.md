# Module – Verantwortlichkeiten & Vertrag

## Zweck
Module kapseln **Quellen-spezifisches Wissen**.
Der Core kennt keine fachlichen Details einzelner Quellen.

---

## Grundprinzip
- Keine festen Modulnamen im Core
- Module werden geladen, nicht per `if (pbx)`
- Jedes Modul ist **optional** und aktivierbar über CONFIG

---

## Modul-Vertrag (Pflicht)

Jedes Modul **muss liefern**:

### facts
- Normalisierte Rohdaten der Quelle
- Keine Interpretation
- Kein Merge

### history
- Zeitliche Abfolge der Quellenereignisse
- Append-only

### refs
- Eindeutige Referenzen zur Idempotenz
- z. B. externe IDs, Call-IDs, Session-IDs

---

## Optionale Modul-Felder

### defaults
- Vorschläge für:
  - `display`
  - `workflow`
- Werden vom Writer nur gesetzt, wenn Ziel leer ist

### ui_hints
- Hinweise für UI-Darstellung
- z. B. bevorzugte Icons, Tags
- **keine Logik**

---

## Modul-Settings
- Jedes Modul besitzt eigene Settings
- Modul-Settings ≠ Global CONFIG
- Kein Zugriff auf fremde Modul-Settings

---

## Verbote
- Kein direktes Schreiben ins Event-Storage
- Keine Merge-Entscheidungen
- Keine Kenntnis anderer Module

---

## Ziel
- Austauschbare Quellen
- Saubere Erweiterbarkeit
- Stabiler Core
