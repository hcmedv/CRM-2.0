<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/bericht_einsatz/index.php
 * Zweck:
 * - Bericht Einsatz (Leistungsnachweis) als Modul-Seite
 * - 5 Cards:
 *   1) Auftraggeber
 *   2) Datensicherung
 *   3) Auftragsbeschreibung & Allgemeines (An-/Abfahrt + KM)
 *   4) Einsatz, Tätigkeiten & Material (Zeilen, Add/Remove)
 *   5) Abnahme, Freigabe & Kundenunterschrift
 */

define('CRM_PAGE_TITLE',   'Bericht Einsatz');
define('CRM_PAGE_ACTIVE',  'vorgang');
define('CRM_SUBNAV_ACTIVE','bericht_einsatz');

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Mitarbeiter Default (lieber „voller Name“, falls vorhanden – sonst Fallback)
$defaultMitarbeiter = '';
if (!empty($_SESSION['crm_user']['display_name'])) {
    $defaultMitarbeiter = (string)$_SESSION['crm_user']['display_name'];
} elseif (!empty($_SERVER['PHP_AUTH_USER'])) {
    $defaultMitarbeiter = (string)$_SERVER['PHP_AUTH_USER'];
} elseif (!empty($_SERVER['REMOTE_USER'])) {
    $defaultMitarbeiter = (string)$_SERVER['REMOTE_USER'];
}
?>

<link rel="stylesheet" href="/bericht_einsatz/assets/crm_bericht_einsatz.css?v=1">
<script defer src="/_inc/assets/crm_autofill_customers.js?v=1"></script>
<script defer src="/bericht_einsatz/assets/crm_bericht_einsatz.js?v=1"></script>

