<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Content-Security-Policy — dynamisch von PHP gesendet, damit sie pro
 * Installation erweiterbar ist (statt fest in public/.htaccess).
 *
 * Die BASIS ist streng und identisch mit der bisherigen .htaccess-Policy.
 * Eine Installation kann in config/site.json unter "csp" je Direktive
 * zusaetzliche Quellen erlauben (z.B. eine externe Widget-Domain), ohne die
 * Basis aufzuweichen und ohne dass das oeffentliche CMF-Produkt fremde
 * Domains mitliefert.
 *
 * Beispiel config/site.json:
 *   "csp": {
 *     "script_src":  ["https://widget.example.com"],
 *     "connect_src": ["https://widget.example.com", "wss://widget.example.com"]
 *   }
 *
 * Sicherheit: Jede konfigurierte Quelle wird streng gegen eine Whitelist
 * gesaeubert (nur bekannte Keywords, Schemata und Host-Quellen). Alles mit
 * Leerzeichen, Semikolon, Anfuehrungszeichen oder Steuerzeichen wird
 * verworfen — so kann ueber die Konfig weder eine neue Direktive noch ein
 * unerwuenschtes Keyword (z.B. 'unsafe-eval') noch ein CRLF-Header-Break
 * eingeschleust werden.
 */
final class Csp {

  /** Strenge Basis-Policy (identisch zur bisherigen .htaccess-CSP). */
  private const BASE = [
    'default-src'     => ["'self'"],
    'img-src'         => ["'self'", 'data:', 'https:'],
    'media-src'       => ["'self'", 'https:'],
    'font-src'        => ["'self'"],
    'style-src'       => ["'self'", "'unsafe-inline'"],
    'script-src'      => ["'self'", "'unsafe-inline'"],
    'connect-src'     => ["'self'"],
    'frame-src'       => ["'self'", 'https:'],
    'frame-ancestors' => ["'self'"],
    'base-uri'        => ["'self'"],
    'form-action'     => ["'self'"],
    'object-src'      => ["'none'"],
  ];

  /** Welche Konfig-Schluessel welche Direktive erweitern duerfen. */
  private const EXTENDABLE = [
    'script_src'  => 'script-src',
    'connect_src' => 'connect-src',
    'style_src'   => 'style-src',
    'img_src'     => 'img-src',
    'font_src'    => 'font-src',
    'media_src'   => 'media-src',
    'frame_src'   => 'frame-src',
  ];

  /** Sendet den CSP-Header (einmal, nur wenn noch keine Header raus sind). */
  public static function send(): void {
    if (headers_sent()) return;
    header('Content-Security-Policy: ' . self::build());
  }

  /** Baut die Policy-Zeichenkette aus Basis + gesaeuberter Konfig. */
  public static function build(): string {
    $dirs = self::BASE;
    $site = Storage::readJson('config/site.json');
    $csp = is_array($site['csp'] ?? null) ? $site['csp'] : [];

    foreach (self::EXTENDABLE as $cfgKey => $directive) {
      $extra = $csp[$cfgKey] ?? null;
      if (!is_array($extra)) continue;
      foreach ($extra as $src) {
        if (!is_string($src)) continue;
        $safe = self::sanitizeSource($src);
        if ($safe !== '' && !in_array($safe, $dirs[$directive], true)) {
          $dirs[$directive][] = $safe;
        }
      }
    }

    $parts = [];
    foreach ($dirs as $name => $vals) {
      $parts[] = $name . ' ' . implode(' ', $vals);
    }
    return implode('; ', $parts);
  }

  /**
   * Laesst nur sichere CSP-Quell-Ausdruecke durch, sonst ''.
   * Erlaubt: die Keywords 'self'/'none'/'unsafe-inline'; reine Schemata
   * (https: http: wss: ws: data: blob:); scheme://host[:port]; sowie
   * Host-Quellen (optional *.-Subdomain, optional :port). Bewusst NICHT
   * erlaubt: 'unsafe-eval', 'unsafe-hashes', beliebige Keywords, Pfade,
   * und alles mit Leerzeichen/Semikolon/Quote/Steuerzeichen.
   */
  private static function sanitizeSource(string $s): string {
    $s = trim($s);
    if ($s === '' || strlen($s) > 200) return '';
    // Harte Ablehnung bei allem, was Header/Direktive brechen koennte.
    if (preg_match('/[\s;,"\\\\\x00-\x1f]/', $s)) return '';

    if (in_array($s, ["'self'", "'none'", "'unsafe-inline'"], true)) return $s;
    if (preg_match('/^(https?|wss?|data|blob):$/i', $s)) return $s;
    if (preg_match('#^(https?|wss?)://\*?[a-z0-9.-]+(:[0-9]{1,5})?$#i', $s)) return $s;
    if (preg_match('/^(\*\.)?[a-z0-9][a-z0-9.-]*(:[0-9]{1,5})?$/i', $s)) return $s;
    return '';
  }
}
