<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf {
  public static function token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['_csrf'];
  }

  public static function check(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || $t === '' || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $t)) {
      http_response_code(403);
      echo "CSRF";
      exit;
    }
  }
}
