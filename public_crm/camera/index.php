<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/camera/index.php
 * Zweck:
 * - Foto-Dokumentation (Kamera) als Modul-Seite
 * - 3 Bereiche (Cards): Kunde / Bilder / Aktion
 * - Vorschau-Canvas entfernt → Preview per Lightbox beim Klick auf Thumbnail
 */

$MOD = 'camera';

define('CRM_PAGE_TITLE', 'Foto-Dokumentation');
define('CRM_PAGE_ACTIVE', 'vorgang');          // Topnav bleibt auf „Vorgang“
define('CRM_SUBNAV_ACTIVE', 'camera');         // Subnav-Chip aktiv

require_once __DIR__ . '/../_inc/bootstrap.php';
require_once CRM_ROOT . '/_inc/auth.php';
CRM_Auth_RequireLogin();

require_once CRM_ROOT . '/_inc/page_top.php';
?>

<section class="card card--wide">
  <div class="card__title">Foto-Dokumentation</div>
  <div class="card__body">

    <!-- 1) Kunde -->
    <section class="card card--wide cam_card">
      <div class="card__title">Kunde</div>
      <div class="card__body">

        <!-- =======================================================================================
            KUNDE / VORGANG (Autocomplete)
            DOM-IDs:
            - cam_customer_chip
            - cam_customer_q
            - cam_customer_number
            - cam_customer_source
            - cam_customer_id
            - cam_customer_preview
            - cam_customer_suggest
        ======================================================================================= -->
        <div class="cam_row">
          <div class="cam_col cam_col--grow autocomplete">

            <label class="label" for="cam_customer_q">Kunde / Vorgang</label>

            <div class="cam_customerline">
              <button class="crm-fieldchip" type="button" id="cam_customer_chip" title="Kunde löschen / ändern">Unbekannt</button>

              <input class="input" id="cam_customer_q" type="text" placeholder="Kunde suchen …" autocomplete="off">

              <input type="hidden" id="cam_customer_number" name="customer_number" value="">
              <input type="hidden" id="cam_customer_source" name="customer_source" value="">
              <input type="hidden" id="cam_customer_id" name="customer_id" value="">
            </div>

            <div class="events-muted" id="cam_customer_preview" style="margin-top:6px;"></div>
            <div id="cam_customer_suggest" class="suggest" hidden></div>
          </div>
        </div>

        <div class="cam_row" style="margin-top:10px;">
          <div class="cam_col cam_col--grow">
            <label class="label" for="cam_title">Titel (Pflicht)</label>
            <input class="input" id="cam_title" type="text" placeholder="z. B. Router getauscht">
          </div>
        </div>


      </div>
    </section>

    <!-- 2) Bilder -->
    <section class="card card--wide cam_card">
      <div class="card__title">Bilder</div>
      <div class="card__body">

        <label class="label">Live</label>
        <video id="cam_video" autoplay playsinline muted></video>

        <div class="cam_actions">

          <select class="input cam_res" id="cam_res">
            <option value="1280x720" selected>1280×720 (HD)</option>
            <option value="1920x1080">1920×1080 (FullHD)</option>
            <option value="2560x1440">2560×1440 (QHD)</option>
          </select>

          <button class="crm-btn" type="button" id="cam_start">Start</button>
          <button class="crm-btn crm-btn-primary" type="button" id="cam_snap" disabled>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Aufnahme&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</button>
          <button class="crm-btn" type="button" id="cam_stop" disabled>Stop</button>
        </div>

        <div class="muted" id="cam_status">Kamera: aus</div>

      </div>
    </section>

    <!-- 3) Aktion -->
    <section class="card card--wide cam_card">
      <div class="card__title">Aktion</div>
      <div class="card__body">

        <div class="cam_actions cam_actions--top">
          <button class="crm-btn crm-btn-pPrimary" type="button" id="cam_doc" disabled>Dokumentieren</button>
          <button class="crm-btn" type="button" id="cam_upload" disabled>Hochladen</button>
          <button class="crm-btn btnDanger" type="button" id="cam_clear" disabled>Verwerfen</button>

          <div class="cam_spacer"></div>
          <div class="muted"><span id="cam_queue_info">Fotos: 0</span></div>
        </div>

        <div id="cam_queue" class="cam_queue"></div>

        <!-- Lightbox -->
        <div class="cam_lightbox" id="cam_lightbox" hidden>
          <div class="cam_lightbox__backdrop" data-close></div>
          <div class="cam_lightbox__panel" role="dialog" aria-modal="true">
            <div class="cam_lightbox__head">
              <div class="cam_lightbox__title" id="cam_lightbox_title">Vorschau</div>
              <button class="crm-fieldchip btn--xs" type="button" data-close>Schließen</button>
            </div>
            <div class="cam_lightbox__body">
              <img id="cam_lightbox_img" alt="">
            </div>
          </div>
        </div>

      </div>
    </section>

  </div>
</section>

<?php require_once CRM_ROOT . '/_inc/page_bottom.php'; ?>
