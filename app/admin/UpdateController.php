<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Storage;

final class UpdateController {

  /**
   * Erlaubte Update-Server. Updates werden nur von diesen Hosts
   * und nur per HTTPS geladen — verhindert, dass eine manipulierte
   * update_url in version.json fremden Code nachlaedt.
   */
  private const ALLOWED_UPDATE_HOSTS = [
    'cmf.brosemedien.de',
  ];

  /** Dateien/Ordner die beim Update ersetzt werden */
  private const SYSTEM_PATHS = [
    'app',
    'public/index.php',
    'public/admin.php',
    'public/api.php',
    'public/.htaccess',
    'public/assets/css/base.css',
    'public/assets/css/admin.css',
    'public/assets/js',
    'public/assets/fonts',
    'LICENSE',
    'version.json',
  ];

  /** Dateien die NIEMALS ueberschrieben werden */
  private const PROTECTED_PATHS = [
    'content',
    'config/users.json',
    'config/site.json',
    'config/styles.json',
    'config/login_attempts.json',
    'public/media',
    'public/assets/css/custom.css',
    'public/assets/css/theme.css',
    'public/files',
    'public/sitemap.xml',
    'public/robots.txt',
  ];

  public function check(): void {
    $local = Storage::readJson('version.json');
    $localVersion = (string)($local['version'] ?? '0.0.0');
    $updateUrl = $this->validatedUpdateUrl();

    $remote = null;
    $error = null;

    if ($updateUrl !== null) {
      $remote = $this->fetchRemoteVersion($updateUrl . '/api.php?a=version_check');
      if ($remote === null) {
        $error = 'Verbindung zum Update-Server fehlgeschlagen.';
      }
    } else {
      $error = 'Kein gueltiger Update-Server konfiguriert (update_url in version.json fehlt oder ist nicht erlaubt).';
    }

    $remoteVersion = $remote ? (string)($remote['version'] ?? '0.0.0') : null;
    $updateAvailable = $remoteVersion !== null && version_compare($remoteVersion, $localVersion, '>');
    $changelog = $remote['changelog'] ?? [];
    $downloadUrl = $remote ? ($updateUrl . (string)($remote['download_url'] ?? '/files/cmf_latest.zip')) : '';

    $this->renderCheckResult($localVersion, $remoteVersion, $updateAvailable, $changelog, $downloadUrl, $error);
  }

  public function run(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=settings');
      exit;
    }

    Csrf::check();

    $updateUrl = $this->validatedUpdateUrl();

    if ($updateUrl === null) {
      $_SESSION['_flash'] = 'Kein gueltiger Update-Server konfiguriert.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    $remote = $this->fetchRemoteVersion($updateUrl . '/api.php?a=version_check');
    if ($remote === null) {
      $_SESSION['_flash'] = 'Update-Server nicht erreichbar.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    $downloadUrl = $updateUrl . (string)($remote['download_url'] ?? '/files/cmf_latest.zip');
    $root = Storage::root();
    $tempDir = sys_get_temp_dir() . '/cmf_update_' . bin2hex(random_bytes(8));
    $tempZip = $tempDir . '/update.zip';

    try {
      // 1. Maintenance-Modus aktivieren
      @file_put_contents($root . '/config/.maintenance', date('c'));

      // 2. ZIP herunterladen
      @mkdir($tempDir, 0775, true);
      $downloaded = $this->downloadFile($downloadUrl, $tempZip);
      if (!$downloaded) {
        throw new \RuntimeException('Download fehlgeschlagen: ' . $downloadUrl);
      }

      // 3. ZIP entpacken
      $zip = new \ZipArchive();
      if ($zip->open($tempZip) !== true) {
        throw new \RuntimeException('ZIP konnte nicht geoeffnet werden.');
      }

      // Zip-Slip Schutz
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false) continue;
        if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_starts_with($entry, '\\')) {
          $zip->close();
          throw new \RuntimeException('Ungueltige Pfade im Update-ZIP.');
        }
      }

