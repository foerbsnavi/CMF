<?php
declare(strict_types=1);

namespace App\Core;

final class Theme {
  public static function ensureThemeCss(): void {
    $stylesPath = Storage::root() . '/config/styles.json';
    $cssPath = Storage::root() . '/public/assets/css/theme.css';
    // Nur neu generieren wenn styles.json neuer als theme.css
    if (is_file($cssPath) && is_file($stylesPath) && filemtime($cssPath) >= filemtime($stylesPath)) {
      return;
    }
    $cfg = Storage::readJson('config/styles.json');
    if (!$cfg) return;
    self::writeThemeCss($cfg);
  }

  public static function writeThemeCss(array $cfg): void {
    $fonts = self::normalizeFonts($cfg['fonts'] ?? []);

    $vars = [
      '--container' => $cfg['container'] ?? '1100px',
      '--pad' => $cfg['pad'] ?? '16px',
      '--gap' => $cfg['gap'] ?? '16px',

      '--radius-sm' => $cfg['radius']['sm'] ?? '8px',
      '--radius-md' => $cfg['radius']['md'] ?? '14px',
      '--radius-lg' => $cfg['radius']['lg'] ?? '22px',

      '--color-bg' => $cfg['colors']['bg'] ?? '#ffffff',
      '--color-text' => $cfg['colors']['text'] ?? '#111111',
      '--color-muted' => $cfg['colors']['muted'] ?? '#666666',
      '--color-border' => $cfg['colors']['border'] ?? '#dddddd',
      '--color-primary' => $cfg['colors']['primary'] ?? '#0d6efd',
      '--color-secondary' => $cfg['colors']['secondary'] ?? '#ff0000',
      '--color-primary-text' => $cfg['colors']['primary_text'] ?? '#ffffff',
      '--color-link' => $cfg['colors']['link'] ?? '#0d6efd',

      '--font-size' => $cfg['type']['body'] ?? '14px',
      '--font-body' => $fonts['body_css'],
      '--font-body-weight' => $fonts['body_weight'],
      '--font-heading' => $fonts['heading_css'],
      '--font-heading-weight' => $fonts['heading_weight'],

      '--h1' => $cfg['type']['h1'] ?? '2.1rem',
      '--h2' => $cfg['type']['h2'] ?? '1.6rem',
      '--h3' => $cfg['type']['h3'] ?? '1.25rem',
      '--h4' => $cfg['type']['h4'] ?? '1.1rem',
      '--h5' => $cfg['type']['h5'] ?? '1.0rem',
    ];

    $root = '';
    foreach ($vars as $k => $v) $root .= $k . ':' . $v . ';';

    $css = self::fontFaceCss()
      . ":root{{$root}}"
      . "body{background-color:var(--color-bg);color:var(--color-text);font-size:var(--font-size);font-family:var(--font-body);font-weight:var(--font-body-weight)}"
      . "button,input,select,textarea{font:inherit}"
      . "a{color:var(--color-link)}"
      . "header,footer,nav{border-color:var(--color-border)}"
      . "nav a{color:var(--color-link)}"
      . "nav a:hover{text-decoration:underline}"
      . ".nav-submenu{border-color:var(--color-border);border-radius:var(--radius-md)}"
      . ".nav-subtoggle{color:var(--color-primary)}"
      . ".btn{border:1px solid var(--color-border);border-radius:var(--radius-md)}"
      . ".btn.primary{background:var(--color-primary);color:var(--color-primary-text);border-color:var(--color-primary)}"
      . "img{border-radius:var(--radius-md)}"
      . "code{color:var(--color-secondary)}"
      . "h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);font-weight:var(--font-heading-weight)}"
      . "h1{font-size:var(--h1);color:var(--color-secondary)}"
      . "h2{font-size:var(--h2);color:var(--color-secondary)}"
      . "h3{font-size:var(--h3);color:var(--color-secondary)}"
      . "h4{font-size:var(--h4);color:var(--color-secondary)}"
      . "h5{font-size:var(--h5);color:var(--color-secondary)}";

    $cssPath = Storage::root() . '/public/assets/css/theme.css';
    $tmp = $cssPath . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $css);
    rename($tmp, $cssPath);
  }

  public static function availableFonts(): array {
    $dir = Storage::root() . '/public/assets/fonts';
    $out = [];

    if (!is_dir($dir)) {
      return $out;
    }

    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
      if (!$file->isFile()) {
        continue;
      }

      $ext = strtolower((string)$file->getExtension());
      if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
        continue;
      }

      $full = str_replace('\\', '/', $file->getPathname());
      $relative = str_replace(str_replace('\\', '/', Storage::root() . '/public'), '', $full);
      $family = self::fontFamilyFromFilename((string)$file->getBasename('.' . $ext));
      $key = strtolower($family . '|' . $relative);

      $out[$key] = [
        'family' => $family,
        'path' => $relative,
        'format' => self::cssFormatForExtension($ext),
        'weight' => self::detectWeightFromName((string)$file->getBasename('.' . $ext)),
        'style' => self::detectStyleFromName((string)$file->getBasename('.' . $ext)),
      ];
    }

    usort($out, function (array $a, array $b): int {
      $cmp = strcmp($a['family'], $b['family']);
      return $cmp !== 0 ? $cmp : strcmp($a['path'], $b['path']);
    });

    return array_values($out);
  }

  private static function fontFaceCss(): string {
    $css = '';
    $seen = [];

    foreach (self::availableFonts() as $font) {
      $signature = strtolower($font['family'] . '|' . $font['weight'] . '|' . $font['style'] . '|' . $font['path']);
      if (isset($seen[$signature])) {
        continue;
      }
      $seen[$signature] = true;

      $css .= '@font-face{'
        . 'font-family:' . self::quoteCssString($font['family']) . ';'
        . 'src:url(' . self::quoteCssString($font['path']) . ') format(' . self::quoteCssString($font['format']) . ');'
        . 'font-weight:' . $font['weight'] . ';'
        . 'font-style:' . $font['style'] . ';'
        . 'font-display:swap;'
        . '}';
    }

    return $css;
  }

  private static function normalizeFonts(mixed $fonts): array {
    $fonts = is_array($fonts) ? $fonts : [];
    $body = trim((string)($fonts['body'] ?? ''));
    $heading = trim((string)($fonts['heading'] ?? ''));

    return [
      'body' => $body,
      'body_weight' => self::weightToCss((string)($fonts['body_weight'] ?? 'regular')),
      'heading' => $heading,
      'heading_weight' => self::weightToCss((string)($fonts['heading_weight'] ?? 'regular')),
      'body_css' => self::fontStack($body),
      'heading_css' => self::fontStack($heading),
    ];
  }

  private static function weightToCss(string $weight): string {
    return match (strtolower($weight)) {
      'light' => '300',
      'bold'  => '700',
      default => '400',
    };
  }

  private static function fontStack(string $font): string {
    $fallback = 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';
    return $font !== '' ? self::quoteCssString($font) . ',' . $fallback : $fallback;
  }

  private static function fontFamilyFromFilename(string $filename): string {
    $base = preg_replace('/[-_]+/', ' ', $filename) ?? $filename;
    $base = preg_replace('/\b(thin|extralight|ultralight|light|regular|book|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique)\b/i', '', $base) ?? $base;
    $base = trim((string)(preg_replace('/\s+/', ' ', $base) ?? $base));
    return $base !== '' ? $base : $filename;
  }

  private static function detectWeightFromName(string $filename): string {
    $name = strtolower($filename);
    return match (true) {
      str_contains($name, 'thin') => '100',
      str_contains($name, 'extralight'), str_contains($name, 'ultralight') => '200',
      str_contains($name, 'light') => '300',
      str_contains($name, 'medium') => '500',
      str_contains($name, 'semibold'), str_contains($name, 'demibold') => '600',
      str_contains($name, 'extrabold'), str_contains($name, 'ultrabold') => '800',
      str_contains($name, 'black'), str_contains($name, 'heavy') => '900',
      str_contains($name, 'bold') => '700',
      default => '400',
    };
  }

  private static function detectStyleFromName(string $filename): string {
    $name = strtolower($filename);
    return str_contains($name, 'italic') || str_contains($name, 'oblique') ? 'italic' : 'normal';
  }

  private static function cssFormatForExtension(string $ext): string {
    return match ($ext) {
      'woff2' => 'woff2',
      'woff' => 'woff',
      'ttf' => 'truetype',
      'otf' => 'opentype',
      default => $ext,
    };
  }

  private static function quoteCssString(string $value): string {
    return '"' . addcslashes($value, "\\\"") . '"';
  }
}
