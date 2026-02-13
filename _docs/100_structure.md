# 100_structure.md

# CRM 2.0 Verzeichnisstruktur verbindlich

## Ziel

Klare physische Trennung zwischen

- CRM Systemzustand
- Kundendaten fachliche Inhalte

Systemdaten und Kundendaten dürfen niemals vermischt werden.

---

# ROOT STRUKTUR ausserhalb Webroot

_crm_config  
_crm_data  
_crm_archiv  
_kunden  
log  
tmp  
public_crm  
public_service  
_doc  
_auth  

---

# 1 CRM SYSTEM LAUFZEIT BETRIEB

## _crm_config

Enthält

- settings_crm.php
- settings_module.php
- secrets_module.php

Zweck

Konfiguration  
Keine Laufzeitdaten

---

## _crm_data

Enthält ausschließlich Systemzustand

Beispiel Struktur

_crm_data  
    stores  
    cache  
    raw  
    queue  
    state  

stores  
- events.json  
- kunden_map.json  
- m365_mirror.json  

cache  
- Suchindizes  
- abgeleitete Daten  

raw  
- Debug Payloads  
- Replay Daten  

queue  
- Writer Queue  
- temporäre Arbeitslisten  

state  
- last_run Marker  
- Hashes  
- Checkpoints  

WICHTIG

In _crm_data dürfen keine Kundendokumente liegen  
Keine Uploads  
Keine PDFs  
Keine JSON Berichte  

Nur Systemdaten

---

## _crm_archiv

System Archiv

Beispiel  
- rotierte raw Daten  
- Snapshots  
- Debug Archive  

Nicht für Kundendokumente

---

# 2 KUNDENDATEN FACHLICHE INHALTE

## _kunden

Physische Trennung der Kundendaten vom CRM System

Struktur

_kunden/<KN>/<modul>/<jahr>/<typ>/

VERBINDLICHE REGEL

Modul -> Jahr -> Typ

---

## Beispiel

_kunden/10032  
    camera  
        2026  
            original  
            thumbs  
    berichte  
        2026  
            pdf  
            json  
    praxisanalyse  
        2026  
            pdf  
            json  
            media  

---

# Modul Regel

Jedes Modul das Artefakte erzeugt speichert ausschließlich unter

paths.kunden/<KN>/<modul>/<jahr>/<typ>/

Nicht unter

_crm_data  
_crm_archiv  
public_crm  
public_service  

---

# WICHTIGE GRUNDSAETZE

1 CRM Systemdaten und Kundendaten sind physisch getrennt  
2 Kein Modul darf eigene Root Pfade bauen  
3 Alle Pfade kommen ausschließlich aus settings_crm  
4 Events speichern niemals physische Dateipfade  
5 meta enthält keine absoluten Systempfade  
6 Löschung eines Kunden bedeutet Löschen von _kunden/<KN>  

---

# ARCHITEKTUR PHILOSOPHIE

CRM ist das System  
KUNDEN sind Inhalte  

Das CRM verarbeitet Daten  
Die Kundendaten gehören nicht zum Systemzustand  

Diese Trennung ist bewusst hart
