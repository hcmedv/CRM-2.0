# CONFIG – Zentrale Konfiguration

## Zweck
Technische Grundkonfiguration des CRM-Systems.
Definiert *wo* etwas liegt und *welche Module aktiv sind*.
Keine fachliche Logik.

## Darf enthalten
- Pfade (data, log, tmp, assets)
- URLs / Endpoints
- Feature-Flags
- Liste aktiver Module
- Environment-abhängige Werte (dev / prod)

## Darf NICHT enthalten
- Event-States oder Workflows
- Typen, Kategorien, Prioritäten
- Texte, Labels, Farben
- Merge- oder Business-Regeln
- UI-Logik

## Verantwortung
- Wird von Core, Writer, Modulen und UI nur gelesen
- Keine Laufzeitänderungen

## Änderungsregeln
- Änderungen sind technisch, nicht fachlich
- Neue Quelle ⇒ Eintrag in CONFIG, kein Code-Refactor

## Ziel
- Keine Hardcodings im Writer
- Module aktivier-/deaktivierbar über Config
