<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Sitemap;
use App\Core\Storage;
use App\Core\Slug;

final class PagesController {
  public function index(): void {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    // Nach nav.order sortieren
    usort($pages, fn(array $a, array $b) => ((int)($a['nav']['order'] ?? 0)) <=> ((int)($b['nav']['order'] ?? 0)));

    // Hierarchie aufbauen
    $byParent = [];
    foreach ($pages as $p) {
      $parent = (string)($p['nav']['parent'] ?? '');
      $parent = $parent !== '' ? $parent : '__root__';
      $byParent[$parent][] = $p;
    }

    $csrf = Csrf::token();
    $rows = $this->renderPageRows($byParent, '__root__', 0, $csrf);

    $content = "<div class=\"actions\">"
      . "<a class=\"btn primary\" href=\"/admin.php?a=page_new\">+ Neue Seite</a>"
      . "</div>"
      . "<table id=\"pages-table\" data-reorder-action=\"/admin.php?a=page_reorder\" data-csrf=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
      . "<thead><tr><th>Seite</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>{$rows}</tbody></table>";

    $content .= self::reorderScript();

    $this->render('Seiten', $content);
  }

  /**
   * Gemeinsames Sortier-Script fuer Seiten- und Blog-Liste:
   * Drag-and-Drop plus Hoch/Runter-Buttons als Tastatur-Alternative.
   * Wirkt auf alle Tabellen mit data-reorder-action.
   */
  public static function reorderScript(): string {
    return <<<'DRAGSCRIPT'
<script>
(function() {
  document.querySelectorAll('table[data-reorder-action]').forEach(function(table) {
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var action = table.dataset.reorderAction;
    var csrf = table.dataset.csrf || '';
    var dragRow = null;

    function sendOrder() {
      var rows = tbody.querySelectorAll('tr[data-id]');
      var order = Array.from(rows).map(function(r) { return r.dataset.id; });
      fetch(action, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ _csrf: csrf, order: order })
      }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
          tbody.style.outline = '2px solid #2e7d32';
          setTimeout(function() { tbody.style.outline = ''; }, 800);
        }
      });
    }

    tbody.addEventListener('dragstart', function(e) {
      dragRow = e.target.closest('tr');
      if (dragRow) {
        dragRow.style.opacity = '.4';
        e.dataTransfer.effectAllowed = 'move';
      }
    });

    tbody.addEventListener('dragover', function(e) {
      e.preventDefault();
      var target = e.target.closest('tr');
      if (target && target !== dragRow) {
        var rect = target.getBoundingClientRect();
        var mid = rect.top + rect.height / 2;
        if (e.clientY < mid) {
          tbody.insertBefore(dragRow, target);
        } else {
          tbody.insertBefore(dragRow, target.nextSibling);
        }
      }
    });

    tbody.addEventListener('dragend', function() {
      if (dragRow) dragRow.style.opacity = '';
      dragRow = null;
      sendOrder();
    });