<section class="card card--wide">
  <div class="card__title">Bericht Einsatz</div>
  <div class="card__body">

    <form id="frm_be" method="post" action="./send.php" autocomplete="off">

      <input type="hidden" id="default_mitarbeiter" value="<?= h($defaultMitarbeiter); ?>">

      <!-- 1) Auftraggeber -->
      <section class="card card--wide be_card" id="sec_kunde">
        <div class="card__title">Auftraggeber</div>
        <div class="card__body">

          <div class="be_row">
            <div class="be_col be_col--grow autocomplete">
              <label class="label" for="be_kunde_firma">Firma (Kunde) <span class="req">*</span></label>

              <div class="be_customerline">
                <input type="hidden" id="be_kunde_id" name="kunde_id" value="">
                <input type="hidden" id="be_kunden_nummer" name="kunden_nummer" value="">

                <button class="crm-fieldchip is-clickable" type="button" id="be_customer_chip" title="Kunde löschen / ändern">Unbekannt</button>

                <input class="input" id="be_kunde_firma" name="kunde_firma" type="text" placeholder="Firma suchen …" autocomplete="off">
              </div>

              <div id="be_customer_suggest" class="crm-suggest" hidden></div>
            </div>
          </div>

          <div class="be_row"><br>
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_inhaber">Inhaber / Geschäftsführer</label>
              <input class="input" id="be_kunde_inhaber" name="kunde_inhaber" type="text">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_strasse">Straße</label>
              <input class="input" id="be_kunde_strasse" name="kunde_strasse" type="text">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_plzort">PLZ / Ort</label>
              <input class="input" id="be_kunde_plzort" name="kunde_plzort" type="text">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_telefon">Telefon</label>
              <input class="input" id="be_kunde_telefon" name="kunde_telefon" type="text">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_ansprechpartner">Ansprechpartner <span class="req">*</span></label>
              <input class="input" id="be_kunde_ansprechpartner" name="kunde_ansprechpartner" type="text">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_auftragsmail">Auftrags-Mail <span class="req">*</span></label>
              <input class="input" id="be_kunde_auftragsmail" name="kunde_auftragsmail" type="email">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_kunde_emails">E-Mails (weitere)</label>
              <input class="input" id="be_kunde_emails" name="kunde_emails" type="text" placeholder="optional, mehrere mit Komma getrennt">
            </div>
          </div>

        </div>
      </section>

      <!-- 2) Datensicherung -->
      <section class="card card--wide be_card" id="sec_dasi">
        <div class="card__title">Datensicherung</div>
        <div class="card__body">

          <div class="be_row">
            <div class="be_col be_col--grow">
              <div class="muted">
                Bestätigung zur Datensicherung Ihrer Programme &amp; Daten.
              </div>

              <div class="be_choices">
                <label class="be_chk">
                  <input type="checkbox" id="be_dasi_ok" name="dasi_ok" value="1">
                  Datensicherung – aktuell, vorhanden &amp; geprüft.
                </label>

                <label class="be_chk">
                  <input type="checkbox" id="be_dasi_none" name="dasi_none" value="1">
                  Datensicherung – nicht vorhanden! Auftrag ausführen.
                </label>

                <label class="be_chk">
                  <input type="checkbox" id="be_dasi_before" name="dasi_before" value="1">
                  Datensicherung – vor Auftragsbeginn ausführen.
                </label>

                <input type="hidden" id="be_dasi_status" name="dasi_status" value="">
              </div>
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_dasi_hinweise">Hinweise (optional)</label>
              <textarea class="input be_textarea" id="be_dasi_hinweise" name="dasi_hinweise" placeholder="z. B. Art / Ort / Datum der Sicherung, Besonderheiten"></textarea>
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_sig_dasi_name">Name (Unterzeichner) <span class="req">*</span></label>
              <input class="input" id="be_sig_dasi_name" name="sig_dasi_name" type="text" placeholder="Unterzeichner (Datensicherung)">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label">Unterschrift Datensicherung (Auftraggeber) <span class="req">*</span></label>

              <canvas class="be_sig" id="be_sig_dasi" width="980" height="260"></canvas>
              <div class="be_actions">
                <input type="hidden" id="be_sig_dasi_data" name="sig_dasi_data" value="">
                <button class="crm-btn crm-btn--sm" type="button" id="be_btn_sig_dasi_clear">Unterschrift löschen</button>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- 3) Auftragsbeschreibung & Allgemeines -->
      <section class="card card--wide be_card" id="sec_allg">
        <div class="card__title">Auftragsbeschreibung &amp; Allgemeines</div>
        <div class="card__body">

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_title">Titel (Pflicht) <span class="req">*</span></label>
              <input class="input" id="be_title" name="title" type="text" placeholder="z. B. Router getauscht">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_beschreibung">Auftragsbeschreibung <span class="req">*</span></label>
              <textarea class="input be_textarea" id="be_beschreibung" name="beschreibung" placeholder="Was ist der Auftrag / Problem / Ziel?"></textarea>
            </div>
          </div>

          <div class="be_meta be_meta--allg">
            <div class="be_col">
              <label class="label" for="be_date">Datum</label>
              <input class="input" id="be_date" name="date" type="date">
            </div>

            <div class="be_col">
              <label class="label" for="be_drive_h">An-/Abfahrt (h)</label>
              <input class="input" id="be_drive_h" name="drive_h" type="text" inputmode="decimal" placeholder="h">
            </div>

            <div class="be_col">
              <label class="label" for="be_drive_km">Kilometer</label>
              <input class="input" id="be_drive_km" name="drive_km" type="text" inputmode="numeric" placeholder="km">
            </div>

            <div class="be_col">
              <label class="label" for="be_employee">Mitarbeiter</label>
              <input class="input" id="be_employee" name="employee" type="text" placeholder="Mitarbeiter">
            </div>
          </div>

        </div>
      </section>

      <!-- 4) Einsatz, Tätigkeiten & Material -->
      <section class="card card--wide be_card" id="sec_work">
        <div class="card__title">Einsatz, Tätigkeiten &amp; Material</div>
        <div class="card__body">

          <!-- Einsatzzeiten -->
          <div class="be_subtitle">Einsatzzeiten</div>

          <div class="be_e_head">
            <div>Datum</div>
            <div>Beginn</div>
            <div>Ende</div>
            <div>Einsatzzeit (min)</div>
            <div>Mitarbeiter</div>
            <div></div>
          </div>

          <div id="be_einsatz_list" class="be_list"></div>

          <div class="be_actions be_actions--sum">
            <div class="muted">
              Gesamt: <span id="be_sum_minutes">0</span> min <span id="be_sum_hours" class="be_sum_hours"></span>
            </div>
            <div class="be_spacer"></div>
            <button class="crm-btn crm-btn--icon crm-btn--primary"
                    type="button" id="be_btn_add_einsatz" aria-label="Einsatzzeile hinzufügen">+</button>
          </div>

          <hr class="be_sep">

          <!-- Tätigkeiten -->
          <div class="be_subtitle">Tätigkeiten</div>

          <div id="be_tasks_list" class="be_list"></div>

          <div class="be_actions">
            <div class="be_spacer"></div>
            <button class="crm-btn crm-btn--icon crm-btn--primary"
                    type="button" id="be_btn_add_task" aria-label="Tätigkeit hinzufügen">+</button>
          </div>

          <hr class="be_sep">

          <!-- Material -->
          <div class="be_subtitle">Material</div>

          <div id="be_material_list" class="be_list"></div>

          <div class="be_actions">
            <div class="be_spacer"></div>
            <button class="crm-btn crm-btn--icon crm-btn--primary"
                    type="button" id="be_btn_add_material" aria-label="Materialzeile hinzufügen">+</button>
          </div>

        </div>
      </section>

      <!-- 5) Abnahme -->
      <section class="card card--wide be_card" id="sec_abnahme">
        <div class="card__title">Abnahme, Freigabe und Kundenunterschrift</div>
        <div class="card__body">

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_ab_bemerkung">Bemerkung</label>
              <textarea class="input be_textarea" id="be_ab_bemerkung" name="ab_bemerkung" placeholder="Optional"></textarea>
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <div class="be_choices">
                <label class="be_chk">
                  <input type="checkbox" id="be_ab_done" name="ab_done" value="1">
                  Auftrag - <span class="be_abnahme">beendet</span>, Dienstleistung <span class="be_abnahme">überprüft und abgeschlossen.</span> 
                </label>

                <label class="be_chk">
                  <input type="checkbox" id="be_ab_open" name="ab_open" value="1">
                  Auftrag - offen, weitere Dienstleistungen notwendig. 
                </label>
              </div>

              <input type="hidden" id="be_ab_status" name="ab_status" value="">
            </div>
          </div>

          <!-- WICHTIG: IDs wieder auf "main", damit dein JS passt -->
          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label" for="be_sig_main_name">Name (Unterzeichner) <span class="req">*</span></label>
              <input class="input" id="be_sig_main_name" name="sig_main_name" type="text" placeholder="Unterzeichner (Kunde)">
            </div>
          </div>

          <div class="be_row">
            <div class="be_col be_col--grow">
              <label class="label">Unterschrift (Kunde) <span class="req">*</span></label>

              <canvas class="be_sig" id="be_sig_main" width="980" height="260"></canvas>
              <input type="hidden" id="be_sig_main_data" name="sig_main_data" value="">
            </div>
          </div>

          <div class="be_actions">
            <button class="crm-btn" type="button" id="be_btn_sig_main_clear">Unterschrift löschen</button>
            <button class="crm-btn crm-btn--primary" type="submit" name="do" value="doc">Dokumentieren</button>
            <button class="crm-btn" type="submit" name="do" value="pdf">Drucken / PDF</button>
            <button class="crm-btn crm-btn--danger" type="reset" id="be_btn_reset">Änderungen verwerfen</button>

            <div class="be_spacer"></div>
            <div class="muted" id="be_status">Bereit</div>
          </div>

        </div>
      </section>

    </form>

  </div>
</section>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
