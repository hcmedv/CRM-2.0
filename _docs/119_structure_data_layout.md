# 119 – Structure: Data Layout (CRM V2)

## Ziel

Klare, langfristig skalierbare Datenstruktur.

Trennung von:

- System-Kern (Core)
- Fach-Stammdaten (Master)
- Modul-Daten (Integrationen)
- Prozessdaten (Events)
- Kunden-Dokumentenstruktur

Diese Struktur ist strategisch gewählt.
Keine zufällige Ablage.

---

# 1. Grundstruktur

```
<crm_data>/
    core/
    master/
    modules/
    events.json
```

Optional ergänzend:

```
<crm_archiv>/
<crm_log>/
<crm_tmp>/
```

---

# 2. Core (System-Kern)

Pfad:
```
<crm_data>/core/
```

Enthält:

- users.json
- roles.json (zukünftig)
- permissions.json (optional)
- system_flags.json (optional)

Definition:

Core enthält ausschließlich System-relevante Daten,
ohne die das CRM nicht existieren kann.

Beispiele:

- Benutzer
- Rollen
- Rechte
- Systemzustände

Core enthält KEINE fachlichen CRM-Daten.

---

# 3. Master (Fach-Stammdaten)

Pfad:
```
<crm_data>/master/
```

Enthält:

- kunden.json
- contacts.json
- customer_phone_map.json
- ggf. artikel.json
- ggf. service_templates.json

Definition:

Master enthält fachliche Stammdaten des CRM.

Diese Daten gehören dem CRM selbst.
Sie sind nicht modulgebunden.
Sie sind nicht externe Spiegel.

Beispiele:

- Kunden
- Ansprechpartner
- interne Zuordnungen
- Stamminformationen

Wichtig:

Master ist keine technische Cache-Ebene.
Master ist fachlich führend.

---

# 4. Modules (Integrationen)

Pfad:
```
<crm_data>/modules/<modul>/
```

Beispiele:

```
<crm_data>/modules/pbx/
<crm_data>/modules/teamviewer/
<crm_data>/modules/m365/
<crm_data>/modules/camera/
```

Definition:

Modules enthalten:

- externe Rohdaten
- Caches
- technische Spiegel
- Modul-spezifische Persistenz

Beispiele:

- m365 contacts_cache.json
- teamviewer poll_raw.json
- pbx raw_store.json
- camera upload_index.json

Wichtig:

Module-Daten sind NICHT fachlich führend.
Sie sind Adapter-Zwischenschicht.

---

# 5. Events (Prozesscontainer)

Pfad:
```
<crm_data>/events.json
```

Definition:

Events sind fachliche Prozesscontainer.

Sie verbinden:

- Auslöser (meta.*)
- Workflow (workflow.state)
- Zeit (timing)
- Worklog
- Referenzen

WICHTIG:

events.json darf ausschließlich gelesen und geschrieben werden durch:

- crm_events_read.php
- crm_events_write.php

Keine API greift direkt auf events.json zu.

Dies verhindert:

- Dateikollisionen
- Inkonsistente Zustände
- Race Conditions

---

# 6. Kunden-Dokumentenstruktur

Physische Kundendaten (PDF, Kamera, Analyse, Reports)
liegen NICHT unter `<crm_data>`.

Empfohlene Struktur:

```
<storage_kunden>/
    <KN>/
        <modul>/
            <jahr>/
                <typ>/
                    dateien...
```

Beispiel:

```
/_storage_kunden/10032/camera/2026/images/
/_storage_kunden/10032/service/2026/reports/
/_storage_kunden/10032/praxisanalyse/2026/json/
```

Regel:

Kundendaten sind immer:

Modul → Jahr → Typ

Nicht flach.
Nicht gemischt.

---

# 7. Was NICHT erlaubt ist

Nicht erlaubt:

```
<crm_data>/kunden.json
<crm_data>/mitarbeiter.json
<crm_data>/calendar.json
```

Keine flache Ablage.

Keine Modul- und Core-Mischung.

Keine externen Spiegel im Master-Bereich.

---

# 8. Warum diese Struktur?

Diese Struktur ermöglicht:

- klare Verantwortlichkeiten
- Backup-Strategien pro Bereich
- modulare Erweiterbarkeit
- saubere Rechte-Modelle
- spätere Mandantenfähigkeit
- einfache Migration

Sie trennt:

System
Fachlogik
Integration
Prozess
Dokumente

---

# 9. Zusammenfassung

Core → System selbst  
Master → CRM-Stammdaten  
Modules → externe Adapter  
Events → Prozesscontainer  
Storage → physische Kundendaten  

Diese Struktur ist verbindlich für CRM V2.
