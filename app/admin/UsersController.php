<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Storage;

final class UsersController {
  public function index(): void {
    $cfg = Storage::readJson('config/users.json');
    $users = $cfg['users'] ?? [];
    $rows = '';

    foreach ($users as $user) {
      $name = htmlspecialchars((string)($user['user'] ?? ''), ENT_QUOTES);
      $rawUser = (string)($user['user'] ?? '');
      $roles = is_array($user['roles'] ?? null) ? $user['roles'] : [];
      $hasApi = in_array('api', $roles, true) && !empty($user['api_tokens']);
      $type = $hasApi ? 'Login + API' : 'Login';

      $rows .= "<tr>";
      $rows .= "<td>{$name}</td>";
      $rows .= "<td>" . htmlspecialchars($type, ENT_QUOTES) . "</td>";
      $rows .= "<td class=\"actions\">";

      if (count($users) <= 1) {
        $rows .= "<small>Letzter Benutzer</small>";
      } else {
        $rows .= "<form method=\"post\" action=\"/admin.php?a=user_delete\" style=\"display:inline\" onsubmit=\"return confirm('Benutzer löschen?')\">"
          . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
          . "<input type=\"hidden\" name=\"user\" value=\"{$name}\">"
          . "<button class=\"btn\" type=\"submit\">Löschen</button>"
          . "</form>";
      }

      $rows .= "</td></tr>";
    }

    $flash = '';
    if (!empty($_SESSION['_user_api_token'])) {
      $token = htmlspecialchars((string)$_SESSION['_user_api_token'], ENT_QUOTES);
      unset($_SESSION['_user_api_token']);

      $flash = "<div class=\"notice\" style=\"margin-bottom:16px;padding:12px;border:1px solid rgba(127,127,127,.25);border-radius:10px\">"
        . "<strong>API-Token erzeugt</strong><br>"
        . "<code style=\"display:block;margin-top:8px;word-break:break-all\">{$token}</code>"
        . "<small style=\"display:block;margin-top:8px\">Dieses Token wird nur jetzt angezeigt. Danach ist es weg wie ein guter Bug nach dem Fix.</small>"
        . "</div>";
    }

    $content = $flash
      . "<div class=\"cols cols-2\">"
      . "<div>"
      . "<h2>Benutzer</h2>"
      . "<table><thead><tr><th>Benutzername</th><th>Typ</th><th>Aktion</th></tr></thead><tbody>{$rows}</tbody></table>"
      . "</div>"
      . "<div>"
      . "<h2>Neuen Zugang anlegen</h2>"
      . "<form method=\"post\" action=\"/admin.php?a=user_create\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<label>Benutzername<br><input type=\"text\" name=\"user\" required></label>"
      . "<label>Passwort<br><input type=\"password\" name=\"pass\" required></label>"
      . "<label style=\"display:flex;gap:10px;align-items:center;margin-top:12px\">"
      . "<input type=\"checkbox\" name=\"api_enabled\" value=\"1\">"
      . "<span>API-Zugang anlegen</span>"
      . "</label>"
      . "<div class=\"actions\" style=\"margin-top:14px\">"
      . "<button class=\"btn primary\" type=\"submit\">Benutzer anlegen</button>"
      . "</div>"
      . "</form>"
      . "</div>"
      . "<div>"
      . "<h2>Passwort ändern</h2>"
      . "<form method=\"post\" action=\"/admin.php?a=user_password\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<label>Benutzername<br><select name=\"user\">"
      . implode('', array_map(fn($u) => '<option value="' . htmlspecialchars((string)($u['user'] ?? ''), ENT_QUOTES) . '">' . htmlspecialchars((string)($u['user'] ?? ''), ENT_QUOTES) . '</option>', $users))
      . "</select></label>"
      . "<label>Neues Passwort<br><input type=\"password\" name=\"pass\" required></label>"
      . "<div class=\"actions\" style=\"margin-top:14px\">"
      . "<button class=\"btn primary\" type=\"submit\">Passwort speichern</button>"
      . "</div>"
      . "</form>"
      . "</div>"
      . "</div>";

    $this->render('Benutzer', $content);
  }

  public function create(): void {
    Csrf::check();

    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $apiEnabled = (string)($_POST['api_enabled'] ?? '') === '1';

    if ($user === '' || $pass === '') {
      header('Location: /admin.php?a=users');
      exit;
    }

    $user = strtolower($user);
    $user = preg_replace('/[^a-z0-9_\-\.]/', '-', $user) ?? $user;
    $user = trim(preg_replace('/-+/', '-', $user) ?? $user, '-');

    if ($user === '') {
      header('Location: /admin.php?a=users');
      exit;
    }

    $cfg = Storage::readJson('config/users.json');
    $users = $cfg['users'] ?? [];

    foreach ($users as $existing) {
      if (hash_equals((string)($existing['user'] ?? ''), $user)) {
        header('Location: /admin.php?a=users');
        exit;
      }
    }

    $newUser = [
      'user' => $user,
      'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
      'roles' => ['admin']
    ];

    if ($apiEnabled) {
      $token = $this->generateApiToken();

      $newUser['roles'] = ['admin', 'api'];
      $newUser['api_tokens'] = [
        [
          'name' => 'main',
          'token_hash' => hash('sha256', $token),
          'enabled' => true,
          'created' => date('c')
        ]
      ];

      $_SESSION['_user_api_token'] = $token;
    }

    $users[] = $newUser;

    $cfg['users'] = array_values($users);
    Storage::writeJson('config/users.json', $cfg);

    header('Location: /admin.php?a=users');
    exit;
  }

  public function password(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin.php?a=users'); exit; }
    Csrf::check();

    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');

    if ($user === '' || $pass === '') {
      header('Location: /admin.php?a=users');
      exit;
    }

    $cfg = Storage::readJson('config/users.json');
    $users = $cfg['users'] ?? [];

    foreach ($users as &$u) {
      if ((string)($u['user'] ?? '') === $user) {
        $u['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        break;
      }
    }
    unset($u);

    $cfg['users'] = $users;
    Storage::writeJson('config/users.json', $cfg);

    $_SESSION['_flash'] = 'Passwort für ' . $user . ' geändert.';
    header('Location: /admin.php?a=users');
    exit;
  }

  public function delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin.php?a=users'); exit; }
    Csrf::check();

    $user = trim((string)($_POST['user'] ?? ''));

    if ($user === '') {
      header('Location: /admin.php?a=users');
      exit;
    }

    $cfg = Storage::readJson('config/users.json');
    $users = $cfg['users'] ?? [];

    if (count($users) <= 1) {
      header('Location: /admin.php?a=users');
      exit;
    }

    $users = array_values(array_filter($users, fn($u) => (string)($u['user'] ?? '') !== $user));

    $cfg['users'] = $users;
    Storage::writeJson('config/users.json', $cfg);

    header('Location: /admin.php?a=users');
    exit;
  }

  private function generateApiToken(): string {
    return 'cms_' . bin2hex(random_bytes(24));
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}
