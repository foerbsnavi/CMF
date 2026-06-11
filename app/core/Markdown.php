<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Markdown-Ausgabe fuer Seiten und Blog-Posts.
 * Jede veroeffentlichte Seite ist unter {url}.md als sauberes Markdown
 * erreichbar — fuer KI-Agenten, LLM-Crawler und alle, die Inhalte
 * maschinenlesbar beziehen wollen (siehe auch /llms.txt).
 */
final class Markdown {

  /** Komplette Seite als Markdown mit Kopfzeilen. */
  public static function page(string $slug, array $pageData, string $baseUrl): string {
    $meta = is_array($pageData['meta'] ?? null) ? $pageData['meta'] : [];
    $title = trim((string)($meta['title'] ?? ''));
    $desc = trim((string)($meta['description'] ?? ''));
    $blocks = is_array($pageData['content']['blocks'] ?? null) ? $pageData['content']['blocks'] : [];

    $url = $baseUrl !== ''
      ? ($slug === 'home' ? $baseUrl . '/' : $baseUrl . '/' . $slug)
      : '';

    $out = '# ' . $title . "\n\n";
    if ($desc !== '') $out .= '> ' . $desc . "\n\n";
    if ($url !== '') $out .= 'Kanonische URL: ' . $url . "\n\n---\n\n";

    $out .= self::blocks($blocks);

    return rtrim($out) . "\n";
  }

  public static function blocks(array $blocks): string {
    $out = '';
    foreach ($blocks as $b) {
      $part = self::block(is_array($b) ? $b : []);
      if ($part !== '') $out .= $part . "\n\n";
    }
    return $out;
  }

  private static function block(array $b): string {
    $type = (string)($b['type'] ?? '');
    $d = is_array($b['data'] ?? null) ? $b['data'] : [];

    switch ($type) {
      case 'heading':
        $lvl = (int)($d['level'] ?? 2);
        if ($lvl < 1 || $lvl > 6) $lvl = 2;
        return str_repeat('#', $lvl) . ' ' . trim((string)($d['text'] ?? ''));

      case 'text':
        return self::htmlToMd((string)($d['html'] ?? ''));

      case 'html':
        return self::htmlToMd((string)($d['code'] ?? ''));

      case 'image':
        $src = trim((string)($d['src'] ?? ''));
        if ($src === '') return '';
        $alt = trim((string)($d['alt'] ?? ''));
        $cap = trim((string)($d['caption'] ?? ''));
        $md = '![' . $alt . '](' . $src . ')';
        if ($cap !== '') $md .= "\n*" . $cap . '*';
        return $md;

      case 'list':
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $ordered = (bool)($d['ordered'] ?? false);
        $lines = [];
        $n = 1;
        foreach ($items as $it) {
          $lines[] = ($ordered ? ($n++ . '. ') : '- ') . trim((string)$it);
        }
        return implode("\n", $lines);

      case 'buttons':
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $lines = [];
        foreach ($items as $it) {
          $label = trim((string)($it['label'] ?? ''));
          $href = trim((string)($it['href'] ?? ''));
          if ($label === '' || $href === '') continue;
          $lines[] = '- [' . $label . '](' . $href . ')';
        }
        return implode("\n", $lines);

      case 'columns':
        $cols = is_array($d['items'] ?? null) ? $d['items'] : [];
        $parts = [];
        foreach ($cols as $colBlocks) {
          if (!is_array($colBlocks)) continue;
          $md = trim(self::blocks($colBlocks));
          if ($md !== '') $parts[] = $md;
        }
        return implode("\n\n", $parts);

      case 'blog_overview':
        $idx = Storage::readJson('content/blog.json');
        $blogSlug = trim((string)($idx['slug'] ?? 'blog'));
        $posts = $idx['posts'] ?? [];
        usort($posts, fn(array $a, array $b) => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
        $lines = [];
        foreach ($posts as $post) {
          if (($post['status'] ?? 'draft') !== 'published') continue;
          $t = trim((string)($post['title'] ?? ''));
          $s = trim((string)($post['slug'] ?? ''));
          $desc = trim((string)($post['description'] ?? ''));
          if ($t === '' || $s === '') continue;
          $lines[] = '- [' . $t . '](/' . $blogSlug . '/' . $s . '.md)' . ($desc !== '' ? ': ' . $desc : '');
        }
        return implode("\n", $lines);

      default:
        return '';
    }
  }

  /** Pragmatische HTML-zu-Markdown-Umwandlung fuer Text- und HTML-Bloecke. */
  private static function htmlToMd(string $html): string {
    // Links zuerst, bevor Tags entfernt werden
    $html = preg_replace_callback(
      '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
      fn(array $m) => '[' . trim(strip_tags($m[3])) . '](' . $m[2] . ')',
      $html
    ) ?? $html;

    // Bilder
    $html = preg_replace_callback(
      '#<img\b[^>]*src\s*=\s*(["\'])(.*?)\1[^>]*>#i',
      function (array $m) {
        $alt = '';
        if (preg_match('/alt\s*=\s*(["\'])(.*?)\1/i', $m[0], $am)) $alt = $am[2];
        return '![' . $alt . '](' . $m[2] . ')';
      },
      $html
    ) ?? $html;

    // Ueberschriften in HTML-Bloecken
    $html = preg_replace_callback(
      '#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
      fn(array $m) => "\n\n" . str_repeat('#', (int)$m[1]) . ' ' . trim(strip_tags($m[2])) . "\n\n",
      $html
    ) ?? $html;

    // Auszeichnungen
    $html = preg_replace('#</?(strong|b)\b[^>]*>#i', '**', $html) ?? $html;
    $html = preg_replace('#</?(em|i)\b[^>]*>#i', '*', $html) ?? $html;
    $html = preg_replace('#</?code\b[^>]*>#i', '`', $html) ?? $html;

    // Listen
    $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
    $html = preg_replace('#</li>#i', '', $html) ?? $html;

    // Absaetze und Umbrueche
    $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
    $html = preg_replace('#</(ul|ol|div|section|table|tr)>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</(td|th)>#i', ' ', $html) ?? $html;

    // Restliche Tags entfernen, Entities aufloesen
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Whitespace aufraeumen: Zeilen trimmen, max. eine Leerzeile
    $lines = array_map(fn(string $l) => rtrim($l), explode("\n", $text));
    $text = implode("\n", $lines);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
  }
}
