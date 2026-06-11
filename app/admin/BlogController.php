<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Sitemap;
use App\Core\Storage;
use App\Core\Slug;

final class BlogController {
  public function index(): void {
    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    usort($posts, fn(array $a, array $b) => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));

    $csrf = Csrf::token();
    $rows = '';

    foreach ($posts as $p) {
      $id = htmlspecialchars((string)($p['id'] ?? ''), ENT_QUOTES);
      $slug = htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES);
      $title = htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES);
      $status = (string)($p['status'] ?? 'draft');
      $statusLabel = htmlspecialchars($status, ENT_QUOTES);
      $rowClass = $status === 'draft' ? ' class="row-draggable row-draft"' : ' class="row-draggable"';

      $rows .= "<tr{$rowClass} data-id=\"{$id}\" draggable=\"true\"><td>{$title}<br><small>{$slug}</small></td><td>{$statusLabel}</td><td class=\"actions\">"
        . "<button class=\"btn row-move\" type=\"button\" data-dir=\"up\" aria-label=\"Beitrag {$title} nach oben verschieben\">▲</button>"
        . "<button class=\"btn row-move\" type=\"button\" data-dir=\"down\" aria-label=\"Beitrag {$title} nach unten verschieben\">▼</button>"
        . "<a class=\"btn\" href=\"/admin.php?a=blog_edit&id={$id}\" aria-label=\"Beitrag {$title} bearbeiten\">Bearbeiten</a>"
        . "<form method=\"post\" action=\"/admin.php?a=blog_duplicate\" class=\"form-inline\">"
        . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
        . "<input type=\"hidden\" name=\"id\" value=\"{$id}\">"
        . "<button class=\"btn\" type=\"submit\" aria-label=\"Beitrag {$title} duplizieren\">Duplizieren</button>"
        . "</form>"
        . "<form method=\"post\" action=\"/admin.php?a=blog_delete\" class=\"form-inline\" onsubmit=\"return confirm('Beitrag &quot;{$title}&quot; wirklich löschen?')\">"
        . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
        . "<input type=\"hidden\" name=\"id\" value=\"{$id}\">"
        . "<button class=\"btn\" type=\"submit\" aria-label=\"Beitrag {$title} löschen\">Löschen</button>"
        . "</form>"
        . "</td></tr>";
    }

    $content = "<div class=\"actions\">"
      . "<a class=\"btn primary\" href=\"/admin.php?a=blog_new\">+ Neuer Beitrag</a>"
      . "</div>"
      . "<table id=\"blog-table\" data-reorder-action=\"/admin.php?a=blog_reorder\" data-csrf=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
      . "<thead><tr><th>Beitrag</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>{$rows}</tbody></table>";

    $content .= PagesController::reorderScript();

    // Blog-Einstellungen
    $blogSlug = htmlspecialchars(trim((string)($idx['slug'] ?? 'blog')), ENT_QUOTES);
    $categoriesList = is_array($idx['categories'] ?? null) ? $idx['categories'] : [];
    $categoriesStr = htmlspecialchars(implode(', ', $categoriesList), ENT_QUOTES);

    $content .= "<div class=\"admin-section\">"
      . "<h3>Blog-Einstellungen</h3>"
      . "<form method=\"post\" action=\"/admin.php?a=blog_settings\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Blog-URL-Prefix<br><input type=\"text\" name=\"blog_slug\" value=\"{$blogSlug}\"></label>"
      . "<small>Bestimmt die URL der Beitraege: /<strong>{$blogSlug}</strong>/beitrag-slug</small></div>"
      . "<div><label>Kategorien (kommagetrennt)<br><input type=\"text\" name=\"categories\" value=\"{$categoriesStr}\"></label>"
      . "<small>z.B. Neuigkeiten, Tutorials, Updates</small></div>"
      . "</div>"
      . "<div class=\"actions-top\"><button class=\"btn primary\" type=\"submit\">Einstellungen speichern</button></div>"
      . "</form>"
      . "</div>";

    $this->render('Blog', $content);
  }

  public function settings(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=blog');
      exit;
    }

    Csrf::check();

    $blogSlug = trim((string)($_POST['blog_slug'] ?? 'blog'));
    $categoriesRaw = trim((string)($_POST['categories'] ?? ''));

    $blogSlug = Slug::slugify($blogSlug, 'blog');

    // Kategorien parsen
    $categories = [];
    if ($categoriesRaw !== '') {
      foreach (explode(',', $categoriesRaw) as $cat) {
        $cat = trim($cat);
        if ($cat !== '' && !in_array($cat, $categories, true)) {
          $categories[] = $cat;
        }
      }
    }

    $idx = Storage::readJson('content/blog.json');
    $idx['slug'] = $blogSlug;
    $idx['categories'] = $categories;
    Storage::writeJson('content/blog.json', $idx);

    $_SESSION['_flash'] = 'Blog-Einstellungen gespeichert.';
    header('Location: /admin.php?a=blog');
    exit;
  }

  public function create(): void {
    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $id = bin2hex(random_bytes(6));
    $title = 'Neuer Beitrag';
    $slugBase = 'beitrag';
    $slug = $slugBase;
    $n = 2;
    $slugs = array_map(fn($p) => (string)($p['slug'] ?? ''), $posts);
    while (in_array($slug, $slugs, true)) {
      $slug = $slugBase . '-' . $n;
      $n++;
    }

    $posts[] = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => 'draft',
      'image' => '',
      'description' => '',
      'category' => '',
      'order' => count($posts) + 1,
      'created' => date('c'),
      'updated' => date('c')
    ];

    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);

    $post = [
      'meta' => ['title' => $title, 'description' => ''],
      'content' => ['blocks' => [
        ['id' => 'b1', 'type' => 'heading', 'data' => ['level' => 1, 'text' => $title]],
        ['id' => 'b2', 'type' => 'text', 'data' => ['html' => '<p>Inhalt hier.</p>']]
      ]]
    ];

    Storage::writeJson('content/blog/' . $id . '.json', $post);
    Sitemap::write();

    header('Location: /admin.php?a=blog_edit&id=' . urlencode($id));
    exit;
  }

  public function edit(): void {
    $id = (string)($_GET['id'] ?? '');
    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $p = null;
    foreach ($posts as $it) {
      if (($it['id'] ?? '') === $id) { $p = $it; break; }
    }

    if (!$p) {
      http_response_code(404);
      echo "Not found";
      exit;
    }

    $data = Storage::readJson('content/blog/' . $id . '.json');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $json = $json === false ? "{}" : $json;

    $blogSlug = htmlspecialchars(trim((string)($idx['slug'] ?? 'blog')), ENT_QUOTES);
    $categories = is_array($idx['categories'] ?? null) ? $idx['categories'] : [];

    $title = htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES);
    $slug = htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES);
    $status = htmlspecialchars((string)($p['status'] ?? ''), ENT_QUOTES);
    $image = htmlspecialchars((string)($p['image'] ?? ''), ENT_QUOTES);
    $description = htmlspecialchars((string)($p['description'] ?? ''), ENT_QUOTES);
    $postCategory = trim((string)($p['category'] ?? ''));
    $pageRobots = htmlspecialchars(trim((string)($data['meta']['robots'] ?? '')), ENT_QUOTES);
    $mediaJson = htmlspecialchars(Slug::imageMediaJson(), ENT_QUOTES);

    $categoryOptions = '<option value="">— Keine —</option>';
    foreach ($categories as $cat) {
      $catE = htmlspecialchars((string)$cat, ENT_QUOTES);
      $sel = $postCategory === (string)$cat ? ' selected' : '';
      $categoryOptions .= '<option value="' . $catE . '"' . $sel . '>' . $catE . '</option>';
    }

    $flash = '';
    if (!empty($_SESSION['_flash'])) {
      $flash = (string)$_SESSION['_flash'];
      unset($_SESSION['_flash']);
    }

    $content = "<form method=\"post\" action=\"/admin.php?a=blog_save\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<input type=\"hidden\" name=\"id\" value=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">"
      . "<div class=\"actions\">"
      . "<a class=\"btn\" href=\"/admin.php?a=blog\">&larr; Zur Liste</a>"
      . "<button class=\"btn primary\" type=\"submit\">Speichern</button>"
      . "<a class=\"btn\" href=\"/{$blogSlug}/{$slug}\" target=\"_blank\" rel=\"noopener\">&Ouml;ffnen</a>"
      . "</div>"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Titel<br><input type=\"text\" name=\"title\" value=\"{$title}\"></label></div>"
      . "<div><label>Slug<br><input type=\"text\" name=\"slug\" value=\"{$slug}\"></label></div>"
      . "</div>"
      . "<div class=\"cols cols-3\">"
      . "<div><label>Status<br><select name=\"status\">"
      . "<option value=\"draft\"" . ($status === 'draft' ? ' selected' : '') . ">draft</option>"
      . "<option value=\"published\"" . ($status === 'published' ? ' selected' : '') . ">published</option>"
      . "</select></label></div>"
      . "<div><label>Kategorie<br><select name=\"category\">{$categoryOptions}</select></label></div>"
      . "<div><label>Robots<br><select name=\"robots\">"
      . "<option value=\"\"" . ($pageRobots === '' ? ' selected' : '') . ">index, follow (Standard)</option>"
      . "<option value=\"noindex\"" . ($pageRobots === 'noindex' ? ' selected' : '') . ">noindex</option>"
      . "<option value=\"noindex, nofollow\"" . ($pageRobots === 'noindex, nofollow' ? ' selected' : '') . ">noindex, nofollow</option>"
      . "</select></label></div>"
      . "</div>"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Bild (Pfad)<br><input type=\"text\" name=\"image\" value=\"{$image}\" data-field=\"blog-image\"></label>"
      . "<div style=\"margin:6px 0\"><button type=\"button\" class=\"btn\" onclick=\"(function(){var m=JSON.parse(document.querySelector('[data-block-editor-media]')?.getAttribute('data-block-editor-media')||'[]');if(!m.length){alert('Keine Bilder vorhanden');return;}var d=document.getElementById('blog-image-picker');if(d){d.remove();return;}d=document.createElement('div');d.id='blog-image-picker';d.style.cssText='display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin:8px 0;max-height:240px;overflow:auto;border:1px solid rgba(127,127,127,.2);border-radius:8px;padding:8px';m.forEach(function(i){var b=document.createElement('button');b.type='button';b.setAttribute('aria-label','Bild '+i.path+' auswaehlen');b.style.cssText='border:1px solid rgba(127,127,127,.15);border-radius:6px;padding:4px;background:transparent;cursor:pointer';b.innerHTML='<img src=&quot;'+i.path+'&quot; style=&quot;width:100%;aspect-ratio:1;object-fit:cover;display:block;border-radius:4px&quot;>';b.onclick=function(){document.querySelector('[name=image]').value=i.path;d.remove()};d.appendChild(b)});document.querySelector('[name=image]').parentNode.appendChild(d)})()\">Bild auswaehlen</button></div>"
      . "</div>"
      . "<div><label>Beschreibung<br><textarea name=\"description\" rows=\"2\">{$description}</textarea></label></div>"
      . "</div>"
      . "<div class=\"editor-switch\">"
      . "<button class=\"btn primary\" type=\"button\" data-be-mode=\"visual\">Block-Editor</button>"
      . "<button class=\"btn\" type=\"button\" data-be-mode=\"json\">JSON</button>"
      . "</div>"
      . "<p class=\"be-help\"><strong>Block-Editor</strong> <small>Heading, Text, Bild, Liste, Buttons, Spalten, HTML</small></p>"
      . "<div class=\"block-editor-shell is-active\" data-block-editor data-block-editor-title-selector=\"input[name='title']\" data-block-editor-media=\"{$mediaJson}\"></div>"
      . "<div class=\"json-editor-shell\" data-block-editor-json-wrap>"
      . "<p class=\"json-editor-label\"><strong>Post JSON</strong> <small>(Blocks + Meta)</small></p>"
      . "<textarea name=\"page_json\" data-block-editor-source>" . htmlspecialchars($json, ENT_QUOTES) . "</textarea>"
      . "</div>"
      . "</form>";

    $this->render('Blogbeitrag bearbeiten', $content, $flash);
  }

  public function save(): void {
    Csrf::check();

    $id = (string)($_POST['id'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $status = (string)($_POST['status'] ?? 'draft');
    $image = trim((string)($_POST['image'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $robots = trim((string)($_POST['robots'] ?? ''));
    $pageJson = (string)($_POST['page_json'] ?? '{}');

    if ($title === '') $title = 'Ohne Titel';
    if ($slug === '') $slug = $title;

    $slug = Slug::slugify($slug);

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $found = false;
    $slugs = [];

    foreach ($posts as $p) {
      if (($p['id'] ?? '') !== $id) $slugs[] = (string)($p['slug'] ?? '');
    }

    $base = $slug;
    $n = 2;
    while (in_array($slug, $slugs, true)) {
      $slug = $base . '-' . $n;
      $n++;
    }

    foreach ($posts as &$p) {
      if (($p['id'] ?? '') !== $id) continue;
      $p['title'] = $title;
      $p['slug'] = $slug;
      $p['status'] = ($status === 'published') ? 'published' : 'draft';
      $p['image'] = $image;
      $p['description'] = $description;
      $p['category'] = $category;
      $p['updated'] = date('c');
      $found = true;
      break;
    }
    unset($p);

    if (!$found) {
      http_response_code(404);
      echo "Not found";
      exit;
    }

    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);

    $decoded = json_decode($pageJson, true);
    if (!is_array($decoded)) $decoded = [];
    if (!is_array($decoded['meta'] ?? null)) $decoded['meta'] = [];
    $decoded['meta']['image'] = $image;
    $decoded['meta']['description'] = $description;
    if ($robots !== '') {
      $decoded['meta']['robots'] = $robots;
    } else {
      unset($decoded['meta']['robots']);
    }
    Storage::writeJson('content/blog/' . $id . '.json', $decoded);

    Sitemap::write();

    $_SESSION['_flash'] = 'Gespeichert.';
    header('Location: /admin.php?a=blog_edit&id=' . urlencode($id));
    exit;
  }

  public function delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=blog');
      exit;
    }

    Csrf::check();

    $id = (string)($_POST['id'] ?? '');
    if ($id === '') {
      header('Location: /admin.php?a=blog');
      exit;
    }

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];
    $posts = array_values(array_filter($posts, fn($p) => (string)($p['id'] ?? '') !== $id));
    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);

    $file = Storage::root() . '/content/blog/' . $id . '.json';
    if (is_file($file)) @unlink($file);

    Sitemap::write();

    header('Location: /admin.php?a=blog');
    exit;
  }

  public function duplicate(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=blog');
      exit;
    }

    Csrf::check();

    $sourceId = (string)($_POST['id'] ?? '');
    if ($sourceId === '') {
      header('Location: /admin.php?a=blog');
      exit;
    }

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $source = null;
    foreach ($posts as $p) {
      if (($p['id'] ?? '') === $sourceId) { $source = $p; break; }
    }

    if (!$source) {
      header('Location: /admin.php?a=blog');
      exit;
    }

    $newId = bin2hex(random_bytes(6));
    $newTitle = (string)($source['title'] ?? 'Kopie') . ' (Kopie)';
    $baseSlug = Slug::slugify($newTitle);
    $slugs = array_map(fn($p) => (string)($p['slug'] ?? ''), $posts);
    $slug = $baseSlug;
    $n = 2;
    while (in_array($slug, $slugs, true)) {
      $slug = $baseSlug . '-' . $n;
      $n++;
    }

    $posts[] = [
      'id' => $newId,
      'slug' => $slug,
      'title' => $newTitle,
      'status' => 'draft',
      'image' => (string)($source['image'] ?? ''),
      'description' => (string)($source['description'] ?? ''),
      'category' => (string)($source['category'] ?? ''),
      'order' => count($posts) + 1,
      'created' => date('c'),
      'updated' => date('c')
    ];

    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);

    $sourceData = Storage::readJson('content/blog/' . $sourceId . '.json');
    if (!is_array($sourceData)) {
      $sourceData = ['meta' => ['title' => $newTitle, 'description' => ''], 'content' => ['blocks' => []]];
    }
    if (isset($sourceData['meta'])) {
      $sourceData['meta']['title'] = $newTitle;
    }
    Storage::writeJson('content/blog/' . $newId . '.json', $sourceData);

    Sitemap::write();

    header('Location: /admin.php?a=blog_edit&id=' . urlencode($newId));
    exit;
  }

  public function reorder(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      echo json_encode(['ok' => false]);
      exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!empty($body['_csrf'])) {
      $_POST['_csrf'] = $body['_csrf'];
    }
    Csrf::check();

    $order = $body['order'] ?? null;

    if (!is_array($order)) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'error' => 'order fehlt']);
      exit;
    }

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $orderMap = [];
    foreach ($order as $i => $id) {
      $orderMap[(string)$id] = $i + 1;
    }

    foreach ($posts as &$p) {
      $pid = (string)($p['id'] ?? '');
      if (isset($orderMap[$pid])) {
        $p['order'] = $orderMap[$pid];
      }
    }
    unset($p);

    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);
    Sitemap::write();

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}
