<?php
declare(strict_types=1);

namespace App\Core;

final class ApiAuth {
  public static function requireToken(): array {
    $token = self::readBearerToken();

    if ($token === '') {
      self::deny('missing_token');
    }

    $cfg = Storage::readJson('config/users.json');
    $users = $cfg['users'] ?? [];
    $tokenSha256 = hash('sha256', $token);

    foreach ($users as $user) {
      $userName = trim((string)($user['user'] ?? ''));
      if ($userName === '') {
        continue;
      }

      $roles = $user['roles'] ?? [];
      if (is_array($roles) && !in_array('api', $roles, true) && !in_array('admin', $roles, true)) {
        continue;
      }

      $tokens = $user['api_tokens'] ?? [];
      if (!is_array($tokens)) {
        continue;
      }

      foreach ($tokens as $entry) {
        if (!is_array($entry)) {
          continue;
        }

        $hash = trim((string)($entry['token_hash'] ?? ''));
        if ($hash === '') {
          continue;
        }

        if (!hash_equals($hash, $tokenSha256)) {
          continue;
        }

        if (($entry['enabled'] ?? true) === false) {
          self::deny('token_disabled');
        }

        return [
          'user' => $userName,
          'roles' => is_array($roles) ? array_values($roles) : [],
          'token_name' => trim((string)($entry['name'] ?? '')),
        ];
      }
    }

    self::deny('invalid_token');
  }

  private static function readBearerToken(): string {
    $header = self::readAuthHeader();

    if (preg_match('/Bearer\s+(.+)$/i', $header, $m) === 1) {
      return trim((string)$m[1]);
    }

    $fallback = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    if ($fallback !== '') {
      return $fallback;
    }

    return '';
  }

  private static function readAuthHeader(): string {
    $headers = [
      $_SERVER['HTTP_AUTHORIZATION'] ?? '',
      $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    foreach ($headers as $header) {
      $header = trim((string)$header);
      if ($header !== '') {
        return $header;
      }
    }

    if (function_exists('getallheaders')) {
      $all = getallheaders();
      if (is_array($all)) {
        foreach ($all as $name => $value) {
          if (strcasecmp((string)$name, 'Authorization') === 0) {
            return trim((string)$value);
          }
        }
      }
    }

    return '';
  }

  private static function deny(string $error): never {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="CMS API"');
    echo json_encode([
      'ok' => false,
      'error' => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}