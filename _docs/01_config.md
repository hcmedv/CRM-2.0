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

## Deployment-Hinweis (Shared Hosting)
Beispiel Pfade (können je Host abweichen):

- Webroot CRM: /www/htdocs/.../crm.hcmedv.de/public_crm/
- Project Root: /www/htdocs/.../crm.hcmedv.de/
  - data/, log/, tmp/ liegen hier (außerhalb Webroot)
  
## Secrets & Auth

Secrets und Authentifizierungsdaten sind **strikt getrennt** von der normalen
Konfiguration und werden **nicht versioniert**.

### Verzeichnis
- `/_auth/`

### Inhalte
- `secrets_crm.php`
  - API-Tokens
  - Passwörter
  - externe IDs (z. B. M365 Folder-ID)
- `secret_htaccess.php`
  - Tokens / Hashes für htaccess-gestützte Absicherung

### Regeln
- `/_auth/*` ist **nicht Teil von Git**
- `/config/*` enthält **keine Secrets**
- Zugriff auf Secrets erfolgt ausschließlich über eine zentrale Lade-Logik
- Kein Direktzugriff auf Secrets aus Modulen

### Ziel
- Klare Sicherheitsgrenze
- Saubere Trennung von Konfiguration und Geheimnissen
- Einfache Rotation von Secrets ohne Codeänderung
