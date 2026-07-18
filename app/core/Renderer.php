<?php
declare(strict_types=1);

namespace App\Core;

final class Renderer {
  public static function renderPage(string $slug, array $pageData, array $pagesIndex): string {
    $site = Storage::readJson('config/site.json');
    $lang = $site['lang'] ?? 'de';
    $siteName = $site['name'] ?? 'Webseiten CMS';
    $baseUrl = rtrim((string)($site['baseUrl'] ?? ''), '/');

    $meta = $pageData['meta'] ?? [];
    $pageTitle = trim((string)($meta['title'] ?? ''));
    $title = $pageTitle !== '' && $pageTitle !== $siteName
      ? $pageTitle . ' | ' . $siteName
      : $siteName;
    $desc = trim((string)($meta['description'] ?? '')) ?: '';
    $robots = trim((string)($meta['robots'] ?? ''));

    $canonical = $baseUrl !== ''
      ? ($slug === 'home' ? $baseUrl . '/' : $baseUrl . '/' . $slug)
      : '';

    $blocks = is_array($pageData['content']['blocks'] ?? null) ? $pageData['content']['blocks'] : [];
    $postImage = trim((string)($pageData['_post']['image'] ?? ''));
    $rawImage = trim((string)($meta['image'] ?? '')) ?: $postImage ?: self::firstImageSrc($blocks) ?: trim((string)($site['og_image'] ?? ''));
    $ogImage = ($rawImage !== '' && $baseUrl !== '' && !str_starts_with($rawImage, 'http'))
      ? $baseUrl . '/' . ltrim($rawImage, '/')
      : $rawImage;

    $header = Storage::readJson('content/globals/header.json');
    $footer = Storage::readJson('content/globals/footer.json');

    $navHtml = self::renderNav($slug, $pagesIndex);
    $headerHtml = self::renderBlocks($header['content']['blocks'] ?? []);
    $footerHtml = self::renderBlocks($footer['content']['blocks'] ?? []);
    $mainHtml = self::renderBlocks($blocks);

    $head = self::head($title, $desc, $lang, $siteName, $canonical, $ogImage, $robots)
      . self::jsonLd($slug, $pageData, $site, $canonical, $ogImage);

    $siteJsFile = Storage::root() . '/public/assets/js/site.js';
    $siteJsV = is_file($siteJsFile) ? (string)filemtime($siteJsFile) : '1';

    return "<!doctype html><html lang=\"" . self::e($lang) . "\"><head>{$head}</head><body>"
      . "<a class=\"skip-link\" href=\"#main-content\">Zum Inhalt springen</a>"
      . "<header>{$headerHtml}</header>"
      . "<nav aria-label=\"Hauptnavigation\">{$navHtml}</nav>"
      . "<main id=\"main-content\">{$mainHtml}</main>"
      . "<footer>{$footerHtml}</footer>"
      . "<script src=\"/assets/js/site.js?v=" . $siteJsV . "\" defer></script>"
      . "</body></html>";
  }

  private static function head(string $title, string $desc, string $lang, string $siteName, string $canonical, string $ogImage, string $robots = ''): string {
    $t = self::e($title);
    $d = self::e($desc);
    $c = self::e($canonical);
    $i = self::e($ogImage);
    $sn = self::e($siteName);
    $r = self::e($robots);
    $twitterCard = $ogImage !== '' ? 'summary_large_image' : 'summary';
    return "<meta charset=\"utf-8\">"
      . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
      . "<title>{$t}</title>"
      . ($d !== '' ? "<meta name=\"description\" content=\"{$d}\">" : '')
      . ($r !== '' ? "<meta name=\"robots\" content=\"{$r}\">" : '')
      . ($c !== '' ? "<link rel=\"canonical\" href=\"{$c}\">" : '')
      . "<meta property=\"og:type\" content=\"website\">"
      . "<meta property=\"og:title\" content=\"{$t}\">"
      . ($d !== '' ? "<meta property=\"og:description\" content=\"{$d}\">" : '')
      . ($c !== '' ? "<meta property=\"og:url\" content=\"{$c}\">" : '')
      . ($sn !== '' ? "<meta property=\"og:site_name\" content=\"{$sn}\">" : '')
      . ($i !== '' ? "<meta property=\"og:image\" content=\"{$i}\">" : '')
      . "<meta name=\"twitter:card\" content=\"{$twitterCard}\">"
      . "<meta name=\"twitter:title\" content=\"{$t}\">"
      . ($d !== '' ? "<meta name=\"twitter:description\" content=\"{$d}\">" : '')
      . ($i !== '' ? "<meta name=\"twitter:image\" content=\"{$i}\">" : '')
      . "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"News-Feed\" href=\"/feed.xml\">"
      . self::cssLink('/assets/css/base.css')
      . self::cssLink('/assets/css/theme.css')
      . self::cssLink('/assets/css/custom.css');
  }