      $extractDir = $tempDir . '/extracted';
      @mkdir($extractDir, 0775, true);
      $zip->extractTo($extractDir);
      $zip->close();

      // Zweite Verteidigungslinie: alle entpackten Pfade muessen
      // per realpath innerhalb des Extraktionsordners liegen
      $this->assertInsideDir($extractDir);

      // 4. Validieren: Wichtige Dateien vorhanden?
      if (!is_dir($extractDir . '/app') || !is_file($extractDir . '/public/index.php')) {
        throw new \RuntimeException('Update-Paket unvollstaendig (app/ oder index.php fehlt).');
      }

      // 5. Backup erstellen
      $backupDir = $root . '/config/.update_backup_' . date('Ymd_His');
      @mkdir($backupDir, 0775, true);
      $this->copyDir($root . '/app', $backupDir . '/app');
      @copy($root . '/version.json', $backupDir . '/version.json');

      // 6. System-Dateien ersetzen
      // app/ komplett ersetzen
      $this->deleteDir($root . '/app');
      $this->copyDir($extractDir . '/app', $root . '/app');

      // Public-Dateien einzeln ersetzen
      $publicFiles = ['index.php', 'admin.php', 'api.php', '.htaccess'];
      foreach ($publicFiles as $f) {
        $src = $extractDir . '/public/' . $f;
        if (is_file($src)) {
          @copy($src, $root . '/public/' . $f);
        }
      }

      // Assets ersetzen (base.css, admin.css, JS, Fonts) — NICHT custom.css und theme.css
      $assetDirs = ['js', 'fonts'];
      foreach ($assetDirs as $d) {
        $src = $extractDir . '/public/assets/' . $d;
        $dest = $root . '/public/assets/' . $d;
        if (is_dir($src)) {
          $this->deleteDir($dest);
          $this->copyDir($src, $dest);
        }
      }

      // CSS einzeln (nur base.css und admin.css)
      $cssFiles = ['base.css', 'admin.css'];
      foreach ($cssFiles as $f) {
        $src = $extractDir . '/public/assets/css/' . $f;
        if (is_file($src)) {
          @copy($src, $root . '/public/assets/css/' . $f);
        }
      }

      // LICENSE
      if (is_file($extractDir . '/LICENSE')) {
        @copy($extractDir . '/LICENSE', $root . '/LICENSE');
      }

      // 7. version.json aktualisieren
      if (is_file($extractDir . '/version.json')) {
        $newVersion = json_decode(file_get_contents($extractDir . '/version.json'), true);
        if (is_array($newVersion)) {
          // update_url aus alter Version uebernehmen
          $newVersion['update_url'] = $updateUrl;
          Storage::writeJson('version.json', $newVersion);
        }
      }

      // 8. Opcache zuruecksetzen
      if (function_exists('opcache_reset')) {
        @opcache_reset();
      }

      // 9. Aufraeumen
      $this->deleteDir($tempDir);

      // 10. Altes Backup nach 1 Tag aufraumen (nur das aelteste behalten)
      $this->cleanOldBackups($root . '/config');

