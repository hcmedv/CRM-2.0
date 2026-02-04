# 012 – CSS Struktur & Konventionen (CRM)

## Ziel
Definition einer **einheitlichen CSS-Strategie**, damit:
- Module optisch konsistent bleiben
- Änderungen an einem Modul nicht andere Module zerstören
- Debugging eindeutig ist (klarer Ursprung jeder Klasse)
- das System langfristig erweiterbar bleibt

---

## Grundprinzipien

- **Globales CSS** liefert Layout + UI-Bausteine
- **Modul-CSS** liefert nur modul-spezifische Styles
- Module überschreiben **keine globalen Klassen**
- Keine Inline-CSS in Templates/Seiten
- Keine CamelCase in CSS-Klassen
- Nur **eine** Konvention, keine Mischformen

---

## CSS Layer (verbindlich)

### 1) Global: `crm.css`
Pflicht auf jeder Seite.

Enthält:
- Layout-Grundlagen (Container, Grid, Cards)
- UI-Primitives (Buttons, Inputs, Chips, Tabs, Modal/Overlay)
- globale Utilities (z. B. `muted`, spacing helpers)
- Tokens / Variablen (Farben, Radius, Shadows)

Darf:
- globale Klassen definieren, die überall verwendet werden

Darf nicht:
- modul-spezifische Optik/Logik enthalten

---

### 2) Modul: `<modul>.css`
Optional pro Seite (nur wenn das Modul eigene Styles braucht).

Pfad-Konzept:
- Modul besitzt ein eigenes Assets-Verzeichnis
- Modul-CSS wird nur auf Modul-Seiten geladen

Enthält:
- Styles, die nur dieses Modul betreffen
- ausschließlich Klassen, die mit dem Modulpräfix beginnen

Darf:
- modul-spezifische Komponenten stylen

Darf nicht:
- globale Klassen überschreiben
- Styles für andere Module enthalten

---

## Naming-Konvention (verbindlich)

### Prefix = Modulname ausgeschrieben
Keine Abkürzungen.

Beispiele:
- `events-*`
- `login-*`
- `stammdaten-*`
- `service-report-*`
- `admin-*`

---

## BEM-ähnliches Schema (pragmatisch)

Schema:
- `<modul>-<block>`
- `<modul>-<block>__<element>`
- `<modul>-<block>--<modifier>`

Beispiele (Events):
- `events-tile`
- `events-tile--open`
- `events-detail`
- `events-detail__notes`
- `events-list--work`

Regeln:
- keine Mischformen
- keine abweichenden Separatoren
- Modifier niemals ohne Block

---

## Bindestrich vs. Unterstrich (final)

Frontend (CSS/HTML/Assets/Routes):
- **nur Bindestrich** (`-`)

Beispiele:
- `service-report.css`
- `service-report__header`
- `/service-report`

Unterstrich ist nur für:
- PHP Variablen
- PHP Array Keys / JSON Keys (wenn notwendig)

---

## Globale Klassen (sehr wenige)

Global erlaubte Klassen aus `crm.css` (Beispiele):
- Layout: `crm-main`, `crm-card`, `crm-grid`
- Utilities: `muted`, `hidden`
- Controls: `btn`, `input`, `chip`, `tab`, `modal`

Regel:
- Globale Klassen sind stabil und werden nicht modulweise angepasst

---

## Utilities vs. Modul-Styles

- Wiederkehrende UI-Elemente (Buttons, Inputs, Chips, Tabs) gehören in `crm.css`
- Modul-spezifische Sonderfälle gehören in `<modul>.css`
- Keine Utility-Klassen pro Modul

---

## Responsives Verhalten

- Responsives Layout ist primär Aufgabe von `crm.css`
- Module definieren nur zusätzliche Breakpoints, wenn zwingend notwendig
- Tablet-Optimierung ist Pflicht (primär iPad)

---

## Debugging-Regeln

- Jede CSS-Regel muss eindeutig zuordenbar sein:
  - global → `crm.css`
  - modul → `<modul>.css`
- Keine inline Styles
- Keine zufälligen Klassen ohne Prefix

---

## Ergebnis

- konsistenter Look & Feel
- sichere Modul-Isolation
- geringe Seiteneffekte bei Änderungen
- klarer Upgrade-Pfad (HTMX/Alpine später möglich)

Diese Regeln sind verbindlich für alle neuen Seiten und Module.
