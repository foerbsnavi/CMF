<?php
declare(strict_types=1);

namespace App\Core;

final class Sanitizer {
  public static function html(string $html): string {
    $allowed = ['a','b','strong','i','em','u','br','p','ul','ol','li','code','pre','span','small','sup','sub'];
    $html = strip_tags($html, $allowed);
    // Event-Handler und formaction entfernen (quoted und unquoted)
    $html = preg_replace('/\s(?:on\w+|formaction)\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\s(?:on\w+|formaction)\s*=\s*'[^']*'/i", '', $html) ?? $html;
    $html = preg_replace('/\s(?:on\w+|formaction)\s*=\s*[^\s>]+/i', '', $html) ?? $html;
    // href/src gegen eine SCHEMA-WHITELIST pruefen — nicht per Blacklist. Die alte
    // Blacklist ("javascript"/"data" suchen) war per Entity oder Steuerzeichen umgehbar
    // (z.B. java&#115;cript: oder java<TAB>script:). safeUrl() entschluesselt beides vorher.
    $decode = fn(string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace_callback('/\b(href|src)\s*=\s*"([^"]*)"/i',
      fn(array $m): string => $m[1] . '="' . self::safeUrl($decode($m[2])) . '"', $html) ?? $html;
    $html = preg_replace_callback("/\\b(href|src)\\s*=\\s*'([^']*)'/i",
      fn(array $m): string => $m[1] . '="' . self::safeUrl($decode($m[2])) . '"', $html) ?? $html;
    $html = preg_replace_callback('/\b(href|src)\s*=\s*([^\s>"\']+)/i',
      fn(array $m): string => $m[1] . '="' . self::safeUrl($decode($m[2])) . '"', $html) ?? $html;
    // style-Attribut entfernen (verhindert CSS-Expressions)
    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', $html) ?? $html;
    return $html;
  }

  /**
   * Prueft eine URL gegen eine Schema-WHITELIST und gibt sie attribut-sicher
   * (htmlspecialchars) zurueck. Erlaubt: relative/verankerte Ziele ohne Schema
   * sowie http, https, mailto, tel. Alles andere (javascript:, data:, vbscript: …)
   * wird auf '#' neutralisiert. Steuerzeichen werden vor der Pruefung entfernt,
   * damit sie kein Schema verschleiern koennen (java<TAB>script:).
   */
  public static function safeUrl(string $url): string {
    $probe = preg_replace('/[\x00-\x20]+/', '', $url) ?? $url;
    $hasScheme = (bool)preg_match('#^[a-z][a-z0-9+.\-]*:#i', $probe);
    if ($hasScheme) {
      $lower = strtolower($probe);
      $ok = str_starts_with($lower, 'http://')
        || str_starts_with($lower, 'https://')
        || str_starts_with($lower, 'mailto:')
        || str_starts_with($lower, 'tel:');
      if (!$ok) {
        return '#';
      }
    }
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Entschärft eine hochgeladene SVG-Datei: Script-Elemente, Event-Handler,
   * javascript:-URLs und foreignObject werden entfernt.
   */
  public static function svgFile(string $file): void {
    $svg = @file_get_contents($file);
    if ($svg === false || $svg === '') return;
    // script/style/foreignObject auch mit Namespace-Prefix (z.B. <svg:script>)
    $svg = preg_replace('#<(?:\w+:)?script\b[^>]*>.*?</(?:\w+:)?script>#is', '', $svg) ?? $svg;
    $svg = preg_replace('#<(?:\w+:)?script\b[^>]*/?>#i', '', $svg) ?? $svg;
    $svg = preg_replace('#<(?:\w+:)?style\b[^>]*>.*?</(?:\w+:)?style>#is', '', $svg) ?? $svg;
    $svg = preg_replace('#<(?:\w+:)?foreignObject\b.*?</(?:\w+:)?foreignObject>#is', '', $svg) ?? $svg;
    $svg = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $svg) ?? $svg;
    $svg = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $svg) ?? $svg;
    $svg = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', $svg) ?? $svg;
    // javascript: und data: in href/xlink:href neutralisieren
    $svg = preg_replace('/\b((?:xlink:)?href)\s*=\s*"[^"]*(?:javascript|data)\s*:[^"]*"/i', '$1="#"', $svg) ?? $svg;
    $svg = preg_replace("/\b((?:xlink:)?href)\s*=\s*'[^']*(?:javascript|data)\s*:[^']*'/i", "$1='#'", $svg) ?? $svg;
    @file_put_contents($file, $svg);
  }
}
