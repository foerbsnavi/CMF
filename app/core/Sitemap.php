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
      $updated = (string)($p['updated'] ?? '');
      $lastmod = $updated !== '' ? substr($updated, 0, 10) : '';
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
      $bpUpdated = (string)($bp['updated'] ?? '');
      $bpLastmod = $bpUpdated !== '' ? substr($bpUpdated, 0, 10) : '';

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

  private static function writeRobots(string $baseUrl): void {
    $sitemap = $baseUrl !== '' ? "Sitemap: {$baseUrl}/sitemap.xml\n" : '';
    $txt = "User-agent: *\nAllow: /\n{$sitemap}";

    $path = Storage::root() . '/public/robots.txt';
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $txt);
    rename($tmp, $path);
  }
}