      $newV = Storage::readJson('version.json');
      $_SESSION['_flash'] = 'Update auf Version ' . ($newV['version'] ?? '?') . ' erfolgreich installiert.';

    } catch (\Throwable $e) {
      // Details nur ins Server-Log — keine internen Pfade in der UI
      error_log('CMF Update fehlgeschlagen: ' . $e->getMessage());
      $_SESSION['_flash'] = 'Update fehlgeschlagen. Details stehen im Server-Fehlerprotokoll.';
    } finally {
      // Maintenance-Modus deaktivieren
      @unlink($root . '/config/.maintenance');
      // Temp aufraeumen
      if (is_dir($tempDir)) {
        $this->deleteDir($tempDir);
      }
    }

    header('Location: /admin.php?a=settings');
    exit;
  }

  public function rollback(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: /admin.php?a=settings');
      exit;
    }

    Csrf::check();

    $root = Storage::root();
    $backups = glob($root . '/config/.update_backup_*');
    if (!$backups) {
      $_SESSION['_flash'] = 'Kein Backup zum Wiederherstellen gefunden.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    // Neuestes Backup nehmen
    sort($backups);
    $latest = end($backups);

    // Backup-Pfad muss real innerhalb von config/ liegen (Symlink-Schutz)
    $configReal = str_replace('\\', '/', (string)realpath($root . '/config'));
    $latestReal = str_replace('\\', '/', (string)realpath($latest));
    if ($configReal === '' || $latestReal === '' || !str_starts_with($latestReal, $configReal . '/')) {
      $_SESSION['_flash'] = 'Backup ungueltig.';
      header('Location: /admin.php?a=settings');
      exit;
    }
    $latest = $latestReal;

    if (is_dir($latest . '/app')) {
      @file_put_contents($root . '/config/.maintenance', date('c'));
      $this->deleteDir($root . '/app');
      $this->copyDir($latest . '/app', $root . '/app');
      if (is_file($latest . '/version.json')) {
        @copy($latest . '/version.json', $root . '/version.json');
      }
      if (function_exists('opcache_reset')) {
        @opcache_reset();
      }
      @unlink($root . '/config/.maintenance');
      $_SESSION['_flash'] = 'Rollback erfolgreich. Vorherige Version wiederhergestellt.';
    } else {
      $_SESSION['_flash'] = 'Backup unvollstaendig.';
    }

    header('Location: /admin.php?a=settings');
    exit;
  }

  /**
   * Liest update_url aus version.json und gibt sie nur zurueck,
   * wenn sie HTTPS nutzt und der Host auf der Allowlist steht.
   */
  private function validatedUpdateUrl(): ?string {
    $local = Storage::readJson('version.json');
    $updateUrl = rtrim(trim((string)($local['update_url'] ?? '')), '/');
    if ($updateUrl === '') return null;

    $parts = parse_url($updateUrl);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));

    if ($scheme !== 'https' || !in_array($host, self::ALLOWED_UPDATE_HOSTS, true)) {
      return null;
    }

    return $updateUrl;
  }

  /** Wirft eine Exception, wenn eine Datei per Symlink/Traversal aus $dir ausbricht. */
  private function assertInsideDir(string $dir): void {
    $base = realpath($dir);
    if ($base === false) {
      throw new \RuntimeException('Extraktionsordner nicht lesbar.');
    }
    $base = str_replace('\\', '/', $base);
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $item) {
      $real = realpath($item->getPathname());
      if ($real === false) {
        throw new \RuntimeException('Ungueltiger Eintrag im Update-Paket.');
      }
      $real = str_replace('\\', '/', $real);
      if (!str_starts_with($real, $base . '/') && $real !== $base) {
        throw new \RuntimeException('Pfad ausserhalb des Extraktionsordners im Update-Paket.');
      }
    }
  }

  private function fetchRemoteVersion(string $url): ?array {
    $context = stream_context_create([
      'http' => ['timeout' => 10, 'ignore_errors' => true],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
      // Fallback auf curl
      if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 10,
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_FOLLOWLOCATION => true
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
      }
    }

    if (!is_string($raw) || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !($data['ok'] ?? false)) return null;
    return $data['data'] ?? null;
  }

  private function downloadFile(string $url, string $dest): bool {
    // Zuerst curl versuchen
    if (function_exists('curl_init')) {
      $fp = fopen($dest, 'wb');
      if ($fp === false) return false;
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true
      ]);
      $ok = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      fclose($fp);
      return $ok !== false && $code >= 200 && $code < 300 && filesize($dest) > 1000;
    }

    // Fallback: file_get_contents
    $context = stream_context_create([
      'http' => ['timeout' => 120],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false || strlen($data) < 1000) return false;
    return file_put_contents($dest, $data) !== false;
  }

  private function copyDir(string $src, string $dest): void {
    if (!is_dir($src)) return;
    @mkdir($dest, 0775, true);
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
      $target = $dest . '/' . $it->getSubPathname();
      if ($item->isDir()) {
        @mkdir($target, 0775, true);
      } else {
        @copy($item->getPathname(), $target);
      }
    }
  }

  private function deleteDir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
  }

  private function cleanOldBackups(string $configDir): void {
    $backups = glob($configDir . '/.update_backup_*');
    if (!$backups || count($backups) <= 1) return;
    sort($backups);
    // Alle ausser dem neuesten loeschen
    array_pop($backups);
    foreach ($backups as $old) {
      if (is_dir($old)) $this->deleteDir($old);
    }
  }

  private function renderCheckResult(string $local, ?string $remote, bool $updateAvailable, array $changelog, string $downloadUrl, ?string $error): void {
    // Wird von SettingsController aufgerufen — gibt HTML zurueck
    echo '<div class="update-panel">';
    echo '<h3 class="card-heading">System-Update</h3>';

    echo '<div class="cols cols-2">';
    echo '<div><span>Installierte Version</span><br><strong class="version-value">' . htmlspecialchars($local, ENT_QUOTES) . '</strong></div>';

    if ($error) {
      echo '<div><span>Verfuegbare Version</span><br><span class="text-error">' . htmlspecialchars($error, ENT_QUOTES) . '</span></div>';
    } elseif ($remote !== null) {
      $cls = $updateAvailable ? 'version-value version-new' : 'version-value';
      echo '<div><span>Verfuegbare Version</span><br><strong class="' . $cls . '">' . htmlspecialchars($remote, ENT_QUOTES) . '</strong>';
      if ($updateAvailable) echo ' <span class="badge badge-green">Neu</span>';
      echo '</div>';
    }
    echo '</div>';

    if ($updateAvailable && $downloadUrl !== '') {
      echo '<form method="post" action="/admin.php?a=update_run" class="actions-top" onsubmit="return confirm(\'System auf Version ' . htmlspecialchars($remote ?? '', ENT_QUOTES) . ' aktualisieren? Benutzerdaten, Inhalte und Design bleiben erhalten.\')">';
      echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
      echo '<button class="btn primary" type="submit">Update auf ' . htmlspecialchars($remote ?? '', ENT_QUOTES) . ' installieren</button>';
      echo '</form>';
    } elseif ($remote !== null && !$updateAvailable && $error === null) {
      echo '<p class="hint-text">Das System ist auf dem neuesten Stand.</p>';
    }

    // Changelog anzeigen
    if ($updateAvailable && !empty($changelog)) {
      echo '<details class="admin-details"><summary>Changelog</summary><ul class="changelog-list">';
      foreach ($changelog as $entry) {
        $v = htmlspecialchars((string)($entry['version'] ?? ''), ENT_QUOTES);
        $d = htmlspecialchars((string)($entry['date'] ?? ''), ENT_QUOTES);
        echo '<li><strong>' . $v . '</strong> (' . $d . ')';
        if (!empty($entry['changes']) && is_array($entry['changes'])) {
          echo '<ul>';
          foreach ($entry['changes'] as $c) {
            echo '<li>' . htmlspecialchars((string)$c, ENT_QUOTES) . '</li>';
          }
          echo '</ul>';
        }
        echo '</li>';
      }
      echo '</ul></details>';
    }

    // Rollback-Button
    $backups = glob(Storage::root() . '/config/.update_backup_*');
    if ($backups) {
      echo '<div class="update-rollback">';
      echo '<form method="post" action="/admin.php?a=update_rollback" class="form-inline" onsubmit="return confirm(\'Zur vorherigen Version zurueckkehren?\')">';
      echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
      echo '<button class="btn" type="submit">Rollback zur vorherigen Version</button>';
      echo '</form>';
      echo '</div>';
    }

    echo '</div>';
  }
}
