# 320 – Module: Erstellung & Struktur (CRM V2)

## Ziel

Ein Modul ist eine klar abgegrenzte Funktionseinheit im CRM.

Module dürfen:

- Daten liefern
- Daten transformieren
- Events erzeugen (über Writer)
- UI-Komponenten bereitstellen

Module dürfen nicht:

- direkt auf events.json zugreifen
- workflow.state setzen
- Root-Struktur von Events eigenständig verändern
- absolute Pfade verwenden

---

# 1. Modul-Typen

Ein Modul kann sein:

- Integration (pbx, teamviewer, m365, etc.)
- Fachmodul (vorgang, bericht, camera, etc.)
- Hilfsmodul (search, export, tools)

---

# 2. Verzeichnisstruktur

## Code (Public)

Beispiel:

```
/public_crm/<modul>/
/public_crm/api/<modul>/
```

## Konfiguration

```
<crm_config>/<modul>/settings_<modul>.php
<crm_config>/<modul>/secrets_<modul>.php
```

Secrets sind optional.

---

# 3. Modul-Daten (NEUE REGEL)

Globale Struktur in `settings_crm.php`:

```
'paths' => [
  'root'          => $ROOT,
  'crm_data'      => $ROOT . '/_crm_data',
  'crm_config'    => $ROOT . '/_crm_config',
  'crm_archiv'    => $ROOT . '/_crm_archiv',
  'kunden_storage'=> $ROOT . '/_storage_kunden',
  'log'           => $ROOT . '/log',
  'tmp'           => $ROOT . '/tmp',
]
```

---

# 320 – Module: Erstellung & Struktur (CRM V2)

## Konvention pro Modul

Moduldaten liegen unter:

```
<crm_data>/modules/<modul>/
```

Zugriff erfolgt ausschließlich über:

```
CRM_MOD_PATH('<modul>', 'data')
```

Beispiel:

```
CRM_MOD_PATH('vorgang','data')
→ <crm_data>/modules/vorgang/
```

WICHTIG:

In Modul-Settings dürfen nur Dateinamen stehen.
Keine absoluten Pfade.
Keine Hardcodes.
Keine direkten Referenzen auf <crm_data>.

Beispiel (zulässig):

```
'file_current' => 'vorgang_current.json'
```

Nicht zulässig (Altstruktur / Hardcode):

```
'/data/vorgang/vorgang_current.json'
'/ _crm_data/modules/vorgang/vorgang_current.json'
```

---

# 4. Logs pro Modul

Modullogs liegen unter:

```
<log>/<modul>/
```

Zugriff:

```
CRM_MOD_PATH('<modul>', 'log')
```

---

# 5. Archiv pro Modul (optional)

Falls benötigt:

```
<crm_archiv>/<modul>/
```

Nur für technische Archive.
Nicht für Kundendokumente.

---

# 6. Event-Erzeugung (verbindlich)

Module dürfen Events nur erzeugen über:

```
crm_events_write.php
```

oder über:

```
CRM_EventGenerator::upsert()
```

Module dürfen niemals:

- events.json direkt öffnen
- file_put_contents auf events.json machen
- Merge-Logik selbst implementieren

---

# 7. Trigger-Regel (ESSENTIELL)

Module, die als Trigger fungieren (pbx, teamviewer, etc.):

dürfen nur setzen:

- refs
- timing
- meta.<source>.*

dürfen niemals setzen:

- workflow.state
- workflow.priority
- created_at
- updated_at

Diese werden vom Writer kontrolliert.

---

# 8. Kundendaten gehören NICHT ins Modul

Wenn ein Modul Dokumente erzeugt (z. B. camera, bericht, analyse):

Speicherort ist:

```
<kunden_storage>/<KN>/<modul>/<jahr>/<typ>/
```

NICHT:

```
<crm_data>/modules/<modul>/
```

Moduldaten sind Systemdaten.
Kundendokumente sind Storage-Daten.

Strikte Trennung.

---

# 9. Pflichtprinzipien

- Keine absoluten Pfade
- Keine Hardcodes
- Keine Root-Key-Manipulation
- Keine direkte Event-Dateiverarbeitung
- Snake_case
- Additive Erweiterung

---

# 10. Fazit

Ein Modul ist:

- isoliert
- erweiterbar
- austauschbar
- pfadunabhängig
- workflow-neutral

Systemdaten → <crm_data>  
Kundendaten → <kunden_storage>  
Events → zentraler Writer  
