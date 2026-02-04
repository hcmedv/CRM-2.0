 # 113 – Assets-Struktur & Konventionen (CRM)

## Ziel
Klare, vorhersehbare Struktur für **Assets (CSS, JS, Images, Fonts)**, damit:
- jedes Modul isoliert bleibt
- Lade-Reihenfolge nachvollziehbar ist
- Debugging einfach wird
- Erweiterungen keine Seiteneffekte erzeugen

---

## Grundprinzipien

- Assets sind **immer modulbezogen**
- Globale Assets existieren nur einmal
- Kein Asset wird „irgendwo“ abgelegt
- Kein Modul greift auf Assets eines anderen Moduls zu
- Alles ist explizit eingebunden (kein Autoload-Magie)

---

## Asset-Layer

### 1) Global Assets (CRM-weit)

Pfad: /public/_assets/


Enthält:
- `css/crm.css`            → globales Layout & UI
- `js/crm.js`              → globale Helpers (optional)
- `img/`                   → Logos, Icons, UI-Grafiken
- `fonts/`                 → Webfonts

Regeln:
- werden auf **allen** Seiten geladen
- enthalten **keine Modul-Logik**
- dürfen von Modulen genutzt, aber nicht verändert werden

---

### 2) Modul-Assets

Pfad: /public/<modul>/assets/

Beispiele:

/public/events/assets/
├─ events.css
├─ events.js
├─ events_render.js
└─ img/


Regeln:
- jedes Modul besitzt **sein eigenes Asset-Verzeichnis**
- Modul lädt **nur seine eigenen Assets**
- kein Cross-Modul-Import
- Asset-Namen beginnen immer mit dem Modulnamen

---

## CSS-Assets

### Global
/public/_assets/css/crm.css

- Layout
- UI-Primitives
- globale Utilities
- Responsive Basis

### Modul

/public/<modul>/assets/<modul>.css

- nur modul-spezifische Klassen
- Prefix = Modulname
- keine globalen Overrides

---

## JavaScript-Assets

### Global JS (optional)

/public/_assets/js/crm.js


Zweck:
- globale Hilfsfunktionen
- Event-Bus / Utilities
- Debug-Hooks

Keine:
- Modul-Logik
- Seitenlogik

---

### Modul-JS

Pfad: /public/<modul>/assets/

Empfohlene Aufteilung:

<modul>.js → Bootstrap / Init
<modul>_api.js → API Calls
<modul>_render.js → Rendering
<modul>_actions.js → User Actions


Regeln:
- Dateinamen immer mit Modulprefix
- klare Verantwortung pro Datei
- kein monolithisches „alles.js“

---

## Images & Media

### Global

/public/_assets/img/


- Logos
- allgemeine Icons
- UI-Grafiken

### Modul
/public/<modul>/assets/img/


- modul-spezifische Icons
- Screenshots
- Placeholder

---

## Fonts

Pfad: /public/_assets/fonts/


Regeln:
- Fonts sind **immer global**
- nie modulweise eingebunden
- Definition ausschließlich in `crm.css`

---

## Lade-Reihenfolge (verbindlich)

1. Global CSS (`crm.css`)
2. Modul CSS (`<modul>.css`)
3. Global JS (falls vorhanden)
4. Modul JS

Kein Lazy-Load zum Start.
Optimierung erfolgt später bewusst.

---

## Debug-Regeln

- Jedes Asset muss eindeutig zuordenbar sein
- Keine anonymen Dateinamen (`style.css`, `script.js`)
- Bei Fehlern ist sofort klar:
  - globales Asset oder Modul-Asset

---

## Ergebnis

- saubere Trennung global vs. modul
- wartbare Struktur
- keine Seiteneffekte
- einfache Erweiterbarkeit

Diese Struktur ist verbindlich für alle neuen Module.
