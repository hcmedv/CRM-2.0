# 320_modules_create.md


# Neues Modul anlegen (CRM v2)

## Ziel
Ein neues Modul (z. B. `vorgang`) so anlegen, dass:
- es über `settings_crm.php` aktiviert wird,
- es in der Navigation erscheint,
- die Seite(n) geladen werden können,
- optionale Modul-Assets (`css/js`) automatisch eingebunden werden,
- Modul-spezifische Settings/Secrets per Bootstrap automatisch geladen werden.

---

## 1) Modul in `config/settings_crm.php` aktivieren

### 1.1 Module-Flag setzen
In `config/settings_crm.php`:

```php
'modules' => [
  // ...
  'vorgang' => true,
],

1.2 Navigation erweitern

In config/settings_crm.php:


'nav' => [
  ['key' => 'start',   'label' => 'Start',   'href' => '/'],
  ['key' => 'vorgang', 'label' => 'Vorgang', 'href' => '/vorgang/'],
  // ...
],

Hinweis: key muss zu CRM_PAGE_ACTIVE passen.

2) Modul-Settings-Datei anlegen

Pfad:

config/<modul>/settings_<modul>.php

Beispiel config/vorgang/settings_vorgang.php:

<?php
declare(strict_types=1);

return [
  'vorgang' => [
    'debug' => true,

    // Optional: Asset-Liste (wenn mehr als 1 css/js benötigt wird)
    // Wenn nicht gesetzt: Fallback auf /vorgang/assets/crm_vorgang.css|js (falls vorhanden)
    'assets' => [
      'css' => [
        'assets/crm_vorgang.css',
      ],
      'js' => [
        'assets/crm_vorgang.js',
      ],
    ],
  ],
];


3) Modul-Secrets-Datei anlegen (optional)

Pfad:

config/<modul>/secrets_<modul>.php

Wichtig: immer doppelt gekapselt (outer key = Modulname).

Beispiel config/vorgang/secrets_vorgang.php:

<?php
declare(strict_types=1);

return [
  'vorgang' => [
    // 'api_token' => '...',
  ],
];

4) Modul-Webroot / Seitenstruktur anlegen

Empfohlene Struktur:

public_crm/
  vorgang/
    index.php
    assets/
      crm_vorgang.css
      crm_vorgang.js

public_crm/vorgang/index.php (Basis-Seite)
<?php
declare(strict_types=1);

$MOD = 'vorgang';

define('CRM_PAGE_TITLE',  'Vorgang');
define('CRM_PAGE_ACTIVE', 'vorgang');
define('CRM_SUBNAV_HTML', '<a class="subnav__chip subnav__chip--active" href="#">Übersicht</a>');

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';
?>

<div class="grid grid--start">

  <section class="card">
    <div class="card__title">Vorgänge</div>
    <div class="card__body"></div>
  </section>

  <section class="card">
    <div class="card__title">Dokumente</div>
    <div class="card__body"></div>
  </section>

  <section class="card">
    <div class="card__title">Stammdaten</div>
    <div class="card__body"></div>
  </section>

  <section class="card card--wide">
    <div class="card__title">Status</div>
    <div class="card__body"></div>
  </section>

</div>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>


Wichtig:

$MOD = 'vorgang'; muss vor page_top.php gesetzt sein, damit Modul-Assets eingebunden werden.

CRM_PAGE_ACTIVE muss dem nav.key entsprechen.

5) Modul-Assets anlegen (leer reicht)
5.1 public_crm/vorgang/assets/crm_vorgang.css
/* Modul: vorgang */

5.2 public_crm/vorgang/assets/crm_vorgang.js
// Modul: vorgang


Wenn du keine Dateien anlegst, wird auch nichts eingebunden (Fallback prüft is_file(...)).

6) Modul-Datenpfade (Konvention)

Global in settings_crm.php:

'paths' => [
  'data' => $ROOT . '/data',
  'log'  => $ROOT . '/log',
  'tmp'  => $ROOT . '/tmp',
],


Konvention pro Modul:

CRM_MOD_PATH('<modul>', 'data') → <paths.data>/<modul>

Beispiel: CRM_MOD_PATH('vorgang','data') → /data/vorgang

In Modul-Settings daher nur Dateinamen speichern, nicht absolute Pfade.

7) Checkliste (Minimal)

 config/settings_crm.php: Modul in modules aktiv

 config/settings_crm.php: Nav-Eintrag vorhanden

 config/<modul>/settings_<modul>.php vorhanden (return ['<modul>'=>...])

 public_crm/<modul>/index.php vorhanden

 $MOD = '<modul>'; in der Seite gesetzt

 optional: public_crm/<modul>/assets/crm_<modul>.css|js vorhanden

 optional: config/<modul>/secrets_<modul>.php (wrapped keys)
 