    // Tastatur-Alternative: Hoch/Runter-Buttons
    tbody.addEventListener('click', function(e) {
      var btn = e.target.closest('.row-move');
      if (!btn) return;
      var row = btn.closest('tr');
      if (!row) return;
      if (btn.dataset.dir === 'up' && row.previousElementSibling) {
        tbody.insertBefore(row, row.previousElementSibling);
      } else if (btn.dataset.dir === 'down' && row.nextElementSibling) {
        tbody.insertBefore(row.nextElementSibling, row);
      } else {
        return;
      }
      btn.focus();
      sendOrder();
    });
  });
})();
</script>
DRAGSCRIPT;
  }

  private function renderPageRows(array $byParent, string $parentKey, int $depth, string $csrf): string {
    $items = $byParent[$parentKey] ?? [];
    $rows = '';

    foreach ($items as $p) {
      $id = htmlspecialchars((string)($p['id'] ?? ''), ENT_QUOTES);
      $slug = htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES);
      $title = htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES);
      $status = (string)($p['status'] ?? 'draft');
      $statusLabel = htmlspecialchars($status, ENT_QUOTES);
      $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
      $prefix = $depth > 0 ? '└ ' : '';
      $rowClass = $status === 'draft' ? ' class="row-draggable row-draft"' : ' class="row-draggable"';

      $rows .= "<tr{$rowClass} data-id=\"{$id}\" draggable=\"true\"><td>{$indent}{$prefix}{$title}<br>{$indent}<small>{$slug}</small></td><td>{$statusLabel}</td><td class=\"actions\">"
        . "<button class=\"btn row-move\" type=\"button\" data-dir=\"up\" aria-label=\"Seite {$title} nach oben verschieben\">▲</button>"
        . "<button class=\"btn row-move\" type=\"button\" data-dir=\"down\" aria-label=\"Seite {$title} nach unten verschieben\">▼</button>"
        . "<a class=\"btn\" href=\"/admin.php?a=page_edit&id={$id}\" aria-label=\"Seite {$title} bearbeiten\">Bearbeiten</a>"
        . "<form method=\"post\" action=\"/admin.php?a=page_duplicate\" class=\"form-inline\">"
        . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
        . "<input type=\"hidden\" name=\"id\" value=\"{$id}\">"
        . "<button class=\"btn\" type=\"submit\" aria-label=\"Seite {$title} duplizieren\">Duplizieren</button>"
        . "</form>"
        . "<form method=\"post\" action=\"/admin.php?a=page_delete\" class=\"form-inline\" onsubmit=\"return confirm('Seite &quot;{$title}&quot; wirklich löschen?')\">"
        . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars($csrf, ENT_QUOTES) . "\">"
        . "<input type=\"hidden\" name=\"id\" value=\"{$id}\">"
        . "<button class=\"btn\" type=\"submit\" aria-label=\"Seite {$title} löschen\">Löschen</button>"
        . "</form>"
        . "</td></tr>";

      // Unterseiten rekursiv
      $rows .= $this->renderPageRows($byParent, (string)($p['id'] ?? ''), $depth + 1, $csrf);
    }

    return $rows;
  }

  public function create(): void {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $id = bin2hex(random_bytes(6));
    $title = 'Neue Seite';
    $slugBase = 'seite';
    $slug = $slugBase;
    $n = 2;
    $slugs = array_map(fn($p) => (string)($p['slug'] ?? ''), $pages);
    while (in_array($slug, $slugs, true)) {
      $slug = $slugBase . '-' . $n;
      $n++;
    }

    $pages[] = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => 'draft',
      'nav' => [
        'show' => true,
        'order' => count($pages) + 1,
        'label' => null,
        'parent' => null
      ],
      'created' => date('c'),
      'updated' => date('c')
    ];

    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);

    $page = [
      'meta' => ['title' => $title, 'description' => ''],
      'content' => ['blocks' => [
        ['id' => 'b1', 'type' => 'heading', 'data' => ['level' => 1, 'text' => $title]],
        ['id' => 'b2', 'type' => 'text', 'data' => ['html' => '<p>Inhalt hier.</p>']]
      ]]
    ];

    Storage::writeJson('content/pages/' . $id . '.json', $page);
    Sitemap::write();

    header('Location: /admin.php?a=page_edit&id=' . urlencode($id));
    exit;
  }

  public function edit(): void {
    $id = (string)($_GET['id'] ?? '');
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $p = null;
    foreach ($pages as $it) {
      if (($it['id'] ?? '') === $id) {
        $p = $it;
        break;
      }
    }

    if (!$p) {
      http_response_code(404);
      echo "Not found";
      exit;
    }

    $data = Storage::readJson('content/pages/' . $id . '.json');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $json = $json === false ? "{}" : $json;

    $title = htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES);
    $slug = htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES);
    $status = htmlspecialchars((string)($p['status'] ?? ''), ENT_QUOTES);
    $navShow = (bool)($p['nav']['show'] ?? true);
    $navOrder = (int)($p['nav']['order'] ?? 0);
    $navLabel = htmlspecialchars((string)($p['nav']['label'] ?? ''), ENT_QUOTES);
    $navParent = (string)($p['nav']['parent'] ?? '');
    $pageRobots = htmlspecialchars(trim((string)($data['meta']['robots'] ?? '')), ENT_QUOTES);
    $mediaJson = htmlspecialchars(Slug::imageMediaJson(), ENT_QUOTES);

    $parentOptions = '<option value="">— Hauptebene —</option>';
    foreach ($pages as $page) {
      $pageId = (string)($page['id'] ?? '');
      if ($pageId === $id) continue;
      $pageTitle = htmlspecialchars((string)($page['title'] ?? $page['slug'] ?? $pageId), ENT_QUOTES);
      $selected = $navParent !== '' && $navParent === $pageId ? ' selected' : '';
      $parentOptions .= '<option value="' . htmlspecialchars($pageId, ENT_QUOTES) . '"' . $selected . '>' . $pageTitle . '</option>';
    }

    // Flash-Meldung
    $flash = '';
    if (!empty($_SESSION['_flash'])) {
      $flash = (string)$_SESSION['_flash'];
      unset($_SESSION['_flash']);
    }

    $content = "<form method=\"post\" action=\"/admin.php?a=page_save\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<input type=\"hidden\" name=\"id\" value=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">"
      . "<div class=\"actions\">"
      . "<a class=\"btn\" href=\"/admin.php?a=pages\">← Zur Liste</a>"
      . "<button class=\"btn primary\" type=\"submit\">Speichern</button>"
      . "<a class=\"btn\" href=\"/" . ($slug === 'home' ? '' : $slug) . "\" target=\"_blank\" rel=\"noopener\">Öffnen</a>"
      . "</div>"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Titel<br><input type=\"text\" name=\"title\" value=\"{$title}\"></label></div>"
      . "<div><label>Slug<br><input type=\"text\" name=\"slug\" value=\"{$slug}\"></label></div>"
      . "</div>"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Status<br><select name=\"status\">"
      . "<option value=\"draft\"" . ($status === 'draft' ? ' selected' : '') . ">draft</option>"
      . "<option value=\"published\"" . ($status === 'published' ? ' selected' : '') . ">published</option>"
      . "</select></label></div>"
      . "<div><label>Navigation<br><select name=\"nav_show\">"
      . "<option value=\"1\"" . ($navShow ? ' selected' : '') . ">anzeigen</option>"
      . "<option value=\"0\"" . (!$navShow ? ' selected' : '') . ">verstecken</option>"
      . "</select></label></div>"
      . "</div>"
      . "<div class=\"cols cols-3\">"
      . "<div><label>Nav-Order<br><input type=\"text\" name=\"nav_order\" value=\"" . htmlspecialchars((string)$navOrder, ENT_QUOTES) . "\"></label></div>"
      . "<div><label>Nav-Label<br><input type=\"text\" name=\"nav_label\" value=\"{$navLabel}\"></label></div>"
      . "<div><label>Unterpunkt von<br><select name=\"nav_parent\">{$parentOptions}</select></label></div>"
      . "</div>"
      . "<div class=\"cols cols-2\">"
      . "<div><label>Robots<br><select name=\"robots\">"
      . "<option value=\"\"" . ($pageRobots === '' ? ' selected' : '') . ">index, follow (Standard)</option>"
      . "<option value=\"noindex\"" . ($pageRobots === 'noindex' ? ' selected' : '') . ">noindex</option>"
      . "<option value=\"noindex, nofollow\"" . ($pageRobots === 'noindex, nofollow' ? ' selected' : '') . ">noindex, nofollow</option>"
      . "</select></label></div>"
      . "<div></div>"
      . "</div>"
      . "<div class=\"editor-switch\">"
      . "<button class=\"btn primary\" type=\"button\" data-be-mode=\"visual\">Block-Editor</button>"
      . "<button class=\"btn\" type=\"button\" data-be-mode=\"json\">JSON</button>"
      . "</div>"
      . "<p class=\"be-help\"><strong>Block-Editor</strong> <small>Heading, Text, Bild, Liste, Buttons, Spalten, HTML</small></p>"
      . "<div class=\"block-editor-shell is-active\" data-block-editor data-block-editor-title-selector=\"input[name='title']\" data-block-editor-media=\"{$mediaJson}\"></div>"
      . "<div class=\"json-editor-shell\" data-block-editor-json-wrap>"
      . "<p class=\"json-editor-label\"><strong>Page JSON</strong> <small>(Blocks + Meta)</small></p>"
      . "<textarea name=\"page_json\" data-block-editor-source>" . htmlspecialchars($json, ENT_QUOTES) . "</textarea>"
      . "</div>"
      . "</form>";

    $this->render('Seite bearbeiten', $content, $flash);
  }

  public function save(): void {
    Csrf::check();

    $id = (string)($_POST['id'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $status = (string)($_POST['status'] ?? 'draft');
    $navShow = ((string)($_POST['nav_show'] ?? '1')) === '1';
    $navOrder = (int)($_POST['nav_order'] ?? 0);
    $navLabel = trim((string)($_POST['nav_label'] ?? ''));
    $navParent = trim((string)($_POST['nav_parent'] ?? ''));
    $robots = trim((string)($_POST['robots'] ?? ''));
    $pageJson = (string)($_POST['page_json'] ?? '{}');

    if ($title === '') $title = 'Ohne Titel';
    if ($slug === '') $slug = $title;

    $slug = Slug::slugify($slug);

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $found = false;
    $slugs = [];
    $pageIds = [];

    foreach ($pages as $p) {
      $pageIds[] = (string)($p['id'] ?? '');
      if (($p['id'] ?? '') !== $id) $slugs[] = (string)($p['slug'] ?? '');
    }

    $base = $slug;
    $n = 2;
    while (in_array($slug, $slugs, true)) {
      $slug = $base . '-' . $n;
      $n++;
    }

    if ($navParent === '' || $navParent === $id || !in_array($navParent, $pageIds, true)) {
      $navParent = '';
    }

    foreach ($pages as &$p) {
      if (($p['id'] ?? '') !== $id) continue;
      $p['title'] = $title;
      $p['slug'] = $slug;
      $p['status'] = ($status === 'published') ? 'published' : 'draft';
      $p['nav'] = [
        'show' => $navShow,
        'order' => $navOrder,
        'label' => $navLabel !== '' ? $navLabel : null,
        'parent' => $navParent !== '' ? $navParent : null
      ];
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

    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);

    $decoded = json_decode($pageJson, true);
    if (!is_array($decoded)) $decoded = [];
    // Robots in meta setzen
    if (!is_array($decoded['meta'] ?? null)) $decoded['meta'] = [];
    if ($robots !== '') {
      $decoded['meta']['robots'] = $robots;
    } else {
      unset($decoded['meta']['robots']);
    }
    Storage::writeJson('content/pages/' . $id . '.json', $decoded);

    Sitemap::write();

    $_SESSION['_flash'] = 'Gespeichert.';
    header('Location: /admin.php?a=page_edit&id=' . urlencode($id));
    exit;
  }

  public function delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=pages');
      exit;
    }

    Csrf::check();

    $id = (string)($_POST['id'] ?? '');
    if ($id === '') {
      header('Location: /admin.php?a=pages');
      exit;
    }

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    foreach ($pages as &$page) {
      $nav = is_array($page['nav'] ?? null) ? $page['nav'] : [];
      if ((string)($nav['parent'] ?? '') === $id) {
        $nav['parent'] = null;
        $page['nav'] = $nav;
      }
    }
    unset($page);

    $pages = array_values(array_filter($pages, fn($p) => (string)($p['id'] ?? '') !== $id));
    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);

    $file = Storage::root() . '/content/pages/' . $id . '.json';
    if (is_file($file)) @unlink($file);

    Sitemap::write();

    header('Location: /admin.php?a=pages');
    exit;
  }

  public function duplicate(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=pages');
      exit;
    }

    Csrf::check();

    $sourceId = (string)($_POST['id'] ?? '');
    if ($sourceId === '') {
      header('Location: /admin.php?a=pages');
      exit;
    }

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    // Quellseite finden
    $source = null;
    foreach ($pages as $p) {
      if (($p['id'] ?? '') === $sourceId) { $source = $p; break; }
    }

    if (!$source) {
      header('Location: /admin.php?a=pages');
      exit;
    }

    // Neue ID und Slug generieren
    $newId = bin2hex(random_bytes(6));
    $newTitle = (string)($source['title'] ?? 'Kopie') . ' (Kopie)';
    $baseSlug = Slug::slugify($newTitle);
    $slugs = array_map(fn($p) => (string)($p['slug'] ?? ''), $pages);
    $slug = $baseSlug;
    $n = 2;
    while (in_array($slug, $slugs, true)) {
      $slug = $baseSlug . '-' . $n;
      $n++;
    }

    // Neuen Seitenindex-Eintrag
    $pages[] = [
      'id' => $newId,
      'slug' => $slug,
      'title' => $newTitle,
      'status' => 'draft',
      'nav' => [
        'show' => (bool)($source['nav']['show'] ?? true),
        'order' => count($pages) + 1,
        'label' => null,
        'parent' => $source['nav']['parent'] ?? null
      ],
      'created' => date('c'),
      'updated' => date('c')
    ];

    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);

    // Seiteninhalt kopieren
    $sourceData = Storage::readJson('content/pages/' . $sourceId . '.json');
    if (!is_array($sourceData)) {
      $sourceData = ['meta' => ['title' => $newTitle, 'description' => ''], 'content' => ['blocks' => []]];
    }

    // Meta-Titel anpassen
    if (isset($sourceData['meta'])) {
      $sourceData['meta']['title'] = $newTitle;
    }

    Storage::writeJson('content/pages/' . $newId . '.json', $sourceData);

    Sitemap::write();

    header('Location: /admin.php?a=page_edit&id=' . urlencode($newId));
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

    // CSRF aus JSON-Body lesen
    if (!empty($body['_csrf'])) {
      $_POST['_csrf'] = $body['_csrf'];
    }
    Csrf::check();

    $order = $body['order'] ?? $_POST['order'] ?? null;

    if (!is_array($order)) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'error' => 'order fehlt']);
      exit;
    }

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    // order ist ein Array von Seiten-IDs in der neuen Reihenfolge
    $orderMap = [];
    foreach ($order as $i => $id) {
      $orderMap[(string)$id] = $i + 1;
    }

    foreach ($pages as &$p) {
      $pid = (string)($p['id'] ?? '');
      if (isset($orderMap[$pid])) {
        $p['nav']['order'] = $orderMap[$pid];
      }
    }
    unset($p);

    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);
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
