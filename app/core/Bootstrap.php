<?php
declare(strict_types=1);

spl_autoload_register(function(string $class): void {
  $prefix = 'App\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
  $rel = substr($class, strlen($prefix));
  $parts = explode('\\', $rel);
  if (isset($parts[0])) $parts[0] = strtolower($parts[0]);
  $path = __DIR__ . '/../' . implode('/', $parts) . '.php';
  if (is_file($path)) require $path;
});

use App\Core\Storage;
use App\Core\Theme;
use App\Core\Sitemap;

// Session-Härtung: gilt für ALLE später (in Auth/Csrf) gestarteten Sessions.
// Muss vor dem ersten session_start() stehen — Bootstrap läuft als Erstes.
// secure nur unter HTTPS setzen, damit ein reiner HTTP-Zugang (Dev/LAN) nicht das
// Admin-Cookie verliert; die Live-Seite erzwingt HTTPS ohnehin per .htaccess.
$httpsOn = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
  || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
@ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => $httpsOn,
]);

Storage::ensureDirs();

// Maintenance-Modus: Blockiert alle Requests ausser Admin-Update
$maintenanceFile = Storage::root() . '/config/.maintenance';
if (is_file($maintenanceFile)) {
  $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $action = $_GET['a'] ?? '';
  $isAdminUpdate = $scriptName === 'admin.php' && in_array($action, ['update_run', 'update_rollback', 'settings'], true);
  $isApiVersionCheck = $scriptName === 'api.php' && $action === 'version_check';
  if (!$isAdminUpdate && !$isApiVersionCheck) {
    http_response_code(503);
    header('Retry-After: 60');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wartung</title><style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f8f9fb;color:#1c1c1c}div{text-align:center;max-width:400px;padding:40px}</style></head><body><div><h1>Wartungsarbeiten</h1><p>Das System wird gerade aktualisiert. Bitte versuche es in einer Minute erneut.</p></div></body></html>';
    exit;
  }
}

Theme::ensureThemeCss();

// Abgeleitete public-Dateien bei Erststart erzeugen (z. B. frische Installation aus dem ZIP):
// feed.xml und llms.txt liegen dem ZIP NICHT bei und entstünden sonst erst beim ersten
// Speichern im Admin — die beworbene KI-/Markdown-Funktion (llms.txt) und der RSS-Feed
// wären bis dahin tot. Fehlt eine der beiden, einmalig alle Generatoren anstoßen
// (Sitemap::write erzeugt sitemap.xml, robots.txt, feed.xml, llms.txt und search-index.json).
if (!is_file(Storage::root() . '/public/feed.xml') || !is_file(Storage::root() . '/public/llms.txt')) {
  Sitemap::write();
}
