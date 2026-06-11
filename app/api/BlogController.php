<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Storage;
use App\Core\PageSchema;
use App\Core\Sitemap;
use App\Core\Slug;

final class BlogController {

  public function index(): void {
    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    usort($posts, fn(array $a, array $b) => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));

    $out = [];
    foreach ($posts as $post) {
      $id = trim((string)($post['id'] ?? ''));
      if ($id === '') continue;
      $out[] = $this->normalizeIndexPost($post);
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'slug' => trim((string)($idx['slug'] ?? 'blog')),
        'categories' => is_array($idx['categories'] ?? null) ? $idx['categories'] : [],
        'posts' => $out
      ]
    ]);
  }

  public function show(): void {
    $id = trim((string)($_GET['id'] ?? ''));
    $slug = trim((string)($_GET['slug'] ?? ''));

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];
    $found = null;

    foreach ($posts as $post) {
      $postId = (string)($post['id'] ?? '');
      $postSlug = (string)($post['slug'] ?? '');
      if (($id !== '' && $postId === $id) || ($id === '' && $slug !== '' && $postSlug === $slug)) {
        $found = $post;
        break;
      }
    }

    if ($found === null) {
      $this->json(404, ['ok' => false, 'error' => 'post_not_found']);
    }

    $postData = Storage::readJson('content/blog/' . $found['id'] . '.json');

    $this->json(200, [
      'ok' => true,
      'data' => [
        'post' => $this->normalizeIndexPost($found),
        'content' => $this->normalizePostContent($postData)
      ]
    ]);
  }

  public function create(): void {
    $payload = $this->readJsonBody();

    $title = trim((string)($payload['title'] ?? ''));
    $slugInput = trim((string)($payload['slug'] ?? ''));
    $statusInput = trim((string)($payload['status'] ?? 'draft'));
    $image = trim((string)($payload['image'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $contentInput = $payload['content'] ?? $payload['page'] ?? null;

    if ($title === '') $title = 'Neuer Beitrag';

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $id = bin2hex(random_bytes(6));
    $slug = Slug::uniqueSlug(Slug::slugify($slugInput !== '' ? $slugInput : $title), $posts);
    $status = $statusInput === 'published' ? 'published' : 'draft';
    // Neue Beitraege erscheinen oben (kleinste Order minus 1) —
    // eine News-Uebersicht zeigt das Neueste zuerst
    $minOrder = 1;
    foreach ($posts as $p) {
      $o = (int)($p['order'] ?? 0);
      if ($o < $minOrder) $minOrder = $o;
    }
    $order = $minOrder - 1;

    if (is_string($contentInput)) { $contentInput = json_decode($contentInput, true) ?? null; }
    $postContent = is_array($contentInput) ? $contentInput : [];
    $validation = PageSchema::validate($postContent);

    if (!$validation['ok']) {
      $this->json(422, [
        'ok' => false,
        'error' => 'invalid_post_schema',
        'details' => $validation['errors']
      ]);
    }

    $postContent = $this->normalizePostContent($postContent, $title, $image);
    $now = date('c');

    $posts[] = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => $status,
      'image' => $image,
      'description' => $description,
      'category' => $category,
      'order' => $order,
      'created' => $now,
      'updated' => $now
    ];

    $idx['posts'] = array_values($posts);
    Storage::writeJson('content/blog.json', $idx);
    Storage::writeJson('content/blog/' . $id . '.json', $postContent);
    Sitemap::write();

    $this->json(201, [
      'ok' => true,
      'data' => [
        'post' => $this->normalizeIndexPost(end($posts)),
        'content' => $postContent
      ]
    ]);
  }

  public function update(): void {
    $payload = $this->readJsonBody();
    $id = trim((string)($_GET['id'] ?? ($payload['id'] ?? '')));

    if ($id === '') {
      $this->json(400, ['ok' => false, 'error' => 'missing_id']);
    }

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $foundIndex = null;
    foreach ($posts as $i => $post) {
      if ((string)($post['id'] ?? '') === $id) { $foundIndex = $i; break; }
    }

    if ($foundIndex === null) {
      $this->json(404, ['ok' => false, 'error' => 'post_not_found']);
    }

    $current = $posts[$foundIndex];
    $currentContent = Storage::readJson('content/blog/' . $id . '.json');

    $title = array_key_exists('title', $payload) ? trim((string)$payload['title']) : trim((string)($current['title'] ?? ''));
    if ($title === '') $title = 'Ohne Titel';

    $slugSource = array_key_exists('slug', $payload) ? trim((string)$payload['slug']) : trim((string)($current['slug'] ?? ''));
    $slug = Slug::uniqueSlug(Slug::slugify($slugSource !== '' ? $slugSource : $title), $posts, $id);

    $status = array_key_exists('status', $payload)
      ? (((string)$payload['status'] === 'published') ? 'published' : 'draft')
      : (string)($current['status'] ?? 'draft');

    $image = array_key_exists('image', $payload) ? trim((string)$payload['image']) : trim((string)($current['image'] ?? ''));
    $description = array_key_exists('description', $payload) ? trim((string)$payload['description']) : trim((string)($current['description'] ?? ''));
    $category = array_key_exists('category', $payload) ? trim((string)$payload['category']) : trim((string)($current['category'] ?? ''));

    $content = array_key_exists('content', $payload) || array_key_exists('page', $payload)
      ? ($payload['content'] ?? $payload['page'] ?? [])
      : $currentContent;

    if (is_string($content)) { $content = json_decode($content, true) ?? []; }
    if (!is_array($content)) $content = [];

    $validation = PageSchema::validate($content);
    if (!$validation['ok']) {
      $this->json(422, ['ok' => false, 'error' => 'invalid_post_schema', 'details' => $validation['errors']]);
    }

    $content = $this->normalizePostContent($content, $title, $image);

    // Order optional per API setzbar (z.B. fuer Umsortierung)
    $order = array_key_exists('order', $payload) && is_numeric($payload['order'])
      ? (int)$payload['order']
      : (int)($current['order'] ?? 0);

    $updated = [
      'id' => $id,
      'slug' => $slug,
      'title' => $title,
      'status' => $status,
      'image' => $image,
      'description' => $description,
      'category' => $category,
      'order' => $order,
      'created' => (string)($current['created'] ?? date('c')),
      'updated' => date('c')
    ];

    $posts[$foundIndex] = $updated;
    $idx['posts'] = array_values($posts);

    Storage::writeJson('content/blog.json', $idx);
    Storage::writeJson('content/blog/' . $id . '.json', $content);
    Sitemap::write();

    $this->json(200, [
      'ok' => true,
      'data' => ['post' => $updated, 'content' => $content]
    ]);
  }

  public function delete(): void {
    $payload = $this->readJsonBodyOptional();
    $id = trim((string)($_GET['id'] ?? ($payload['id'] ?? '')));

    if ($id === '') {
      $this->json(400, ['ok' => false, 'error' => 'missing_id']);
    }

    $idx = Storage::readJson('content/blog.json');
    $posts = $idx['posts'] ?? [];

    $found = null;
    foreach ($posts as $post) {
      if ((string)($post['id'] ?? '') === $id) { $found = $post; break; }
    }

    if ($found === null) {
      $this->json(404, ['ok' => false, 'error' => 'post_not_found']);
    }

    $posts = array_values(array_filter($posts, fn(array $p): bool => (string)($p['id'] ?? '') !== $id));
    $idx['posts'] = $posts;
    Storage::writeJson('content/blog.json', $idx);
    Sitemap::write();

    $file = Storage::root() . '/content/blog/' . $id . '.json';
    if (is_file($file)) @unlink($file);

    $this->json(200, [
      'ok' => true,
      'data' => ['deleted' => true, 'post' => $this->normalizeIndexPost($found)]
    ]);
  }

  private function normalizeIndexPost(array $post): array {
    return [
      'id' => trim((string)($post['id'] ?? '')),
      'slug' => trim((string)($post['slug'] ?? '')),
      'title' => trim((string)($post['title'] ?? '')),
      'status' => ((string)($post['status'] ?? 'draft') === 'published') ? 'published' : 'draft',
      'image' => trim((string)($post['image'] ?? '')),
      'description' => trim((string)($post['description'] ?? '')),
      'category' => trim((string)($post['category'] ?? '')),
      'order' => (int)($post['order'] ?? 0),
      'created' => trim((string)($post['created'] ?? '')),
      'updated' => trim((string)($post['updated'] ?? ''))
    ];
  }

  private function normalizePostContent(mixed $content, string $fallbackTitle = 'Neuer Beitrag', string $fallbackImage = ''): array {
    $content = is_array($content) ? $content : [];
    $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
    $contentNode = is_array($content['content'] ?? null) ? $content['content'] : [];
    $blocks = $contentNode['blocks'] ?? [];

    if (!is_array($blocks)) $blocks = [];

    if ($blocks === []) {
      $blocks = [
        ['id' => 'b1', 'type' => 'heading', 'data' => ['level' => 1, 'text' => $fallbackTitle]],
        ['id' => 'b2', 'type' => 'text', 'data' => ['html' => '<p>Inhalt hier.</p>']]
      ];
    }

    $normalizedMeta = [
      'title' => trim((string)($meta['title'] ?? $fallbackTitle)),
      'description' => trim((string)($meta['description'] ?? ''))
    ];

    $image = trim((string)($meta['image'] ?? $fallbackImage));
    if ($image !== '') $normalizedMeta['image'] = $image;

    $robots = trim((string)($meta['robots'] ?? ''));
    if ($robots !== '') $normalizedMeta['robots'] = $robots;

    return [
      'meta' => $normalizedMeta,
      'content' => ['blocks' => $blocks]
    ];
  }



  private function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
      $this->json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return $data;
  }

  private function readJsonBodyOptional(): array {
    $raw = file_get_contents('php://input');
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
