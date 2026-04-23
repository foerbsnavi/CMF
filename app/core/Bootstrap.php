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
