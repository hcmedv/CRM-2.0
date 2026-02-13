# 110 – Authentifizierung & Benutzerverwaltung (CRM)

## Ziel

Saubere, zentrale Benutzerverwaltung.

Benutzer sind System-Objekte.
Nicht Login-Modul-Objekte.
Nicht Modul-Daten.

---

# Speicherort (verbindlich)

```
<crm_data>/core/users.json
```

Nicht zulässig (Altstruktur, nur zur Dokumentation historischer Ablageorte):

```
/data/login/mitarbeiter.json
/login/mitarbeiter.json
```


Begründung:

- Benutzer sind System-Kernbestandteil
- Login ist nur eine Oberfläche
- Benutzerverwaltung wird später eigenständiges Modul
- Core-Daten dürfen nicht in Modulpfaden liegen

---

# Inhalt users.json

Beispielstruktur:

```
[
  {
    "id": "u_01",
    "username": "tbuss",
    "display_name": "Thomas Buss",
    "email": "tbuss@firma.de",
    "active": true,
    "roles": ["admin"],
    "auth": {
      "password_hash": "...",
      "totp_enabled": true,
      "totp_secret": "..."
    },
    "created_at": 1736000000,
    "updated_at": 1736000000
  }
]
```

---

# Architekturregel

Login liest Benutzer.

Login besitzt keine Benutzer.

Login erzeugt keine Benutzerstruktur.

Benutzerverwaltung ist Core-Verantwortung.

---

# Erweiterbarkeit

Später möglich:

- Rollenmodell
- Rechte-Matrix
- Benutzer-Status
- Mandantenfähigkeit
- API-Tokens
- Session-Tracking

Alles unter:

```
<crm_data>/core/
```

---

# Sicherheit

Core-Daten:

- nicht versioniert
- außerhalb Webroot
- nur serverseitig lesbar

---

# Fazit

Benutzer gehören in:

```
<crm_data>/core/
```

Nicht in Login.
Nicht in Module.
Nicht flach unter data.

---------------------------------------------------------------------

# 115 – Login & Authentifizierungsstruktur

## Ziel

Klare Trennung von:

- Benutzerverwaltung (Core)
- Authentifizierung (Login-Modul)
- Session-Verwaltung (Runtime)

---

# Login-Modul

Pfad:
```
/public_crm/login/
```

Enthält:

- index.php
- logout.php
- totp.php
- totp_setup.php

---

# Verantwortlichkeiten

Login:

- prüft Benutzer
- validiert Passwort
- validiert TOTP
- startet Session

Login speichert KEINE Benutzer.

Benutzer stammen ausschließlich aus:

```
<crm_data>/core/users.json
```

---

# Session-Verhalten

Session:

- ist Runtime-Zustand
- lebt im Server-Speicher
- wird nicht persistent gespeichert

Session enthält:

- user_id
- roles
- login_timestamp
- last_activity

---

# Sicherheitsprinzip

Authentifizierung ist:

- technische Zugangskontrolle

Nicht:

- fachliche Workflow-Logik
- Event-Zustandssteuerung
- Rechteverwaltung auf Modulebene

Rechteprüfung erfolgt im CRM selbst.

---

# Wichtig

Login ist austauschbar.

Core bleibt bestehen.

Das System darf niemals von einem Login-Dateipfad abhängig sein.

---------------------------------------------------------------------

# 118 – Structure: Security

## Ziel

Klare Sicherheitsstruktur im CRM.

Trennung von:

- Authentifizierung
- Autorisierung
- Fachlogik

---

# Zugriffsebenen

## 1. Web-Zugang

Login nur über:

```
/public_crm/login/
```

Kein direkter Zugriff auf:

- <crm_data>
- <crm_archiv>
- <storage_kunden>

---

## 2. Benutzerquelle

Benutzer stammen ausschließlich aus:

```
<crm_data>/core/users.json
```

Nicht aus Modulordnern.
Nicht aus Login-Unterordnern.
Nicht aus Public-Bereich.

---

## 3. Datenbereiche

Core:
```
<crm_data>/core/
```

Master:
```
<crm_data>/master/
```

Modules:
```
<crm_data>/modules/
```

Events:
```
<crm_data>/events.json
```

Kundenspeicher:
```
<storage_kunden>/
```

---

# Sicherheitsprinzipien

1. Keine direkten Datei-Zugriffe von APIs auf events.json  
   Nur über:
   - crm_events_read.php
   - crm_events_write.php

2. Keine Modul-Logik darf workflow.state verändern

3. Keine Integration darf Root-Event-Keys setzen

4. Benutzerverwaltung ist Core-Verantwortung

5. Storage ist physisch getrennt vom CRM-Kern

---

# Strukturregel

Systemdaten ≠ Kundendaten

Core & Master:
- sind CRM-interne Daten

Storage:
- ist kundenspezifische Dokumentenablage

---

# Langfristige Erweiterbarkeit

Diese Struktur erlaubt später:

- API-Token pro User
- Mandantenfähigkeit
- Rechte-Matrix
- getrennte Backups
- getrennte Restore-Strategien

---

# Zusammenfassung

Authentifizierung → Login  
Benutzerverwaltung → Core  
Fachlogik → CRM  
Kundendokumente → Storage  

Klare Trennung.
Keine Vermischung.
Keine Zufallsstruktur.
