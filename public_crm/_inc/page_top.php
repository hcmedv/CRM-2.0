<?php
declare(strict_types=1);

/*
 * Datei: /public_crm/_inc/page_top.php
 * Zweck:
 * - Header + Top-Navigation (Skeleton)
 * - Aktiver Menüpunkt via CRM_PAGE_ACTIVE
 */

if (!defined('CRM_ROOT')) {
    require_once __DIR__ . '/bootstrap.php';
}

$title   = defined('CRM_PAGE_TITLE') ? (string)CRM_PAGE_TITLE : 'CRM';
$appName = (string)CRM_CFG('app_name', 'CRM');
$appVer  = (string)CRM_CFG('app_version', '');
$active  = defined('CRM_PAGE_ACTIVE') ? (string)CRM_PAGE_ACTIVE : 'start';

$isLoggedIn = isset($_SESSION['crm_user']) && is_array($_SESSION['crm_user']);
$userName   = $isLoggedIn ? (string)($_SESSION['crm_user']['name'] ?? $_SESSION['crm_user']['user'] ?? '') : '';

$nav = CRM_CFG('nav', []);
if (!is_array($nav)) { $nav = []; }
if (count($nav) === 0) { $nav = [['key' => 'start', 'label' => 'Start', 'href' => '/']]; }

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>

<link rel="stylesheet" href="/_inc/crm.css">

<?php if (defined('CRM_DEBUG') && CRM_DEBUG): ?>
<meta name="robots" content="noindex,nofollow">
<?php endif; ?>
</head>
<body>

<div class="app">
<header class="app-header">

  <div class="app-header__row">
    <div>
      <div class="app-header__title"><?= htmlspecialchars($appName, ENT_QUOTES) ?></div>
      <?php if ($appVer !== ''): ?>
        <div class="app-header__meta"><?= htmlspecialchars($appVer, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($isLoggedIn): ?>
      <div class="app-header__right">
        <?php if ($userName !== ''): ?>
          <div class="app-header__user"><?= htmlspecialchars($userName, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <a class="app-header__logout" href="/logout">Logout</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($isLoggedIn): ?>
    <nav class="topnav-block">
      <div class="topnav-block__inner">
        <?php foreach ($nav as $item): ?>
          <?php
            $cls = 'topnav-block__item';
            if ($item['key'] === $active) $cls .= ' topnav-block__item--active';
          ?>
          <a class="<?= $cls ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($item['label'], ENT_QUOTES) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </nav>

    <?php if (defined('CRM_SUBNAV_HTML') && is_string(CRM_SUBNAV_HTML) && CRM_SUBNAV_HTML !== ''): ?>
      <div class="subnav-block">
        <div class="subnav-block__inner">
          <?= CRM_SUBNAV_HTML ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</header>

<main class="app-main">
