<?php
declare(strict_types=1);

namespace App\Core;

final class Auth {
  private const BASE_WAIT   = 5;
  private const MAX_WAIT    = 3600;
  private const STORE       = 'config/login_attempts.json';

  public static function requireLogin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!empty($_SESSION['_admin'])) return;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      Csrf::check();

      $remaining = self::lockedFor();
      if ($remaining > 0) {
        self::renderLogin('Zu viele Fehlversuche. Bitte warten.', $remaining);
        exit;
      }

      $cfg = Storage::readJson('config/users.json');
      $users = $cfg['users'] ?? [];
      $inUser = trim((string)($_POST['user'] ?? ''));
      $inPass = (string)($_POST['pass'] ?? '');

      foreach ($users as $user) {
        $u = (string)($user['user'] ?? '');
        $hash = (string)($user['pass_hash'] ?? '');

        if ($u !== '' && $hash !== '' && hash_equals($u, $inUser) && password_verify($inPass, $hash)) {
          self::clearAttempts();
          session_regenerate_id(true);
          $_SESSION['_admin'] = 1;
          $_SESSION['_admin_user'] = $u;
          header('Location: /admin.php');
          exit;
        }
      }

      $wait = self::recordFailure();
      self::renderLogin('Login fehlgeschlagen. Nächster Versuch in ' . $wait . ' Sekunden.', $wait);
      exit;
    }

    self::renderLogin('');
    exit;
  }

  public static function logout(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: /admin.php');
    exit;
  }

  private static function ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  }

  private static function lockedFor(): int {
    $data = Storage::readJson(self::STORE);
    $entry = $data[self::ip()] ?? null;
    if (!is_array($entry)) return 0;
    $until = (int)($entry['locked_until'] ?? 0);
    $remaining = $until - time();
    return $remaining > 0 ? $remaining : 0;
  }

  private static function recordFailure(): int {
    $data = Storage::readJson(self::STORE);
    $ip = self::ip();
    $entry = is_array($data[$ip] ?? null) ? $data[$ip] : ['count' => 0];
    $count = (int)($entry['count'] ?? 0) + 1;
    $wait = min((int)round(self::BASE_WAIT ** $count), self::MAX_WAIT);
    $data[$ip] = ['count' => $count, 'locked_until' => time() + $wait];
    self::cleanup($data);
    Storage::writeJson(self::STORE, $data);
    return $wait;
  }

  private static function clearAttempts(): void {
    $data = Storage::readJson(self::STORE);
    unset($data[self::ip()]);
    Storage::writeJson(self::STORE, $data);
  }

  private static function cleanup(array &$data): void {
    $now = time();
    foreach ($data as $ip => $entry) {
      if (!is_array($entry) || (int)($entry['locked_until'] ?? 0) < $now - self::MAX_WAIT) {
        unset($data[$ip]);
      }
    }
  }

  private static function renderLogin(string $msg, int $lockedFor = 0): void {
    $csrf = Csrf::token();
    $m = $msg !== '' ? '<p><strong id="login-msg">'.htmlspecialchars($msg, ENT_QUOTES).'</strong></p>' : '';
    $disabled = $lockedFor > 0 ? ' disabled' : '';
    $countdown = '';
    if ($lockedFor > 0) {
      $countdown = '<script>'
        . 'var s=' . $lockedFor . ','
        . 'el=document.getElementById("login-msg"),'
        . 'btn=document.querySelector("button[type=submit]"),'
        . 't=setInterval(function(){'
        .   's--;'
        .   'if(s<=0){'
        .     'clearInterval(t);'
        .     'el.textContent="Jetzt erneut versuchen.";'
        .     'btn.disabled=false;'
        .   '}else{'
        .     'el.textContent=' . json_encode(explode('.', $msg)[0] . '. Noch ') . '+s+" Sekunde"+(s===1?"":"n")+".";'
        .   '}'
        . '},1000);'
        . '</script>';
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title>'
      . '<link rel="stylesheet" href="/assets/css/base.css"><link rel="stylesheet" href="/assets/css/theme.css">'
      . '<style>main{max-width:520px;margin:0 auto;padding:16px}input{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit}label{display:block;margin:0 0 10px 0}.actions{display:flex;gap:10px;flex-wrap:wrap}</style>'
      . '</head><body><main>'
      . '<h1>Admin</h1>'.$m
      . '<form method="post" action="/admin.php">'
      . '<input type="hidden" name="_csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES).'">'
      . '<label>User<br><input name="user" value="" autocomplete="username"></label>'
      . '<label>Passwort<br><input type="password" name="pass" autocomplete="current-password"></label>'
      . '<div class="actions"><button class="btn primary" type="submit"'.$disabled.'>Login</button><a class="btn" href="/">Zur Seite</a></div>'
      . '</form>'
      . $countdown
      . '</main></body></html>';
  }
}