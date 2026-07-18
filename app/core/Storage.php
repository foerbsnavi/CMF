<?php
declare(strict_types=1);

namespace App\Core;

final class Storage {
  private static array $cache = [];

  public static function root(): string {
    return dirname(__DIR__, 2);
  }

  public static function ensureDirs(): void {
    $dirs = [
      self::root() . '/content/pages',
      self::root() . '/content/blog',
      self::root() . '/content/globals',
      self::root() . '/config',
      self::root() . '/public/media',
      self::root() . '/public/assets',
      self::root() . '/public/assets/css',
      self::root() . '/public/assets/fonts',
    ];
    foreach ($dirs as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
  }

  public static function readJson(string $path): array {
    $key = ltrim($path, '/');
    if (isset(self::$cache[$key])) return self::$cache[$key];
    $full = self::root() . '/' . $key;
    if (!is_file($full)) return [];
    $raw = file_get_contents($full);
    $data = json_decode($raw ?: '[]', true);
    $result = is_array($data) ? $data : [];
    self::$cache[$key] = $result;
    return $result;
  }

  public static function writeJson(string $path, array $data): void {
    $key = ltrim($path, '/');
    $full = self::root() . '/' . $key;
    $dir = dirname($full);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $full . '.tmp.' . bin2hex(random_bytes(6));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
    $fp = fopen($tmp, 'wb');
    if ($fp === false) throw new \RuntimeException('Cannot write');
    if (!flock($fp, LOCK_EX)) { fclose($fp); throw new \RuntimeException('Lock failed'); }
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    rename($tmp, $full);
    // Cache aktualisieren
    self::$cache[$key] = $data;
  }

  /**
   * Schreibt eine (abgeleitete) Datei atomar per temp+rename und MELDET Fehler
   * per error_log, statt sie stillschweigend zu verschlucken. $path ist ein
   * absoluter Pfad. Gibt true bei Erfolg zurueck. Gedacht fuer die generierten
   * public-Dateien (feed.xml, llms.txt, sitemap.xml, robots.txt, theme.css …),
   * deren fehlgeschlagenes Schreiben (z.B. public/ read-only) sonst unbemerkt bleibt.
   */
  public static function writeFileAtomic(string $path, string $contents): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      error_log('CMF: Verzeichnis nicht anlegbar (Rechte?): ' . $dir);
      return false;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents) === false) {
      error_log('CMF: Schreiben fehlgeschlagen (Rechte auf ' . $dir . '?): ' . $path);
      return false;
    }
    if (!@rename($tmp, $path)) {
      @unlink($tmp);
      error_log('CMF: Umbenennen fehlgeschlagen: ' . $path);
      return false;
    }
    return true;
  }
}
