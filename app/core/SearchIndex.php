<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Baut den Such-Index fuer die Live-Suche und schreibt ihn als statische
 * Datei public/search-index.json. Besucher-Suchen treffen damit eine
 * statische Datei statt PHP-Bootstrap + N Datei-Reads pro Suchanfrage.
 * Wird zusammen mit der Sitemap bei jeder Inhalts-Aenderung regeneriert.
 */
final class SearchIndex {
  public static function build(): array {
    $idx = Storage::readJson('content/pages.json');
    $pages = $idx['pages'] ?? [];
    $result = [];

    foreach ($pages as $page) {
      if (($page['status'] ?? 'draft') !== 'published') continue;

      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') continue;

      $pageData = Storage::readJson('content/pages/' . $id . '.json');
      $meta = is_array($pageData['meta'] ?? null) ? $pageData['meta'] : [];

      $blocks = is_array($pageData['content']['blocks'] ?? null) ? $pageData['content']['blocks'] : [];
      $text = self::blocksToPlaintext($blocks);
      if (mb_strlen($text) > 500) {
        $text = mb_substr($text, 0, 500);
      }

      $result[] = [
        'slug' => trim((string)($page['slug'] ?? '')),
        'title' => trim((string)($page['title'] ?? '')),
        'description' => trim((string)($meta['description'] ?? '')),
        'text' => $text,
      ];
    }

    // Blog-Posts in den Suchindex aufnehmen
    $blogIdx = Storage::readJson('content/blog.json');
    $blogSlug = trim((string)($blogIdx['slug'] ?? 'blog'));
    foreach ($blogIdx['posts'] ?? [] as $post) {
      if (($post['status'] ?? 'draft') !== 'published') continue;
      $bId = trim((string)($post['id'] ?? ''));
      if ($bId === '') continue;
      $postData = Storage::readJson('content/blog/' . $bId . '.json');
      $bMeta = is_array($postData['meta'] ?? null) ? $postData['meta'] : [];
      $bBlocks = is_array($postData['content']['blocks'] ?? null) ? $postData['content']['blocks'] : [];
      $bText = self::blocksToPlaintext($bBlocks);
      if (mb_strlen($bText) > 500) $bText = mb_substr($bText, 0, 500);
      $result[] = [
        'slug' => $blogSlug . '/' . trim((string)($post['slug'] ?? '')),
        'title' => trim((string)($post['title'] ?? '')),
        'description' => trim((string)($bMeta['description'] ?? '')),
        'text' => $bText,
      ];
    }

    return $result;
  }

  public static function write(): void {
    // Gleiches JSON-Format wie der API-Endpunkt search_index,
    // damit site.js beide Quellen identisch verarbeiten kann
    $json = json_encode(
      ['ok' => true, 'data' => ['pages' => self::build()]],
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) return;

    Storage::writeFileAtomic(Storage::root() . '/public/search-index.json', $json);
  }

  private static function blocksToPlaintext(array $blocks): string {
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
              $sub = self::blocksToPlaintext($colBlocks);
              if ($sub !== '') $parts[] = $sub;
            }
          }
        }
      }
    }

    return implode(' ', $parts);
  }
}
