<?php
declare(strict_types=1);

namespace App\Core;

final class Slug {
  public static function slugify(string $value, string $fallback = 'seite'): string {
    $value = trim($value);
    $value = str_replace(
      ['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'],
      ['ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss'],
      $value
    );
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9\-]/', '-', $value) ?? $value;
    $value = preg_replace('/-+/', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
  }

  public static function uniqueSlug(string $slug, array $items, string $ignoreId = ''): string {
    $used = [];
    foreach ($items as $item) {
      if (!is_array($item)) { $used[] = (string)$item; continue; }
      $itemId = (string)($item['id'] ?? '');
      if ($ignoreId !== '' && $itemId === $ignoreId) continue;
      $used[] = (string)($item['slug'] ?? '');
    }
    $base = $slug;
    $n = 2;
    while (in_array($slug, $used, true)) {
      $slug = $base . '-' . $n;
      $n++;
    }
    return $slug;
  }

  public static function imageMediaJson(): string {
    $dir = Storage::root() . '/public/media';
    $items = [];

    if (is_dir($dir)) {
      $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
      );

      foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $relative = str_replace(Storage::root() . '/public', '', $file->getPathname());
        $relative = str_replace('\\', '/', $relative);
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) continue;
        $items[] = [
          'path' => $relative,
          'filename' => basename($relative),
          'modified' => date('c', (int)$file->getMTime())
        ];
      }
    }

    usort($items, fn(array $a, array $b): int => strcmp((string)$b['modified'], (string)$a['modified']));
    return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
  }
}
