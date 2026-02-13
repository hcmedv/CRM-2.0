# 118 – Structure: Security (CRM V2)

## Ziel

Verbindliche Sicherheits- und Zugriffsstruktur für CRM V2.

Trennung von:

- Authentifizierung (Login)
- Benutzerverwaltung (Core)
- Autorisierung (Rechte/ Rollen)
- Fachlogik (Events / Workflow)
- Kundendaten (Storage)

---

# 1. Web-Zugänge

## CRM (intern)

Login ausschließlich über:

```
/public_crm/login/
```

Beispiele:

- /public_crm/login/index.php
- /public_crm/login/totp.php
- /public_crm/login/logout.php

Kein direkter Webzugriff auf:

- <crm_data>
- <crm_config>
- <crm_archiv>
- <kunden_storage>

Diese liegen außerhalb Webroot.

---

## Service (extern)

Service-Portal ist getrennt:

```
/public_service/
```

Service darf nur explizit freigegebene Inhalte anzeigen.
Keine internen CRM-Daten.

---

# 2. Benutzerquelle (verbindlich)

Benutzer stammen ausschließlich aus:

```
<crm_data>/core/users.json
```

Nicht zulässig als Benutzerquelle:

- /public_crm/login/*
- /data/login/*
- Modulordnern (z. B. <crm_data>/modules/*)
- Public-Bereich allgemein

Benutzerverwaltung ist Core-Verantwortung.

Login ist nur Zugriffsschicht und besitzt keine eigene Benutzerstruktur.


---

# 3. Authentifizierung

Authentifizierung ist:

- technische Zugangskontrolle
- Prüfung von Passwort
- optional TOTP

Authentifizierung erzeugt eine Session.

Authentifizierung steuert niemals:

- workflow.state
- event-Daten
- Merge-Regeln
- Business-Logik

---

# 4. Autorisierung (Rollen / Rechte)

Rollen und Rechte sind Core.

Zukünftige Dateien:

```
<crm_data>/core/roles.json
<crm_data>/core/permissions.json
```

Benutzerobjekte referenzieren Rollen.

Autorisierung entscheidet:

- welche UI-Funktionen sichtbar sind
- welche APIs aufgerufen werden dürfen

---

# 5. Event-Store Zugriffsschutz

events.json ist zentral und geschützt:

```
<crm_data>/events.json
```

Zugriff ausschließlich über:

- crm_events_read.php
- crm_events_write.php

APIs dürfen niemals direkt auf events.json zugreifen.

Dies verhindert:

- Dateikollisionen
- Race Conditions
- inkonsistente Updates

---

# 6. Trigger-Sicherheitsregel

Integrationen / Trigger (pbx, teamviewer, m365, etc.) dürfen:

- timing liefern
- refs liefern
- meta.<source> befüllen

Trigger dürfen niemals:

- workflow.state setzen oder ändern
- Root-Workflow steuern
- Events schließen oder archivieren

Triggerdaten leben ausschließlich in:

meta.<source>.*

---

# 7. Datenbereiche (physische Trennung)

Systemdaten:

```
<crm_data>/core/
<crm_data>/master/
<crm_data>/modules/
<crm_data>/events.json
```

Kundendaten (Dokumente, Uploads):

```
<kunden_storage>/<KN>/<modul>/<jahr>/<typ>/
```

Regel:

Systemdaten ≠ Kundendaten  
Keine Vermischung.

---

# 8. Protokollierung / Logs

Logs sind getrennt von Daten.

Pfad (global):

```
<log>/
```

Logs dürfen keine Secrets enthalten.

RAW-Stores sind optional und rein technisch (Debug/Replay).

---

# 9. Zusammenfassung

Login → technische Authentifizierung  
Users → Core-Daten  
Rollen/Rechte → Core (zukünftig)  
Events → fachliche Container  
Trigger → nur meta + timing + refs  
Storage → physische Kundendokumente  

Verbindliche Trennung. Keine Ausnahmen.
