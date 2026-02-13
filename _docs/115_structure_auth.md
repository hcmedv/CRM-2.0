# 115 – Login & Authentifizierungsstruktur (CRM)

## Ziel

Saubere Trennung von:

- Benutzerverwaltung (Core)
- Authentifizierung (Login-Modul)
- Session (Runtime)
- Fachlogik (CRM)

Login ist nur Eintrittspunkt.
Benutzer sind Core-Daten.
Workflow ist unabhängig vom Login.

---

# Architekturüberblick

Benutzerquelle:
```
<crm_data>/core/users.json
```

Login-Oberfläche:
```
/public_crm/login/
```

Session:
- Serverseitig
- Nicht persistent
- Keine Datei im CRM-Data-Bereich

---

# Verantwortlichkeiten

## 1. Benutzerverwaltung (Core)

Speicherort:
```
<crm_data>/core/users.json
```

Benutzer sind:

- Systemobjekte
- Nicht Modulobjekte
- Nicht Login-Objekte

Benutzerverwaltung wird später eigenes Modul.

Login darf Benutzer nicht besitzen oder definieren.

---

## 2. Login-Modul

Pfad:
```
/public_crm/login/
```

Enthält z. B.:

- index.php
- logout.php
- totp.php
- totp_setup.php

Login:

- prüft username
- prüft Passwort
- prüft optional TOTP
- startet Session
- setzt Session-Variablen

Login speichert keine Benutzerstruktur.

---

## 3. Session

Session ist Runtime-Zustand.

Enthält z. B.:

- user_id
- roles
- login_timestamp
- last_activity

Session ist:

- nicht versioniert
- nicht CRM-Data
- nicht persistent gespeichert

---

# TOTP (2FA)

TOTP-Konfiguration liegt im Benutzerobjekt:

```
auth.totp_enabled
auth.totp_secret
```

Beispiel:

```
{
  "id": "u_01",
  "username": "tbuss",
  "auth": {
    "password_hash": "...",
    "totp_enabled": true,
    "totp_secret": "..."
  }
}
```

TOTP ist Teil des Benutzers.
Nicht Teil des Login-Moduls.

---

# Sicherheitsprinzipien

1. Login greift ausschließlich auf:
   ```
   <crm_data>/core/users.json
   ```

2. Login darf keine Workflow-States setzen.

3. Login darf keine Event-Daten verändern.

4. Login ist technisch – nicht fachlich.

5. Benutzerverwaltung liegt nicht im Public-Bereich und nicht in einer Modulstruktur,
   sondern ausschließlich unter:
   ```
   <crm_data>/core/
   ```


---

# Trennungsprinzip

Benutzer = Core  
Login = Oberfläche  
Session = Runtime  
Events = Fachobjekte  

Keine Vermischung.

---

# Erweiterbarkeit

Diese Struktur erlaubt:

- Rollenmodell
- Rechte-Matrix
- API-Token
- Mandantenfähigkeit
- Audit-Logging

Ohne strukturellen Bruch.

---

# Fazit

Benutzer gehören in:

```
<crm_data>/core/
```

Login ist nur Eintrittspunkt.

Die CRM-Architektur bleibt stabil, wenn Login austauschbar ist.
