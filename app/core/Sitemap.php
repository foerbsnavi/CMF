<?php
declare(strict_types=1);

namespace App\Core;

final class Sitemap {
  public static function write(): void {
    $pagesIndex = Storage::readJson('content/pages.json');
    $site = Storage::readJson('config/site.json');
    $baseUrl = rtrim((string)($site['baseUrl'] ?? ''), '/');
    $pages = $pagesIndex['pages'] ?? [];

    self::writeSitemap($baseUrl, $pages);
    self::writeRobots($baseUrl);
    self::writeFeed($baseUrl, $site);
    self::writeLlms($baseUrl, $site, $pages);
    // Such-Index als statische Datei mitgenerieren — die Content-Dateien
    // sind durch den Storage-Cache in diesem Request bereits gelesen
    SearchIndex::write();
  }

  /** RSS-2.0-Feed der veroeffentlichten Blog-Posts (statische Datei). */
  private static function writeFeed(string $baseUrl, array $site): void {
    $blogIndex = Storage::readJson('content/blog.json');
    $blogPrefix = trim((string)($blogIndex['slug'] ?? 'blog'));
    $siteName = (string)($site['name'] ?? '');
    $lang = (string)($site['lang'] ?? 'de');

    $posts = array_values(array_filter($blogIndex['posts'] ?? [], fn($p) => ($p['status'] ?? 'draft') === 'published'));
    usort($posts, fn(array $a, array $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
    $posts = array_slice($posts, 0, 20);

    $items = '';
    foreach ($posts as $p) {
      $title = htmlspecialchars((string)($p['title'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
      $desc = htmlspecialchars((string)($p['description'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
      $url = htmlspecialchars($baseUrl . '/' . $blogPrefix . '/' . (string)($p['slug'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
      $ts = strtotime((string)($p['created'] ?? ''));
      $pubDate = $ts !== false ? date(DATE_RSS, $ts) : '';

      $items .= "  <item>\n"
        . "    <title>{$title}</title>\n"
        . "    <link>{$url}</link>\n"
        . "    <guid isPermaLink=\"true\">{$url}</guid>\n"
        . ($pubDate !== '' ? "    <pubDate>{$pubDate}</pubDate>\n" : '')
        . ($desc !== '' ? "    <description>{$desc}</description>\n" : '')
        . "  </item>\n";
    }

    $channelTitle = htmlspecialchars($siteName . ' — News', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $channelLink = htmlspecialchars($baseUrl . '/' . $blogPrefix, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
      . "<rss version=\"2.0\">\n<channel>\n"
      . "  <title>{$channelTitle}</title>\n"
      . "  <link>{$channelLink}</link>\n"
      . "  <description>{$channelTitle}</description>\n"
      . "  <language>" . htmlspecialchars($lang, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</language>\n"
      . $items
      . "</channel>\n</rss>\n";

    $path = Storage::root() . '/public/feed.xml';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $xml);
    rename($tmp, $path);
  }

  /**
   * llms.txt nach llmstxt.org: Wegweiser fuer KI-Agenten und LLM-Crawler.
   * Jede Seite ist zusaetzlich als Markdown unter {url}.md erreichbar.
   */
  private static function writeLlms(string $baseUrl, array $site, array $pages): void {
    $siteName = (string)($site['name'] ?? 'Webseite');

    // Beschreibung der Startseite als Kurzfassung verwenden
    $homeDesc = '';
    foreach ($pages as $p) {
      if (($p['slug'] ?? '') === 'home') {
        $homeData = Storage::readJson('content/pages/' . ($p['id'] ?? '') . '.json');
        $homeDesc = trim((string)($homeData['meta']['description'] ?? ''));
        break;
      }
    }

    $txt = '# ' . $siteName . "\n\n";
    if ($homeDesc !== '') $txt .= '> ' . $homeDesc . "\n\n";
    $txt .= "Jede Seite dieser Website ist als Markdown erreichbar: einfach `.md` an die URL anhaengen.\n"
      . "Die komplette Website ist ueber eine REST-API lesbar und schreibbar: {$baseUrl}/api.php (Doku: {$baseUrl}/api-anleitung.md).\n\n";

    $txt .= "## Seiten\n\n";
    foreach ($pages as $p) {
      if (($p['status'] ?? 'draft') !== 'published') continue;
      $slug = (string)($p['slug'] ?? '');
      $title = trim((string)($p['title'] ?? $slug));
      $pageData = Storage::readJson('content/pages/' . ($p['id'] ?? '') . '.json');
      $robots = trim((string)($pageData['meta']['robots'] ?? ''));
      if (str_contains($robots, 'noindex')) continue;
      $desc = trim((string)($pageData['meta']['description'] ?? ''));
      $url = $slug === 'home' ? $baseUrl . '/home.md' : $baseUrl . '/' . $slug . '.md';
      $txt .= '- [' . $title . '](' . $url . ')' . ($desc !== '' ? ': ' . $desc : '') . "\n";
    }

    $blogIndex = Storage::readJson('content/blog.json');
    $blogPrefix = trim((string)($blogIndex['slug'] ?? 'blog'));
    $posts = array_values(array_filter($blogIndex['posts'] ?? [], fn($p) => ($p['status'] ?? 'draft') === 'published'));
    if ($posts !== []) {
      usort($posts, fn(array $a, array $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
      $txt .= "\n## News\n\n";
      foreach ($posts as $p) {
        $title = trim((string)($p['title'] ?? ''));
        $slug = trim((string)($p['slug'] ?? ''));
        $desc = trim((string)($p['description'] ?? ''));
        if ($title === '' || $slug === '') continue;
        // noindex-Posts nicht in die llms.txt aufnehmen (analog zu Seiten)
        $postData = Storage::readJson('content/blog/' . ($p['id'] ?? '') . '.json');
        $robots = trim((string)($postData['meta']['robots'] ?? ''));
        if (str_contains($robots, 'noindex')) continue;
        $txt .= '- [' . $title . '](' . $baseUrl . '/' . $blogPrefix . '/' . $slug . '.md)' . ($desc !== '' ? ': ' . $desc : '') . "\n";
      }
    }

    $path = Storage::root() . '/public/llms.txt';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $txt);
    rename($tmp, $path);
  }

  private static function writeSitemap(string $baseUrl, array $pages): void {
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
      . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

    foreach ($pages as $p) {
      if (($p['status'] ?? 'draft') !== 'published') continue;
      // noindex-Seiten nicht in die Sitemap aufnehmen
      $pageData = Storage::readJson('content/pages/' . ($p['id'] ?? '') . '.json');
      $robots = trim((string)($pageData['meta']['robots'] ?? ''));
      if (str_contains($robots, 'noindex')) continue;
      $slug = (string)($p['slug'] ?? '');
      $url = $slug === 'home' ? $baseUrl . '/' : $baseUrl . '/' . $slug;
      $lastmod = self::lastmod((string)($p['updated'] ?? ''), 'content/pages/' . ($p['id'] ?? '') . '.json');
      $priority = $slug === 'home' ? '1.0' : '0.8';

      $xml .= "  <url>\n"
        . "    <loc>" . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n"
        . ($lastmod !== '' ? "    <lastmod>{$lastmod}</lastmod>\n" : '')
        . "    <changefreq>weekly</changefreq>\n"
        . "    <priority>{$priority}</priority>\n"
        . "  </url>\n";
    }

    // Blog-Posts
    $blogIndex = Storage::readJson('content/blog.json');
    $blogPrefix = trim((string)($blogIndex['slug'] ?? 'blog'));
    foreach ($blogIndex['posts'] ?? [] as $bp) {
      if (($bp['status'] ?? 'draft') !== 'published') continue;
      $bpData = Storage::readJson('content/blog/' . ($bp['id'] ?? '') . '.json');
      $bpRobots = trim((string)($bpData['meta']['robots'] ?? ''));
      if (str_contains($bpRobots, 'noindex')) continue;
      $bpSlug = (string)($bp['slug'] ?? '');
      $bpUrl = $baseUrl . '/' . $blogPrefix . '/' . $bpSlug;
      $bpLastmod = self::lastmod((string)($bp['updated'] ?? ''), 'content/blog/' . ($bp['id'] ?? '') . '.json');

      $xml .= "  <url>\n"
        . "    <loc>" . htmlspecialchars($bpUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n"
        . ($bpLastmod !== '' ? "    <lastmod>{$bpLastmod}</lastmod>\n" : '')
        . "    <changefreq>weekly</changefreq>\n"
        . "    <priority>0.6</priority>\n"
        . "  </url>\n";
    }

    $xml .= "</urlset>";

    $path = Storage::root() . '/public/sitemap.xml';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $xml);
    rename($tmp, $path);
  }

  /**
   * lastmod aus Index-Datum und Datei-Aenderungszeit der Content-Datei —
   * reine Inhaltsaenderungen aktualisieren das Index-Datum nicht immer,
   * der Datei-Timestamp faengt das ab.
   */
  private static function lastmod(string $updated, string $contentFile): string {
    $fromIndex = $updated !== '' ? substr($updated, 0, 10) : '';
    $file = Storage::root() . '/' . $contentFile;
    $fromFile = is_file($file) ? date('Y-m-d', (int)filemtime($file)) : '';
    // String-Vergleich funktioniert fuer Y-m-d
    return max($fromIndex, $fromFile);
  }

  private static function writeRobots(string $baseUrl): void {
    $sitemap = $baseUrl !== '' ? "Sitemap: {$baseUrl}/sitemap.xml\n" : '';
    $txt = "User-agent: *\nDisallow: /admin.php\nDisallow: /api.php\n{$sitemap}";

    $path = Storage::root() . '/public/robots.txt';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $txt);
    rename($tmp, $path);
  }
}
