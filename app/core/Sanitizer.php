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
    // javascript: und data: URLs in href/src entfernen
    $html = preg_replace('/\b(href|src)\s*=\s*"[^"]*(?:javascript|data)\s*:[^"]*"/i', '$1="#"', $html) ?? $html;
    $html = preg_replace("/\b(href|src)\s*=\s*'[^']*(?:javascript|data)\s*:[^']*'/i", "$1='#'", $html) ?? $html;
    $html = preg_replace('/\b(href|src)\s*=\s*(?:javascript|data)\s*:[^\s>]*/i', '$1="#"', $html) ?? $html;
    // style-Attribut entfernen (verhindert CSS-Expressions)
    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', $html) ?? $html;
    return $html;
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
