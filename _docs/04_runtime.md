# Runtime – Laufzeitkontext

## Zweck
Minimaler, technischer Laufzeitkontext.
Stellt Informationen bereit, die **zur Laufzeit** benötigt werden,
aber **nicht konfigurierbar** sind.

---

## Enthält
- `env` (z. B. dev / prod)
- `apiBase`
- `csrf` / auth-token
- `debug` (bool)

---

## Enthält NICHT
- Pfade, URLs, Module (→ CONFIG)
- Fachliche Definitionen (→ DEFS)
- Event-Daten
- Merge- oder Business-Logik

---

## Regeln
- Wird zur Laufzeit **injiziert**
- Read-only
- Kein Persistieren

---

## Nutzung
- UI (z. B. API-Aufrufe)
- API-Endpunkte (Auth, Debug)
- Keine direkte Nutzung im Writer

---

## Ziel
- Vorhersehbare Laufzeitbedingungen
- Keine impliziten Abhängigkeiten
- Trennung von Konfiguration und Kontext
