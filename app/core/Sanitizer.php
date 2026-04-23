<?php
declare(strict_types=1);

namespace App\Core;

final class Sanitizer {
  public static function html(string $html): string {
    $allowed = '<a><b><strong><i><em><u><br><p><ul><ol><li><code><pre><span><small><sup><sub>';
    $html = strip_tags($html, $allowed);
    // Event-Handler entfernen (quoted und unquoted)
    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $html) ?? $html;
    $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', $html) ?? $html;
    // javascript: und data: URLs in href/src entfernen
    $html = preg_replace('/\b(href|src)\s*=\s*"[^"]*(?:javascript|data)\s*:[^"]*"/i', '$1="#"', $html) ?? $html;
    $html = preg_replace("/\b(href|src)\s*=\s*'[^']*(?:javascript|data)\s*:[^']*'/i", "$1='#'", $html) ?? $html;
    $html = preg_replace('/\b(href|src)\s*=\s*(?:javascript|data)\s*:[^\s>]*/i', '$1="#"', $html) ?? $html;
    // style-Attribut entfernen (verhindert CSS-Expressions)
    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', $html) ?? $html;
    return $html;
  }
}