  /**
   * Strukturierte Daten (ld+json): WebSite auf der Startseite,
   * SoftwareApplication nur wenn site.json "software_schema": true setzt,
   * Article fuer Blog-Posts (Index-Daten kommen vom Router als _post mit).
   */
  private static function jsonLd(string $slug, array $pageData, array $site, string $canonical, string $ogImage): string {
    $siteName = (string)($site['name'] ?? '');
    $baseUrl = rtrim((string)($site['baseUrl'] ?? ''), '/');
    $desc = trim((string)($pageData['meta']['description'] ?? ''));
    $schemas = [];

    if ($slug === 'home' && $baseUrl !== '') {
      $schemas[] = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'url' => $baseUrl . '/'
      ];

      if (!empty($site['software_schema'])) {
        $version = Storage::readJson('version.json');
        $schemas[] = [
          '@context' => 'https://schema.org',
          '@type' => 'SoftwareApplication',
          'name' => $siteName,
          'applicationCategory' => 'DeveloperApplication',
          'operatingSystem' => 'Webserver mit PHP 8.1 oder neuer',
          'softwareVersion' => (string)($version['version'] ?? ''),
          'url' => $baseUrl . '/',
          'downloadUrl' => $baseUrl . '/files/cmf_latest.zip',
          'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
          'description' => $desc
        ];
      }
    }

