<?php
declare(strict_types=1);

use App\Core\Csrf;

$csrf = Csrf::token();
$active = (string)($_GET['a'] ?? 'pages');

$adminNav = [
  ['label' => 'Seiten', 'href' => '/admin.php?a=pages', 'active' => in_array($active, ['pages', 'page_new', 'page_edit', 'page_save', 'page_delete'], true)],
  ['label' => 'Blog', 'href' => '/admin.php?a=blog', 'active' => in_array($active, ['blog', 'blog_new', 'blog_edit', 'blog_save', 'blog_delete'], true)],
  ['label' => 'Header/Footer', 'href' => '/admin.php?a=partials', 'active' => in_array($active, ['partials', 'partial_edit', 'partial_save'], true)],
  ['label' => 'Theme', 'href' => '/admin.php?a=theme', 'active' => in_array($active, ['theme'], true)],
  ['label' => 'Media', 'href' => '/admin.php?a=media', 'active' => in_array($active, ['media'], true)],
  ['label' => 'Einsendungen', 'href' => '/admin.php?a=forms', 'active' => in_array($active, ['forms', 'form_view', 'form_mark', 'form_delete', 'form_clear', 'form_export'], true)],
  ['label' => 'Benutzer', 'href' => '/admin.php?a=users', 'active' => in_array($active, ['users', 'user_new', 'user_edit', 'user_save', 'user_delete'], true)],
  ['label' => 'Einstellungen', 'href' => '/admin.php?a=settings', 'active' => in_array($active, ['settings', 'settings_export', 'settings_import_analyze', 'settings_import_run'], true)],
  ['label' => 'Frontend', 'href' => '/', 'active' => false, 'target' => '_blank'],
  ['label' => 'Logout', 'href' => '/admin.php?a=logout', 'active' => false],
];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title ?? 'Admin', ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/admin.css?v=3">
</head>
<body>
<header class="admin-header"><h1 class="admin-title">CMS Admin</h1><?php if (!empty($_SESSION['_admin_user'])): ?><span class="admin-user"><?= htmlspecialchars((string)$_SESSION['_admin_user'], ENT_QUOTES) ?></span><?php endif; ?></header>

<nav class="admin-nav" aria-label="Admin-Menü">
  <ul class="admin-nav-list">
    <?php foreach ($adminNav as $item): ?>
      <li>
        <a
          href="<?= htmlspecialchars($item['href'], ENT_QUOTES) ?>"
          <?= !empty($item['active']) ? ' aria-current="page"' : '' ?>
          <?= !empty($item['target']) ? ' target="_blank" rel="noopener"' : '' ?>
        ><?= htmlspecialchars($item['label'], ENT_QUOTES) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>

<main>
<?php if (!empty($flash)) : ?><p class="flash-msg" role="status" aria-live="polite"><?= htmlspecialchars($flash, ENT_QUOTES) ?></p><?php endif; ?>
<?= $content ?? '' ?>
</main>

<div class="be-modal" id="be-media-modal" role="dialog" aria-modal="true" aria-labelledby="be-media-modal-title" aria-hidden="true">
  <div class="be-modal-card">
    <div class="be-modal-head">
      <strong id="be-media-modal-title">Bilder auswählen</strong>
      <button class="be-icon-btn" type="button" data-be-modal-close aria-label="Bildauswahl schliessen">✕</button>
    </div>
    <div class="be-media-grid" data-be-media-grid></div>
  </div>
</div>

<script src="/assets/js/admin-block-editor.js" defer></script>
</body>
</html>