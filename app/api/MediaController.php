<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Storage;

final class MediaController {
  private const EXTENSIONS = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'webp' => ['image/webp'],
    'gif' => ['image/gif'],
    'svg' => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
    'pdf' => ['application/pdf'],
    'mp4' => ['video/mp4'],
    'mp3' => ['audio/mpeg', 'audio/mp3'],
    'wav' => ['audio/wav', 'audio/x-wav'],
  ];

  public function index(): void {
    $files = $this->collectFiles();
    $out = [];

    foreach ($files as $file) {
      $refs = $this->findReferencesForPath((string)$file['path']);
      $file['used'] = $refs !== [];
      $file['usage_count'] = count($refs);
      $file['deletable'] = $refs === [];
      $out[] = $file;
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'files' => $out
      ]
    ]);
  }

  public function upload(): void {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
      $this->json(400, [
        'ok' => false,
        'error' => 'missing_file'
      ]);
    }

    $file = $_FILES['file'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $this->json(400, [
        'ok' => false,
        'error' => 'upload_failed'
      ]);
    }

    $name = trim((string)($file['name'] ?? ''));
    $tmp = trim((string)($file['tmp_name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($name === '' || $tmp === '' || $ext === '') {
      $this->json(400, [
        'ok' => false,
        'error' => 'invalid_file'
      ]);
    }

    if (!array_key_exists($ext, self::EXTENSIONS)) {
      $this->json(415, [
        'ok' => false,
        'error' => 'unsupported_filetype'
      ]);
    }

    $mime = $this->detectMime($tmp);
    $allowedMimes = self::EXTENSIONS[$ext];

    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
      if (!($ext === 'svg' && in_array($mime, ['text/plain', 'text/xml', 'application/xml'], true))) {
        $this->json(415, [
          'ok' => false,
          'error' => 'mime_mismatch',
          'details' => [
            'ext' => $ext,
            'mime' => $mime
          ]
        ]);
      }
    }

    $sub = date('Y/m');
    $destDir = Storage::root() . '/public/media/' . $sub;

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
      $this->json(500, [
        'ok' => false,
        'error' => 'media_dir_create_failed'
      ]);
    }

    $base = preg_replace('/[^a-z0-9\-_\.]/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?? 'file';
    $base = trim((string)(preg_replace('/-+/', '-', $base) ?? $base), '-');
    if ($base === '') {
      $base = 'file';
    }

    $filename = $base . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $destDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
      $this->json(500, [
        'ok' => false,
        'error' => 'move_failed'
      ]);
    }

    $src = '/media/' . $sub . '/' . $filename;

    $this->json(201, [
      'ok' => true,
      'data' => [
        'src' => $src,
        'url' => $this->absoluteUrl($src),
        'filename' => $filename,
        'ext' => $ext,
        'mime' => $this->detectMime($dest),
        'size' => (int)filesize($dest),
        'modified' => date('c', (int)filemtime($dest))
      ]
    ]);
  }

  public function usage(): void {
    $payload = $this->readJsonBodyOptional();
    $path = trim((string)($_GET['path'] ?? ($payload['path'] ?? '')));

    if ($path !== '') {
      $full = $this->resolveMediaPath($path);

      if ($full === null || !is_file($full)) {
        $this->json(404, [
          'ok' => false,
          'error' => 'media_not_found'
        ]);
      }

      $refs = $this->findReferencesForPath($path);

      $this->json(200, [
        'ok' => true,
        'data' => [
          'path' => $path,
          'used' => $refs !== [],
          'usage_count' => count($refs),
          'references' => $refs
        ]
      ]);
    }

    $files = $this->collectFiles();
    $used = [];
    $unused = [];

    foreach ($files as $file) {
      $refs = $this->findReferencesForPath((string)$file['path']);
      $entry = [
        'path' => $file['path'],
        'filename' => $file['filename'],
        'url' => $file['url'],
        'mime' => $file['mime'],
        'ext' => $file['ext'],
        'size' => $file['size'],
        'modified' => $file['modified'],
        'used' => $refs !== [],
        'usage_count' => count($refs),
        'references' => $refs
      ];

      if ($refs === []) {
        $unused[] = $entry;
      } else {
        $used[] = $entry;
      }
    }

    $this->json(200, [
      'ok' => true,
      'data' => [
        'summary' => [
          'total' => count($files),
          'used' => count($used),
          'unused' => count($unused)
        ],
        'used_files' => $used,
        'unused_files' => $unused
      ]
    ]);
  }

  public function delete(): void {
    $payload = $this->readJsonBodyOptional();
    $path = trim((string)($_GET['path'] ?? ($payload['path'] ?? '')));

    if ($path === '') {
      $this->json(400, [
        'ok' => false,
        'error' => 'missing_path'
      ]);
    }

    $full = $this->resolveMediaPath($path);

    if ($full === null || !is_file($full)) {
      $this->json(404, [
        'ok' => false,
        'error' => 'media_not_found'
      ]);
    }

    $references = $this->findReferencesForPath($path);

    if ($references !== []) {
      $this->json(409, [
        'ok' => false,
        'error' => 'media_in_use',
        'data' => [
          'path' => $path,
          'usage_count' => count($references),
          'references' => $references
        ]
      ]);
    }

    if (!@unlink($full)) {
      $this->json(500, [
        'ok' => false,
        'error' => 'delete_failed'
      ]);
    }

    $this->cleanupEmptyMediaDirs(dirname($full));

    $this->json(200, [
      'ok' => true,
      'data' => [
        'deleted' => true,
        'path' => $path
      ]
    ]);
  }

  private function collectFiles(): array {
    $dir = Storage::root() . '/public/media';
    $files = [];

    if (is_dir($dir)) {
      $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
      );

      foreach ($it as $file) {
        if (!$file->isFile()) {
          continue;
        }

        $full = $file->getPathname();
        $relative = str_replace(Storage::root() . '/public', '', $full);
        $relative = str_replace('\\', '/', $relative);

        $files[] = [
          'path' => $relative,
          'url' => $this->absoluteUrl($relative),
          'filename' => basename($relative),
          'ext' => strtolower(pathinfo($relative, PATHINFO_EXTENSION)),
          'mime' => $this->detectMime($full),
          'size' => (int)$file->getSize(),
          'modified' => date('c', (int)$file->getMTime()),
        ];
      }
    }

    usort($files, fn(array $a, array $b): int => strcmp((string)$b['modified'], (string)$a['modified']));

    return $files;
  }

  private ?array $cachedSources = null;

  private function findReferencesForPath(string $path): array {
    if ($this->cachedSources === null) {
      $this->cachedSources = $this->contentSources();
    }
    $sources = $this->cachedSources;
    $hits = [];

    foreach ($sources as $source) {
      $sourceHits = [];
      $this->scanValueForPath($source['data'], $path, '', $sourceHits);

      if ($sourceHits === []) {
        continue;
      }

      foreach ($sourceHits as $hit) {
        $hits[] = [
          'source' => $source['label'],
          'location' => $hit
        ];
      }
    }

    return $hits;
  }

  private function contentSources(): array {
    $sources = [];
    $index = Storage::readJson('content/pages.json');
    $pages = $index['pages'] ?? [];
    $titles = [];

    foreach ($pages as $page) {
      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') {
        continue;
      }

      $titles[$id] = trim((string)($page['title'] ?? $id));
    }

    foreach ($titles as $id => $title) {
      $sources[] = [
        'label' => 'Seite: ' . $title,
        'data' => Storage::readJson('content/pages/' . $id . '.json')
      ];
    }

    foreach (['header', 'footer'] as $part) {
      $sources[] = [
        'label' => strtoupper($part),
        'data' => Storage::readJson('content/globals/' . $part . '.json')
      ];
    }

    // Blog-Posts + Beitragsbilder
    $blogIndex = Storage::readJson('content/blog.json');
    foreach ($blogIndex['posts'] ?? [] as $post) {
      $bId = trim((string)($post['id'] ?? ''));
      $bTitle = trim((string)($post['title'] ?? $bId));
      if ($bId === '') continue;
      $sources[] = [
        'label' => 'Blog: ' . $bTitle . ' (Beitragsbild)',
        'data' => ['image' => (string)($post['image'] ?? '')]
      ];
      $sources[] = [
        'label' => 'Blog: ' . $bTitle,
        'data' => Storage::readJson('content/blog/' . $bId . '.json')
      ];
    }

    return $sources;
  }

  private function scanValueForPath(mixed $value, string $path, string $trail, array &$hits): void {
    if (is_array($value)) {
      foreach ($value as $key => $child) {
        $nextTrail = $trail === '' ? (string)$key : $trail . '.' . (string)$key;
        $this->scanValueForPath($child, $path, $nextTrail, $hits);
      }
      return;
    }

    if (!is_string($value) || $value === '') {
      return;
    }

    if (mb_strpos($value, $path) === false) {
      return;
    }

    $hits[] = $trail === '' ? 'root' : $trail;
  }

  private function resolveMediaPath(string $path): ?string {
    $path = str_replace('\\', '/', $path);

    if (!str_starts_with($path, '/media/')) {
      return null;
    }

    if (str_contains($path, '..')) {
      return null;
    }

    $full = Storage::root() . '/public' . $path;
    $base = realpath(Storage::root() . '/public/media');
    $real = realpath($full);

    if ($base === false || $real === false) {
      return null;
    }

    $base = str_replace('\\', '/', $base);
    $real = str_replace('\\', '/', $real);

    if (!str_starts_with($real, $base . '/') && $real !== $base) {
      return null;
    }

    return $real;
  }

  private function cleanupEmptyMediaDirs(string $dir): void {
    $base = realpath(Storage::root() . '/public/media');
    $current = realpath($dir);

    if ($base === false || $current === false) {
      return;
    }

    $base = str_replace('\\', '/', $base);
    $current = str_replace('\\', '/', $current);

    while ($current !== $base && str_starts_with($current, $base . '/')) {
      $items = @scandir($current);

      if (!is_array($items) || count($items) > 2) {
        break;
      }

      @rmdir($current);
      $parent = dirname($current);

      if ($parent === $current) {
        break;
      }

      $current = $parent;
    }
  }

  private function detectMime(string $file): string {
    if (!is_file($file)) {
      return '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
      return '';
    }

    $mime = finfo_file($finfo, $file);
    finfo_close($finfo);

    return is_string($mime) ? trim($mime) : '';
  }

  private function absoluteUrl(string $path): string {
    $site = Storage::readJson('config/site.json');
    $baseUrl = trim((string)($site['baseUrl'] ?? ''));

    if ($baseUrl === '') {
      return $path;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
  }

  private function readJsonBodyOptional(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
      return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }

  private function json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
  }
}