    $post = $pageData['_post'] ?? null;
    if (is_array($post) && $canonical !== '') {
      $article = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => (string)($post['title'] ?? ''),
        'description' => (string)($post['description'] ?? ''),
        'mainEntityOfPage' => $canonical,
        'author' => ['@type' => 'Organization', 'name' => $siteName],
        'publisher' => ['@type' => 'Organization', 'name' => $siteName]
      ];
      $created = trim((string)($post['created'] ?? ''));
      $updated = trim((string)($post['updated'] ?? ''));
      if ($created !== '') $article['datePublished'] = $created;
      if ($updated !== '') $article['dateModified'] = $updated;
      if ($ogImage !== '') $article['image'] = $ogImage;
      $schemas[] = $article;
    }

    $out = '';
    foreach ($schemas as $schema) {
      // JSON_HEX_TAG verhindert Script-Breakout durch </script> in Inhalten
      $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
      if ($json !== false) {
        $out .= '<script type="application/ld+json">' . $json . '</script>';
      }
    }
    return $out;
  }

  private static function firstImageSrc(array $blocks): string {
    foreach ($blocks as $b) {
      $type = (string)($b['type'] ?? '');
      if ($type === 'image') {
        $src = trim((string)($b['data']['src'] ?? ''));
        if ($src !== '') return $src;
      }
      if ($type === 'columns') {
        foreach ($b['data']['items'] ?? [] as $colBlocks) {
          if (is_array($colBlocks)) {
            $src = self::firstImageSrc($colBlocks);
            if ($src !== '') return $src;
          }
        }
      }
    }
    return '';
  }

  private static function renderNav(string $slug, array $pagesIndex): string {
    $pages = $pagesIndex['pages'] ?? [];
    $items = [];

    foreach ($pages as $p) {
      if (($p['status'] ?? 'draft') !== 'published') continue;
      $nav = is_array($p['nav'] ?? null) ? $p['nav'] : [];
      if (!($nav['show'] ?? true)) continue;

      $items[] = [
        'id' => (string)($p['id'] ?? ''),
        'slug' => (string)($p['slug'] ?? ''),
        'title' => (string)($p['title'] ?? ''),
        'nav' => [
          'show' => (bool)($nav['show'] ?? true),
          'order' => (int)($nav['order'] ?? 0),
          'label' => array_key_exists('label', $nav) && $nav['label'] !== null ? trim((string)$nav['label']) : null,
          'parent' => array_key_exists('parent', $nav) && $nav['parent'] !== null ? trim((string)$nav['parent']) : null
        ]
      ];
    }

    usort($items, fn(array $a, array $b) => ((int)$a['nav']['order'] <=> (int)$b['nav']['order']));

    $byParent = [];
    foreach ($items as $item) {
      $parent = $item['nav']['parent'] ?? null;
      $parent = $parent !== '' ? $parent : null;
      $byParent[$parent ?? '__root__'][] = $item;
    }

    $menu = self::renderNavLevel($slug, $byParent, null);

    if ($menu === '') {
      return '';
    }

    $searchHtml = '<li class="nav-item nav-search"><div class="search-wrap" id="search-wrap" role="search">'
      . '<input type="text" class="search-input" id="search-input" placeholder="Suchen..." aria-label="Suche">'
      . '<div class="search-results" id="search-results" aria-live="polite"></div>'
      . '</div></li>';

    // Suchfeld nur in die ERSTE (Haupt-) <ul> einfügen, nicht in Submenus
    $lastClose = strrpos($menu, '</ul>');
    if ($lastClose !== false) {
      $menu = substr_replace($menu, $searchHtml . '</ul>', $lastClose, 5);
    }

    return '<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="nav-menu"><span class="bars"></span><span class="sr-only">Menü</span></button>'
      . '<div class="nav-menu-wrap" id="nav-menu">'
      . $menu
      . '</div>';
  }

  private static function renderNavLevel(string $slug, array $byParent, ?string $parentId): string {
    $key = $parentId ?? '__root__';
    $items = $byParent[$key] ?? [];

    if ($items === []) {
      return '';
    }

    $class = $parentId === null ? 'nav-menu' : 'nav-submenu';
    $html = "<ul class=\"{$class}\">";

    foreach ($items as $item) {
      $id = (string)$item['id'];
      $itemSlug = (string)$item['slug'];
      $label = $item['nav']['label'] ?? null;
      $label = $label !== null && $label !== '' ? $label : ($item['title'] !== '' ? $item['title'] : $itemSlug);
      $href = $itemSlug === 'home' ? '/' : '/' . $itemSlug;
      $childrenHtml = self::renderNavLevel($slug, $byParent, $id);
      $hasChildren = $childrenHtml !== '';
      $isCurrent = $itemSlug === $slug;
      $isAncestor = !$isCurrent && (self::navTreeContainsSlug($byParent, $id, $slug)
        || ($itemSlug !== '' && $itemSlug !== 'home' && str_starts_with($slug, $itemSlug . '/')));
      $currentAttr = $isCurrent ? ' aria-current="page"' : ($isAncestor ? ' aria-current="true"' : '');
      $liClass = $hasChildren ? ' class="nav-item has-children"' : ' class="nav-item"';

      if ($hasChildren) {
        $html .= "<li{$liClass}>"
          . "<div class=\"nav-link-row\">"
          . "<a href=\"" . self::e($href) . "\"{$currentAttr}>" . self::e($label) . "</a>"
          . "<button class=\"nav-subtoggle\" type=\"button\" aria-expanded=\"false\" aria-label=\"Untermenü für " . self::e($label) . " umschalten\">▼</button>"
          . "</div>"
          . $childrenHtml
          . "</li>";
        continue;
      }

      $html .= "<li{$liClass}><a href=\"" . self::e($href) . "\"{$currentAttr}>" . self::e($label) . "</a></li>";
    }

    $html .= '</ul>';
    return $html;
  }

  private static function navTreeContainsSlug(array $byParent, string $parentId, string $slug, int $depth = 0): bool {
    if ($depth > 10) return false;
    $items = $byParent[$parentId] ?? [];
    foreach ($items as $item) {
      if ((string)$item['slug'] === $slug) {
        return true;
      }
      if (self::navTreeContainsSlug($byParent, (string)$item['id'], $slug, $depth + 1)) {
        return true;
      }
    }
    return false;
  }

  public static function renderBlocks(array $blocks): string {
    $out = '';
    foreach ($blocks as $b) $out .= self::renderBlock($b);
    return $out;
  }

  private static function renderBlock(array $b): string {
    $type = (string)($b['type'] ?? '');
    $data = $b['data'] ?? [];
    return match($type) {
      'heading' => self::heading($data),
      'text' => self::text($data),
      'image' => self::image($data),
      'list' => self::lst($data),
      'buttons' => self::buttons($data),
      'columns' => self::columns($data),
      'html' => self::rawHtml($data),
      'blog_overview' => self::blogOverview($data),
      default => ''
    };
  }

  private static function heading(array $d): string {
    $lvl = (int)($d['level'] ?? 2);
    if ($lvl < 1 || $lvl > 6) $lvl = 2;
    $text = self::e((string)($d['text'] ?? ''));
    return "<section><h{$lvl}>{$text}</h{$lvl}></section>";
  }

  private static function text(array $d): string {
    $html = (string)($d['html'] ?? '');
    $html = Sanitizer::html($html);
    return "<div>{$html}</div>";
  }

  private static function image(array $d): string {
    $src = self::e((string)($d['src'] ?? ''));
    if ($src === '') return '';
    $alt = self::e((string)($d['alt'] ?? ''));
    $cap = trim((string)($d['caption'] ?? ''));
    $loading = self::e((string)($d['loading'] ?? 'lazy'));
    $w = (int)($d['width'] ?? 0);
    $h = (int)($d['height'] ?? 0);
    $dim = ($w > 0 && $h > 0) ? " width=\"{$w}\" height=\"{$h}\"" : '';
    $img = "<img src=\"{$src}\" alt=\"{$alt}\"{$dim} loading=\"{$loading}\">";
    if ($cap !== '') return "<div><figure>{$img}<figcaption>" . self::e($cap) . "</figcaption></figure></div>";
    return "<div>{$img}</div>";
  }

  private static function lst(array $d): string {
    $ordered = (bool)($d['ordered'] ?? false);
    $items = $d['items'] ?? [];
    if (!is_array($items) || !$items) return '';
    $tag = $ordered ? 'ol' : 'ul';
    $lis = '';
    foreach ($items as $it) $lis .= "<li>" . self::e((string)$it) . "</li>";
    return "<div><{$tag}>{$lis}</{$tag}></div>";
  }

  private static function buttons(array $d): string {
    $items = $d['items'] ?? [];
    if (!is_array($items) || !$items) return '';
    $out = '<div>';
    foreach ($items as $it) {
      $label = self::e((string)($it['label'] ?? ''));
      // href gegen Schema-Whitelist (blockt javascript:/data: etc., liefert bereits escaped)
      $href = Sanitizer::safeUrl((string)($it['href'] ?? '#'));
      $style = (string)($it['style'] ?? '');
      $cls = 'btn' . ($style === 'primary' ? ' primary' : '');
      $out .= "<a class=\"{$cls}\" href=\"{$href}\">{$label}</a> ";
    }
    return $out . '</div>';
  }

  private static function columns(array $d): string {
    $cols = (int)($d['columns'] ?? 2);
    if ($cols < 2 || $cols > 5) $cols = 2;
    $items = $d['items'] ?? [];
    if (!is_array($items) || count($items) !== $cols) return '';
    $cells = '';
    foreach ($items as $colBlocks) {
      $cells .= "<div>" . self::renderBlocks(is_array($colBlocks) ? $colBlocks : []) . "</div>";
    }
    return "<div><div class=\"cols cols-{$cols}\">{$cells}</div></div>";
  }

  private static function rawHtml(array $d): string {
    $code = (string)($d['code'] ?? '');
    return "<section>{$code}</section>";
  }

  private static function blogOverview(array $d): string {
    $blogIndex = Storage::readJson('content/blog.json');
    $blogSlug = trim((string)($blogIndex['slug'] ?? 'blog'));
    $filterCategory = trim((string)($d['category'] ?? ''));
    $posts = $blogIndex['posts'] ?? [];
    usort($posts, fn(array $a, array $b) => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));

    $cards = '';
    foreach ($posts as $post) {
      if (($post['status'] ?? 'draft') !== 'published') continue;
      if ($filterCategory !== '' && trim((string)($post['category'] ?? '')) !== $filterCategory) continue;

      $slug = self::e((string)($post['slug'] ?? ''));
      $title = self::e((string)($post['title'] ?? ''));
      $desc = self::e((string)($post['description'] ?? ''));
      $image = self::e((string)($post['image'] ?? ''));
      $href = '/' . $blogSlug . '/' . $slug;

      $imgHtml = $image !== ''
        ? '<div class="blog-card-image"><img src="' . $image . '" alt="" loading="lazy"></div>'
        : '';

      // Veroeffentlichungsdatum (created) als <time>-Element
      $dateHtml = '';
      $created = trim((string)($post['created'] ?? ''));
      if ($created !== '') {
        $ts = strtotime($created);
        if ($ts !== false) {
          $dateHtml = '<time class="blog-card-date" datetime="' . date('Y-m-d', $ts) . '">' . date('d.m.Y', $ts) . '</time>';
        }
      }

      $cards .= '<a class="blog-card" href="' . $href . '" aria-label="' . $title . '">'
        . $imgHtml
        . '<div class="blog-card-body">'
        . $dateHtml
        . '<h3 class="blog-card-title">' . $title . '</h3>'
        . ($desc !== '' ? '<p class="blog-card-desc">' . $desc . '</p>' : '')
        . '</div></a>';
    }

    if ($cards === '') {
      return '<section><p>Noch keine Blogbeitraege vorhanden.</p></section>';
    }

    return '<section><div class="blog-grid">' . $cards . '</div></section>';
  }

  private static function cssLink(string $path): string {
    $file = Storage::root() . '/public' . $path;
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return '<link rel="stylesheet" href="' . self::e($path) . '?v=' . $v . '">';
  }

  private static function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}