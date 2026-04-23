<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\PageSchema;
use App\Core\Sitemap;
use App\Core\Storage;
use App\Core\Theme;

final class GlobalsController {
  public function site(): void {
    $site = Storage::readJson('config/site.json');
    $site = $this->normalizeSite($site);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'site' => $site
      ]
    ]);
  }

  public function siteUpdate(): void {
    $payload = $this->readJsonBody();
    $site = $payload['site'] ?? $payload;

    if (!is_array($site)) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_site_schema',
        'details' => ['site ist kein objekt']
      ]);
    }

    $validation = $this->validateSite($site);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_site_schema',
        'details' => $validation['errors']
      ]);
    }

    $site = $this->normalizeSite($site);
    Storage::writeJson('config/site.json', $site);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'site' => $site
      ]
    ]);
  }

  public function partial(): void {
    $part = $this->readPart();
    $data = Storage::readJson('content/globals/' . $part . '.json');
    $data = $this->normalizePartial($data, ucfirst($part));

    $this->json(200, [
      'ok' => true,
      'data' => [
        'part' => $part,
        'content' => $data
      ]
    ]);
  }

  public function partialUpdate(): void {
    $part = $this->readPart();
    $payload = $this->readJsonBody();
    $content = $payload['content'] ?? $payload['page'] ?? $payload;

    // Accept content as JSON string (e.g. from n8n toolHttpRequest keypair)
    if (is_string($content)) { $content = json_decode($content, true) ?? null; }
    if (!is_array($content)) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_partial_schema',
        'details' => ['content ist kein objekt']
      ]);
    }

    $validation = PageSchema::validate($content);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_partial_schema',
        'details' => $validation['errors']
      ]);
    }

    $content = $this->normalizePartial($content, ucfirst($part));
    Storage::writeJson('content/globals/' . $part . '.json', $content);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'part' => $part,
        'content' => $content
      ]
    ]);
  }

  public function styles(): void {
    $styles = Storage::readJson('config/styles.json');
    $styles = $this->normalizeStyles($styles);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'styles' => $styles
      ]
    ]);
  }

  public function stylesUpdate(): void {
    $payload = $this->readJsonBody();
    $styles = $payload['styles'] ?? $payload;

    if (!is_array($styles)) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_styles_schema',
        'details' => ['styles ist kein objekt']
      ]);
    }

    $validation = $this->validateStyles($styles);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_styles_schema',
        'details' => $validation['errors']
      ]);
    }

    $styles = $this->normalizeStyles($styles);
    Storage::writeJson('config/styles.json', $styles);
    Theme::writeThemeCss($styles);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'styles' => $styles
      ]
    ]);
  }

  public function customCss(): void {
    $css = self::readCustomCss();
    $this->json(200, [
      'ok' => true,
      'data' => ['css' => $css]
    ]);
  }

  public function customCssUpdate(): void {
    $payload = $this->readJsonBody();
    $css = (string)($payload['css'] ?? '');

    self::writeCustomCss($css);

    $this->json(200, [
      'ok' => true,
      'data' => ['css' => $css]
    ]);
  }

  public static function readCustomCss(): string {
    $path = Storage::root() . '/public/assets/css/custom.css';
    return is_file($path) ? (string)file_get_contents($path) : '';
  }

  public static function writeCustomCss(string $css): void {
    $path = Storage::root() . '/public/assets/css/custom.css';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $css);
    rename($tmp, $path);
  }

  public function searchIndex(): void {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];
    $result = [];

    foreach ($pages as $page) {
      if (($page['status'] ?? 'draft') !== 'published') continue;

      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') continue;

      $slug = trim((string)($page['slug'] ?? ''));
      $title = trim((string)($page['title'] ?? ''));

      $pageData = Storage::readJson('content/pages/' . $id . '.json');
      $meta = is_array($pageData['meta'] ?? null) ? $pageData['meta'] : [];
      $description = trim((string)($meta['description'] ?? ''));

      $blocks = is_array($pageData['content']['blocks'] ?? null) ? $pageData['content']['blocks'] : [];
      $text = $this->blocksToPlaintext($blocks);
      if (mb_strlen($text) > 500) {
        $text = mb_substr($text, 0, 500);
      }

      $result[] = [
        'slug' => $slug,
        'title' => $title,
        'description' => $description,
        'text' => $text,
      ];
    }

    // Blog-Posts in den Suchindex aufnehmen
    $blogIdx = Storage::readJson('content/blog.json');
    foreach ($blogIdx['posts'] ?? [] as $post) {
      if (($post['status'] ?? 'draft') !== 'published') continue;
      $bId = trim((string)($post['id'] ?? ''));
      if ($bId === '') continue;
      $postData = Storage::readJson('content/blog/' . $bId . '.json');
      $bMeta = is_array($postData['meta'] ?? null) ? $postData['meta'] : [];
      $bBlocks = is_array($postData['content']['blocks'] ?? null) ? $postData['content']['blocks'] : [];
      $bText = $this->blocksToPlaintext($bBlocks);
      if (mb_strlen($bText) > 500) $bText = mb_substr($bText, 0, 500);
      $result[] = [
        'slug' => 'blog/' . trim((string)($post['slug'] ?? '')),
        'title' => trim((string)($post['title'] ?? '')),
        'description' => trim((string)($bMeta['description'] ?? '')),
        'text' => $bText,
      ];
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'pages' => $result,
      ]
    ]);
  }

  private function blocksToPlaintext(array $blocks): string {
    $parts = [];

    foreach ($blocks as $b) {
      $type = (string)($b['type'] ?? '');
      $data = $b['data'] ?? [];

      if ($type === 'heading') {
        $text = trim((string)($data['text'] ?? ''));
        if ($text !== '') $parts[] = $text;
      } elseif ($type === 'text') {
        $html = (string)($data['html'] ?? '');
        $stripped = strip_tags($html);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
        if ($stripped !== '') $parts[] = $stripped;
      } elseif ($type === 'list') {
        $items = $data['items'] ?? [];
        if (is_array($items)) {
          foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') $parts[] = $item;
          }
        }
      } elseif ($type === 'columns') {
        $colItems = $data['items'] ?? [];
        if (is_array($colItems)) {
          foreach ($colItems as $colBlocks) {
            if (is_array($colBlocks)) {
              $sub = $this->blocksToPlaintext($colBlocks);
              if ($sub !== '') $parts[] = $sub;
            }
          }
        }
      }
    }

    return implode(' ', $parts);
  }

  public function siteExport(): void {
    $site = Storage::readJson('config/site.json');
    $styles = Storage::readJson('config/styles.json');
    $header = Storage::readJson('content/globals/header.json');
    $footer = Storage::readJson('content/globals/footer.json');
    $customCss = self::readCustomCss();

    $idx = Storage::readJson('content/pages.json');
    $pages = [];
    foreach ($idx['pages'] ?? [] as $page) {
      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') continue;
      $pages[] = [
        'index' => $page,
        'content' => Storage::readJson('content/pages/' . $id . '.json')
      ];
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'site' => $site,
        'styles' => $styles,
        'header' => $header,
        'footer' => $footer,
        'custom_css' => $customCss,
        'pages' => $pages
      ]
    ]);
  }

  public function siteImport(): void {
    $payload = $this->readJsonBody();
    $imported = [];

    if (isset($payload['site']) && is_array($payload['site'])) {
      Storage::writeJson('config/site.json', $payload['site']);
      $imported[] = 'site';
    }

    if (isset($payload['styles']) && is_array($payload['styles'])) {
      Storage::writeJson('config/styles.json', $payload['styles']);
      Theme::writeThemeCss($payload['styles']);
      $imported[] = 'styles';
    }

    if (isset($payload['header']) && is_array($payload['header'])) {
      Storage::writeJson('content/globals/header.json', $payload['header']);
      $imported[] = 'header';
    }

    if (isset($payload['footer']) && is_array($payload['footer'])) {
      Storage::writeJson('content/globals/footer.json', $payload['footer']);
      $imported[] = 'footer';
    }

    if (isset($payload['custom_css']) && is_string($payload['custom_css'])) {
      self::writeCustomCss($payload['custom_css']);
      $imported[] = 'custom_css';
    }

    if (isset($payload['pages']) && is_array($payload['pages'])) {
      $idx = Storage::readJson('content/pages.json');
      $currentPages = $idx['pages'] ?? [];
      $currentIds = array_map(fn($p) => (string)($p['id'] ?? ''), $currentPages);

      foreach ($payload['pages'] as $entry) {
        $indexData = $entry['index'] ?? null;
        $contentData = $entry['content'] ?? null;
        if (!is_array($indexData) || !is_array($contentData)) continue;

        $id = trim((string)($indexData['id'] ?? ''));
        if ($id === '') continue;

        Storage::writeJson('content/pages/' . $id . '.json', $contentData);

        $existsIdx = array_search($id, $currentIds, true);
        if ($existsIdx !== false) {
          $indexData['updated'] = date('c');
          $currentPages[$existsIdx] = $indexData;
        } else {
          $indexData['created'] = date('c');
          $indexData['updated'] = date('c');
          $currentPages[] = $indexData;
          $currentIds[] = $id;
        }
      }

      $idx['pages'] = array_values($currentPages);
      Storage::writeJson('content/pages.json', $idx);
      $imported[] = 'pages (' . count($payload['pages']) . ')';
    }

    Sitemap::write();

    $this->json(200, [
      'ok' => true,
      'data' => ['imported' => $imported]
    ]);
  }

  public function pagesExport(): void {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];
    $result = [];

    foreach ($pages as $page) {
      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') continue;

      $pageData = Storage::readJson('content/pages/' . $id . '.json');
      $result[] = [
        'index' => $page,
        'content' => is_array($pageData) ? $pageData : []
      ];
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'pages' => $result,
        'count' => count($result)
      ]
    ]);
  }

  public function pagesImport(): void {
    $payload = $this->readJsonBody();
    $importPages = $payload['pages'] ?? null;

    if (!is_array($importPages)) {
      $this->json(422, ['ok' => false, 'error' => 'pages array fehlt']);
    }

    $idx = Storage::readJson('content/pages.json');
    $existingPages = $idx['pages'] ?? [];
    $existingSlugs = array_map(fn($p) => (string)($p['slug'] ?? ''), $existingPages);
    $existingIds = array_map(fn($p) => (string)($p['id'] ?? ''), $existingPages);

    $imported = 0;
    $skipped = 0;
    $mode = (string)($payload['mode'] ?? 'skip'); // skip oder overwrite

    foreach ($importPages as $entry) {
      $indexData = $entry['index'] ?? null;
      $contentData = $entry['content'] ?? null;

      if (!is_array($indexData) || !is_array($contentData)) {
        $skipped++;
        continue;
      }

      $id = trim((string)($indexData['id'] ?? ''));
      $slug = trim((string)($indexData['slug'] ?? ''));

      if ($id === '' || $slug === '') {
        $skipped++;
        continue;
      }

      // Existiert die Seite bereits?
      $existsIdx = array_search($id, $existingIds, true);

      if ($existsIdx !== false) {
        if ($mode !== 'overwrite') {
          $skipped++;
          continue;
        }
        // Überschreiben
        $existingPages[$existsIdx] = $indexData;
        $existingPages[$existsIdx]['updated'] = date('c');
      } else {
        // Neue Seite - Slug-Kollision prüfen
        $newSlug = $slug;
        $n = 2;
        while (in_array($newSlug, $existingSlugs, true)) {
          $newSlug = $slug . '-' . $n;
          $n++;
        }
        $indexData['slug'] = $newSlug;
        $indexData['created'] = date('c');
        $indexData['updated'] = date('c');
        $existingPages[] = $indexData;
        $existingSlugs[] = $newSlug;
        $existingIds[] = $id;
      }

      Storage::writeJson('content/pages/' . $id . '.json', $contentData);
      $imported++;
    }

    $idx['pages'] = array_values($existingPages);
    Storage::writeJson('content/pages.json', $idx);

    Sitemap::write();

    $this->json(200, [
      'ok' => true,
      'data' => [
        'imported' => $imported,
        'skipped' => $skipped
      ]
    ]);
  }

  public function bundle(): void {
    $site = $this->normalizeSite(Storage::readJson('config/site.json'));
    $styles = $this->normalizeStyles(Storage::readJson('config/styles.json'));
    $header = $this->normalizePartial(Storage::readJson('content/globals/header.json'), 'Header');
    $footer = $this->normalizePartial(Storage::readJson('content/globals/footer.json'), 'Footer');
    $customCss = self::readCustomCss();

    $this->json(200, [
      'ok' => true,
      'data' => [
        'site' => $site,
        'styles' => $styles,
        'header' => $header,
        'footer' => $footer,
        'custom_css' => $customCss
      ]
    ]);
  }
  
  private function readPart(): string {
    // n8n compatibility: also accept part from request body
    $raw = file_get_contents('php://input');
    $bodyPart = '';
    if ($raw !== false && trim($raw) !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $bodyPart = trim((string)($decoded['part'] ?? ''));
    }

    $part = trim((string)($_GET['part'] ?? $bodyPart));

    if (!in_array($part, ['header', 'footer'], true)) {
      $this->json(404, [
        'ok' => false,
        'error' => 'partial_not_found'
      ]);
    }

    return $part;
  }

  private function validateSite(array $site): array {
    $errors = [];

    if (trim((string)($site['name'] ?? '')) === '') {
      $errors[] = 'site.name fehlt';
    }

    $lang = trim((string)($site['lang'] ?? ''));
    if ($lang === '') {
      $errors[] = 'site.lang fehlt';
    }

    if (array_key_exists('baseUrl', $site) && !is_string($site['baseUrl'])) {
      $errors[] = 'site.baseUrl ungültig';
    }

    return [
      'ok' => $errors === [],
      'errors' => $errors
    ];
  }

  private function normalizeSite(array $site): array {
    return [
      'name' => trim((string)($site['name'] ?? 'Webseiten CMS')),
      'lang' => trim((string)($site['lang'] ?? 'de')),
      'baseUrl' => trim((string)($site['baseUrl'] ?? ''))
    ];
  }

  private function validateStyles(array $styles): array {
    $errors = [];

    foreach (['container', 'pad', 'gap'] as $key) {
      if (array_key_exists($key, $styles) && !is_string($styles[$key])) {
        $errors[] = "styles.{$key} ungültig";
      }
    }

    foreach (['sm', 'md', 'lg'] as $key) {
      if (isset($styles['radius']) && (!is_array($styles['radius']) || (array_key_exists($key, $styles['radius']) && !is_string($styles['radius'][$key])))) {
        $errors[] = "styles.radius.{$key} ungültig";
      }
    }

    foreach (['bg', 'text', 'muted', 'border', 'primary', 'secondary', 'primary_text', 'link'] as $key) {
      if (isset($styles['colors']) && (!is_array($styles['colors']) || (array_key_exists($key, $styles['colors']) && !is_string($styles['colors'][$key])))) {
        $errors[] = "styles.colors.{$key} ungültig";
      }
    }

    foreach (['body', 'h1', 'h2', 'h3', 'h4', 'h5'] as $key) {
      if (isset($styles['type']) && (!is_array($styles['type']) || (array_key_exists($key, $styles['type']) && !is_string($styles['type'][$key])))) {
        $errors[] = "styles.type.{$key} ungültig";
      }
    }

    foreach (['body', 'heading'] as $key) {
      if (isset($styles['fonts']) && (!is_array($styles['fonts']) || (array_key_exists($key, $styles['fonts']) && !is_string($styles['fonts'][$key])))) {
        $errors[] = "styles.fonts.{$key} ungültig";
      }
    }

    foreach (['body_weight', 'heading_weight'] as $key) {
      if (isset($styles['fonts']) && is_array($styles['fonts']) && array_key_exists($key, $styles['fonts'])) {
        if (!in_array(strtolower((string)($styles['fonts'][$key] ?? '')), ['light', 'regular', 'bold'], true)) {
          $errors[] = "styles.fonts.{$key} ungültig (erlaubt: light, regular, bold)";
        }
      }
    }

    return [
      'ok' => $errors === [],
      'errors' => $errors
    ];
  }

  private function normalizeStyles(array $styles): array {
    $current = Storage::readJson('config/styles.json');

    return [
      'container' => trim((string)($styles['container'] ?? $current['container'] ?? '1100px')),
      'pad' => trim((string)($styles['pad'] ?? $current['pad'] ?? '16px')),
      'gap' => trim((string)($styles['gap'] ?? $current['gap'] ?? '16px')),
      'radius' => [
        'sm' => trim((string)($styles['radius']['sm'] ?? $current['radius']['sm'] ?? '8px')),
        'md' => trim((string)($styles['radius']['md'] ?? $current['radius']['md'] ?? '14px')),
        'lg' => trim((string)($styles['radius']['lg'] ?? $current['radius']['lg'] ?? '22px'))
      ],
      'colors' => [
        'bg' => trim((string)($styles['colors']['bg'] ?? $current['colors']['bg'] ?? '#ffffff')),
        'text' => trim((string)($styles['colors']['text'] ?? $current['colors']['text'] ?? '#111111')),
        'muted' => trim((string)($styles['colors']['muted'] ?? $current['colors']['muted'] ?? '#666666')),
        'border' => trim((string)($styles['colors']['border'] ?? $current['colors']['border'] ?? '#dddddd')),
        'primary' => trim((string)($styles['colors']['primary'] ?? $current['colors']['primary'] ?? '#0d6efd')),
        'secondary' => trim((string)($styles['colors']['secondary'] ?? $current['colors']['secondary'] ?? '#ff0000')),
        'primary_text' => trim((string)($styles['colors']['primary_text'] ?? $current['colors']['primary_text'] ?? '#ffffff')),
        'link' => trim((string)($styles['colors']['link'] ?? $current['colors']['link'] ?? '#0d6efd'))
      ],
      'type' => [
        'body' => trim((string)($styles['type']['body'] ?? $current['type']['body'] ?? '14px')),
        'h1' => trim((string)($styles['type']['h1'] ?? $current['type']['h1'] ?? '2.1rem')),
        'h2' => trim((string)($styles['type']['h2'] ?? $current['type']['h2'] ?? '1.6rem')),
        'h3' => trim((string)($styles['type']['h3'] ?? $current['type']['h3'] ?? '1.25rem')),
        'h4' => trim((string)($styles['type']['h4'] ?? $current['type']['h4'] ?? '1.1rem')),
        'h5' => trim((string)($styles['type']['h5'] ?? $current['type']['h5'] ?? '1.0rem'))
      ],
      'fonts' => [
        'body' => trim((string)($styles['fonts']['body'] ?? $current['fonts']['body'] ?? '')),
        'body_weight' => in_array(strtolower((string)($styles['fonts']['body_weight'] ?? $current['fonts']['body_weight'] ?? '')), ['light', 'regular', 'bold'], true)
          ? strtolower((string)($styles['fonts']['body_weight'] ?? $current['fonts']['body_weight'])) : 'regular',
        'heading' => trim((string)($styles['fonts']['heading'] ?? $current['fonts']['heading'] ?? '')),
        'heading_weight' => in_array(strtolower((string)($styles['fonts']['heading_weight'] ?? $current['fonts']['heading_weight'] ?? '')), ['light', 'regular', 'bold'], true)
          ? strtolower((string)($styles['fonts']['heading_weight'] ?? $current['fonts']['heading_weight'])) : 'regular',
      ]
    ];
  }

  private function normalizePartial(array $content, string $fallbackTitle): array {
    $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
    $contentNode = is_array($content['content'] ?? null) ? $content['content'] : [];
    $blocks = $contentNode['blocks'] ?? [];

    if (!is_array($blocks)) {
      $blocks = [];
    }

    return [
      'meta' => [
        'title' => trim((string)($meta['title'] ?? $fallbackTitle)),
        'description' => trim((string)($meta['description'] ?? ''))
      ],
      'content' => [
        'blocks' => $blocks
      ]
    ];
  }

  private function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);

    if (!is_array($data)) {
      $this->json(400, [
        'ok' => false,
        'error' => 'invalid_json'
      ]);
    }

    return $data;
  }

  private function json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
  }
}