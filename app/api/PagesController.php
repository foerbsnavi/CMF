<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Storage;
use App\Core\PageSchema;
use App\Core\Sitemap;
use App\Core\Slug;

final class PagesController {
  public function index(): void {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    usort($pages, function(array $a, array $b): int {
      return strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? ''));
    });

    $out = [];
    foreach ($pages as $page) {
      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') continue;
      $out[] = $this->normalizeIndexPage($page);
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'pages' => $out
      ]
    ]);
  }

  public function show(): void {
    $id = trim((string)($_GET['id'] ?? ''));
    $slug = trim((string)($_GET['slug'] ?? ''));

    [$page, $pageData] = $this->findPageWithContent($id, $slug);

    if ($page === null) {
      $this->json(404, [
        'ok' => false,
        'error' => 'page_not_found'
      ]);
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'page' => $this->normalizeIndexPage($page),
        'content' => $this->normalizePageContent($pageData)
      ]
    ]);
  }

  public function create(): void {
    $payload = $this->readJsonBody();

    $title = trim((string)($payload['title'] ?? ''));
    $slugInput = trim((string)($payload['slug'] ?? ''));
    $statusInput = trim((string)($payload['status'] ?? 'draft'));
    $navInput = $payload['nav'] ?? [];
    $contentInput = $payload['content'] ?? $payload['page'] ?? null;

    if ($title === '') $title = 'Neue Seite';

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $id = bin2hex(random_bytes(6));
    $slug = Slug::uniqueSlug(Slug::slugify($slugInput !== '' ? $slugInput : $title), $pages);
    $status = $statusInput === 'published' ? 'published' : 'draft';
    $nav = $this->normalizeNav($navInput, count($pages) + 1);

    if (is_string($contentInput)) { $contentInput = json_decode($contentInput, true) ?? null; }
    $pageContent = is_array($contentInput) ? $contentInput : [];
    $validation = PageSchema::validate($pageContent);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_page_schema',
        'details' => $validation['errors']
      ]);
    }

    $pageContent = $this->normalizePageContent($pageContent, $title);
    $now = date('c');

    $pages[] = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => $status,
      'nav' => $nav,
      'created' => $now,
      'updated' => $now
    ];

    $idx['pages'] = array_values($pages);
    Storage::writeJson('content/pages.json', $idx);
    Storage::writeJson('content/pages/' . $id . '.json', $pageContent);
    Sitemap::write();

    $this->json(201, [
      'ok' => true,
      'data' => [
        'page' => [
          'id' => $id,
          'slug' => $slug,
          'title' => $title,
          'status' => $status,
          'nav' => $nav,
          'created' => $now,
          'updated' => $now
        ],
        'content' => $pageContent
      ]
    ]);
  }

  public function update(): void {
    $payload = $this->readJsonBody();
    $id = trim((string)($_GET['id'] ?? ($payload['id'] ?? '')));

    if ($id === '') {
      $this->json(400, [
        'ok' => false,
        'error' => 'missing_id'
      ]);
    }

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $foundIndex = null;
    foreach ($pages as $i => $page) {
      if ((string)($page['id'] ?? '') === $id) {
        $foundIndex = $i;
        break;
      }
    }

    if ($foundIndex === null) {
      $this->json(404, [
        'ok' => false,
        'error' => 'page_not_found'
      ]);
    }

    $current = $pages[$foundIndex];
    $currentContent = Storage::readJson('content/pages/' . $id . '.json');

    $title = array_key_exists('title', $payload) ? trim((string)$payload['title']) : trim((string)($current['title'] ?? ''));
    if ($title === '') $title = 'Ohne Titel';

    $slugSource = array_key_exists('slug', $payload) ? trim((string)$payload['slug']) : trim((string)($current['slug'] ?? ''));
    $slug = Slug::uniqueSlug(Slug::slugify($slugSource !== '' ? $slugSource : $title), $pages, $id);

    $status = array_key_exists('status', $payload)
      ? (((string)$payload['status'] === 'published') ? 'published' : 'draft')
      : (string)($current['status'] ?? 'draft');

    $nav = array_key_exists('nav', $payload)
      ? $this->mergeNav($current['nav'] ?? [], $payload['nav'])
      : $this->normalizeNav($current['nav'] ?? [], 0);

    $content = array_key_exists('content', $payload) || array_key_exists('page', $payload)
      ? ($payload['content'] ?? $payload['page'] ?? [])
      : $currentContent;

    // Accept content as JSON string (e.g. from n8n toolHttpRequest keypair)
    if (is_string($content)) { $content = json_decode($content, true) ?? []; }
    if (!is_array($content)) $content = [];

    $validation = PageSchema::validate($content);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_page_schema',
        'details' => $validation['errors']
      ]);
    }

    $content = $this->normalizePageContent($content, $title);

    $updated = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => $status,
      'nav' => $nav,
      'created' => (string)($current['created'] ?? date('c')),
      'updated' => date('c')
    ];

    $pages[$foundIndex] = $updated;
    $idx['pages'] = array_values($pages);

    Storage::writeJson('content/pages.json', $idx);
    Storage::writeJson('content/pages/' . $id . '.json', $content);
    Sitemap::write();

    $this->json(200, [
      'ok' => true,
      'data' => [
        'page' => $updated,
        'content' => $content
      ]
    ]);
  }

  public function delete(): void {
    $payload = $this->readJsonBodyOptional();
    $id = trim((string)($_GET['id'] ?? ($payload['id'] ?? '')));

    if ($id === '') {
      $this->json(400, [
        'ok' => false,
        'error' => 'missing_id'
      ]);
    }

    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    $found = null;
    foreach ($pages as $page) {
      if ((string)($page['id'] ?? '') === $id) {
        $found = $page;
        break;
      }
    }

    if ($found === null) {
      $this->json(404, [
        'ok' => false,
        'error' => 'page_not_found'
      ]);
    }

    foreach ($pages as &$page) {
      $nav = is_array($page['nav'] ?? null) ? $page['nav'] : [];
      if ((string)($nav['parent'] ?? '') === $id) {
        $nav['parent'] = null;
        $page['nav'] = $nav;
      }
    }
    unset($page);

    $pages = array_values(array_filter($pages, fn(array $page): bool => (string)($page['id'] ?? '') !== $id));
    $idx['pages'] = $pages;
    Storage::writeJson('content/pages.json', $idx);
    Sitemap::write();

    $file = Storage::root() . '/content/pages/' . $id . '.json';
    if (is_file($file)) @unlink($file);

    $this->json(200, [
      'ok' => true,
      'data' => [
        'deleted' => true,
        'page' => $this->normalizeIndexPage($found)
      ]
    ]);
  }

  private function findPageWithContent(string $id, string $slug): array {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];

    foreach ($pages as $page) {
      $pageId = (string)($page['id'] ?? '');
      $pageSlug = (string)($page['slug'] ?? '');

      if ($id !== '' && $pageId === $id) {
        return [$page, Storage::readJson('content/pages/' . $pageId . '.json')];
      }

      if ($id === '' && $slug !== '' && $pageSlug === $slug) {
        return [$page, Storage::readJson('content/pages/' . $pageId . '.json')];
      }
    }

    return [null, null];
  }

  private function normalizeIndexPage(array $page): array {
    return [
      'id' => trim((string)($page['id'] ?? '')),
      'slug' => trim((string)($page['slug'] ?? '')),
      'title' => trim((string)($page['title'] ?? '')),
      'status' => ((string)($page['status'] ?? 'draft') === 'published') ? 'published' : 'draft',
      'nav' => $this->normalizeNav($page['nav'] ?? [], 0),
      'created' => trim((string)($page['created'] ?? '')),
      'updated' => trim((string)($page['updated'] ?? ''))
    ];
  }

  private function normalizeNav(mixed $nav, int $fallbackOrder): array {
    $nav = is_array($nav) ? $nav : [];
    $parent = array_key_exists('parent', $nav) && $nav['parent'] !== null ? trim((string)$nav['parent']) : null;

    return [
      'show' => (bool)($nav['show'] ?? true),
      'order' => (int)($nav['order'] ?? $fallbackOrder),
      'label' => array_key_exists('label', $nav) && $nav['label'] !== null ? trim((string)$nav['label']) : null,
      'parent' => $parent !== '' ? $parent : null
    ];
  }

  private function mergeNav(mixed $currentNav, mixed $incomingNav): array {
    $current = $this->normalizeNav($currentNav, 0);
    $incoming = is_array($incomingNav) ? $incomingNav : [];

    return [
      'show' => array_key_exists('show', $incoming) ? (bool)$incoming['show'] : $current['show'],
      'order' => array_key_exists('order', $incoming) ? (int)$incoming['order'] : $current['order'],
      'label' => array_key_exists('label', $incoming)
        ? ($incoming['label'] !== null ? trim((string)$incoming['label']) : null)
        : $current['label'],
      'parent' => array_key_exists('parent', $incoming)
        ? (($incoming['parent'] !== null && trim((string)$incoming['parent']) !== '') ? trim((string)$incoming['parent']) : null)
        : $current['parent']
    ];
  }

  private function normalizePageContent(mixed $content, string $fallbackTitle = 'Neue Seite'): array {
    $content = is_array($content) ? $content : [];
    $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
    $contentNode = is_array($content['content'] ?? null) ? $content['content'] : [];
    $blocks = $contentNode['blocks'] ?? [];

    if (!is_array($blocks)) $blocks = [];

    if ($blocks === []) {
      $blocks = [
        [
          'id' => 'b1',
          'type' => 'heading',
          'data' => [
            'level' => 1,
            'text' => $fallbackTitle
          ]
        ],
        [
          'id' => 'b2',
          'type' => 'text',
          'data' => [
            'html' => '<p>Inhalt hier.</p>'
          ]
        ]
      ];
    }

    $normalizedMeta = [
      'title' => trim((string)($meta['title'] ?? $fallbackTitle)),
      'description' => trim((string)($meta['description'] ?? ''))
    ];

    // Optionales robots-Feld durchreichen
    $robots = trim((string)($meta['robots'] ?? ''));
    if ($robots !== '') {
      $normalizedMeta['robots'] = $robots;
    }

    return [
      'meta' => $normalizedMeta,
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

  private function readJsonBodyOptional(): array {
    $raw = file_get_contents('php://input');
    // n8n compatibility: also accept JSON from URL parameter _data
    if (($raw === false || trim($raw) === '') && isset($_GET['_data'])) {
      $raw = $_GET['_data'];
    }
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }

  private function json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
  }
}