<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Sitemap;
use App\Core\Storage;

final class SettingsController {

  public function index(): void {
    $csrf = Csrf::token();

    // Flash
    $flash = '';
    if (!empty($_SESSION['_flash'])) {
      $flash = (string)$_SESSION['_flash'];
      unset($_SESSION['_flash']);
    }

    // Import-Analyse aus Session
    $analysis = $_SESSION['_import_analysis'] ?? null;
    $analysisHtml = '';

    if (is_array($analysis)) {
      $analysisHtml = '<div class="card card-spaced">'
        . '<h3 class="card-heading">ZIP-Inhalt</h3>'
        . '<form method="post" action="/admin.php?a=settings_import_run">'
        . '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';

      if ($analysis['header']) {
        $analysisHtml .= '<label class="label-row">'
          . '<input type="checkbox" name="import_header" value="1" checked> Header</label>';
      }
      if ($analysis['footer']) {
        $analysisHtml .= '<label class="label-row">'
          . '<input type="checkbox" name="import_footer" value="1" checked> Footer</label>';
      }
      if ($analysis['pages_count'] > 0) {
        $analysisHtml .= '<label class="label-row">'
          . '<input type="checkbox" name="import_pages" value="1" checked> '
          . htmlspecialchars((string)$analysis['pages_count'], ENT_QUOTES) . ' Seiten</label>';
        if (!empty($analysis['page_titles'])) {
          $analysisHtml .= '<ul class="import-sublist">';
          foreach ($analysis['page_titles'] as $pt) {
            $analysisHtml .= '<li>' . htmlspecialchars((string)$pt, ENT_QUOTES) . '</li>';
          }
          $analysisHtml .= '</ul>';
        }
      }
      if ($analysis['media_count'] > 0) {
        $analysisHtml .= '<label class="label-row">'
          . '<input type="checkbox" name="import_media" value="1" checked> '
          . htmlspecialchars((string)$analysis['media_count'], ENT_QUOTES) . ' Mediendateien</label>';
      }

      $analysisHtml .= '<div class="actions actions-top">'
        . '<button class="btn primary" type="submit">Import starten</button>'
        . '<a class="btn" href="/admin.php?a=settings_import_cancel">Abbrechen</a>'
        . '</div></form></div>';
    }

    // Update-Bereich
    $updateController = new UpdateController();
    ob_start();
    $updateController->check();
    $updateHtml = ob_get_clean();

    $content = $updateHtml
      . '<hr class="divider">'
      . '<h2>Export</h2>'
      . '<p>Erstelle ein ZIP-Backup mit den gewünschten Bereichen.</p>'
      . '<form method="post" action="/admin.php?a=settings_export">'
      . '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">'
      . '<div class="form-options">'
      . '<label class="label-row"><input type="checkbox" name="export_header" value="1" checked> Header</label>'
      . '<label class="label-row"><input type="checkbox" name="export_footer" value="1" checked> Footer</label>'
      . '<label class="label-row"><input type="checkbox" name="export_pages" value="1" checked> Seiten</label>'
      . '<label class="label-row"><input type="checkbox" name="export_media" value="1" checked> Medien</label>'
      . '</div>'
      . '<button class="btn primary" type="submit">ZIP exportieren</button>'
      . '</form>'
      . '<hr class="divider">'
      . '<h2>Import</h2>'
      . '<p>Lade ein ZIP-Backup hoch. Vorhandene Inhalte werden aktualisiert, neue erstellt. Es wird nichts gelöscht.</p>'
      . '<form method="post" action="/admin.php?a=settings_import_analyze" enctype="multipart/form-data">'
      . '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">'
      . '<div class="actions form-options">'
      . '<label class="sr-only" for="import-zip">ZIP-Backup-Datei auswählen</label>'
      . '<input type="file" id="import-zip" name="zip" accept=".zip" required>'
      . '<button class="btn primary" type="submit">ZIP analysieren</button>'
      . '</div>'
      . '</form>'
      . $analysisHtml;

    $this->render('Einstellungen', $content, $flash);
  }

  public function export(): void {
    Csrf::check();

    $includeHeader = ($_POST['export_header'] ?? '') === '1';
    $includeFooter = ($_POST['export_footer'] ?? '') === '1';
    $includePages  = ($_POST['export_pages'] ?? '') === '1';
    $includeMedia  = ($_POST['export_media'] ?? '') === '1';

    $tmpFile = tempnam(sys_get_temp_dir(), 'cmf_export_') . '.zip';
    $zip = new \ZipArchive();

    if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
      $_SESSION['_flash'] = 'ZIP konnte nicht erstellt werden.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    $root = Storage::root();

    if ($includeHeader) {
      $zip->addFile($root . '/content/globals/header.json', 'content/globals/header.json');
    }

    if ($includeFooter) {
      $zip->addFile($root . '/content/globals/footer.json', 'content/globals/footer.json');
    }

    if ($includePages) {
      $zip->addFile($root . '/content/pages.json', 'content/pages.json');
      $dir = $root . '/content/pages';
      if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') as $file) {
          $zip->addFile($file, 'content/pages/' . basename($file));
        }
      }
    }

    if ($includeMedia) {
      $mediaDir = $root . '/public/media';
      if (is_dir($mediaDir)) {
        $it = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
          if (!$file->isFile()) continue;
          $rel = str_replace('\\', '/', str_replace($root . '/public/', '', $file->getPathname()));
          $zip->addFile($file->getPathname(), $rel);
        }
      }
    }

    $zip->close();

    $date = date('Y-m-d');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="cmf-backup-' . $date . '.zip"');
    header('Content-Length: ' . (string)filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
  }

  public function importAnalyze(): void {
    Csrf::check();

    if (!isset($_FILES['zip']) || ($_FILES['zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $_SESSION['_flash'] = 'Keine ZIP-Datei hochgeladen.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    $tmpZip = (string)$_FILES['zip']['tmp_name'];
    $zip = new \ZipArchive();

    if ($zip->open($tmpZip) !== true) {
      $_SESSION['_flash'] = 'ZIP konnte nicht geöffnet werden.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    // ZIP in temp-Ordner entpacken
    $extractDir = sys_get_temp_dir() . '/cmf_import_' . bin2hex(random_bytes(8));
    // Zip-Slip Schutz: Pfade validieren
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entry = $zip->getNameIndex($i);
      if ($entry === false) continue;
      if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_starts_with($entry, '\\')) {
        $zip->close();
        $_SESSION['_flash'] = 'Import abgebrochen: Ungueltige Pfade im ZIP.';
        header('Location: /admin.php?a=settings');
        exit;
      }
    }
    $zip->extractTo($extractDir);
    $zip->close();

    // Zweite Verteidigungslinie: entpackte Pfade muessen per realpath
    // innerhalb des Extraktionsordners liegen (Symlink-/Traversal-Schutz)
    if (!$this->dirIsClean($extractDir)) {
      $this->deleteDir($extractDir);
      $_SESSION['_flash'] = 'Import abgebrochen: Ungueltige Pfade im ZIP.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    // Analyse
    $analysis = [
      'header' => is_file($extractDir . '/content/globals/header.json'),
      'footer' => is_file($extractDir . '/content/globals/footer.json'),
      'pages_count' => 0,
      'page_titles' => [],
      'media_count' => 0,
      'extract_dir' => $extractDir
    ];

    // Seiten zählen
    if (is_file($extractDir . '/content/pages.json')) {
      $idx = json_decode((string)file_get_contents($extractDir . '/content/pages.json'), true);
      if (is_array($idx['pages'] ?? null)) {
        $analysis['pages_count'] = count($idx['pages']);
        foreach ($idx['pages'] as $p) {
          $analysis['page_titles'][] = ($p['title'] ?? '') . ' (' . ($p['slug'] ?? '') . ')';
        }
      }
    }

    // Medien zählen
    $mediaDir = $extractDir . '/media';
    if (is_dir($mediaDir)) {
      $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($it as $f) {
        if ($f->isFile()) $analysis['media_count']++;
      }
    }

    $_SESSION['_import_analysis'] = $analysis;
    header('Location: /admin.php?a=settings');
    exit;
  }

  public function importRun(): void {
    Csrf::check();

    $analysis = $_SESSION['_import_analysis'] ?? null;
    if (!is_array($analysis) || empty($analysis['extract_dir'])) {
      $_SESSION['_flash'] = 'Kein Import vorbereitet.';
      header('Location: /admin.php?a=settings');
      exit;
    }

    $extractDir = (string)$analysis['extract_dir'];

    // Pfad aus der Session erneut validieren: muss ein cmf_import_-Ordner
    // innerhalb des System-Temp-Verzeichnisses sein
    $tempBase = str_replace('\\', '/', (string)realpath(sys_get_temp_dir()));
    $realExtract = is_dir($extractDir) ? str_replace('\\', '/', (string)realpath($extractDir)) : '';
    $isValid = $tempBase !== ''
      && $realExtract !== ''
      && str_starts_with($realExtract, $tempBase . '/')
      && str_starts_with(basename($realExtract), 'cmf_import_');

    if (!$isValid) {
      unset($_SESSION['_import_analysis']);
      $_SESSION['_flash'] = 'Import-Daten nicht mehr verfügbar.';
      header('Location: /admin.php?a=settings');
      exit;
    }
    $extractDir = $realExtract;

    $root = Storage::root();
    $imported = [];

    // Header importieren
    if (($_POST['import_header'] ?? '') === '1') {
      $src = $extractDir . '/content/globals/header.json';
      if (is_file($src)) {
        copy($src, $root . '/content/globals/header.json');
        $imported[] = 'Header';
      }
    }

    // Footer importieren
    if (($_POST['import_footer'] ?? '') === '1') {
      $src = $extractDir . '/content/globals/footer.json';
      if (is_file($src)) {
        copy($src, $root . '/content/globals/footer.json');
        $imported[] = 'Footer';
      }
    }

    // Seiten importieren
    if (($_POST['import_pages'] ?? '') === '1') {
      $srcIndex = $extractDir . '/content/pages.json';
      if (is_file($srcIndex)) {
        $importIdx = json_decode((string)file_get_contents($srcIndex), true);
        $importPages = $importIdx['pages'] ?? [];

        $currentIdx = Storage::readJson('content/pages.json');
        $currentPages = $currentIdx['pages'] ?? [];
        $currentIds = array_map(fn($p) => (string)($p['id'] ?? ''), $currentPages);

        foreach ($importPages as $ip) {
          $id = trim((string)($ip['id'] ?? ''));
          // ID streng validieren — wird als Dateiname verwendet (Path-Traversal-Schutz)
          if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9\-_]{0,63}$/', $id)) continue;

          // Seiten-JSON kopieren
          $srcPage = $extractDir . '/content/pages/' . $id . '.json';
          if (is_file($srcPage)) {
            copy($srcPage, $root . '/content/pages/' . $id . '.json');
          }

          // Index aktualisieren
          $existsIdx = array_search($id, $currentIds, true);
          if ($existsIdx !== false) {
            $ip['updated'] = date('c');
            $currentPages[$existsIdx] = $ip;
          } else {
            $ip['created'] = date('c');
            $ip['updated'] = date('c');
            $currentPages[] = $ip;
            $currentIds[] = $id;
          }
        }

        $currentIdx['pages'] = array_values($currentPages);
        Storage::writeJson('content/pages.json', $currentIdx);
        $imported[] = count($importPages) . ' Seiten';
      }
    }

    // Medien importieren
    if (($_POST['import_media'] ?? '') === '1') {
      $mediaDir = $extractDir . '/media';
      if (is_dir($mediaDir)) {
        $count = 0;
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $allowed = ['jpg','jpeg','png','webp','gif','svg','pdf','mp4','mp3','wav'];
        $allowedMimes = [
          'image/jpeg','image/png','image/webp','image/gif','image/svg+xml',
          'text/plain','text/xml','application/xml', // SVG-Varianten
          'application/pdf','video/mp4','audio/mpeg','audio/mp3','audio/wav','audio/x-wav'
        ];
        $it = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
          if (!$f->isFile()) continue;
          $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
          if (!in_array($ext, $allowed, true)) continue;
          // Echten MIME-Typ pruefen, nicht nur die Endung
          $mime = $finfo->file($f->getPathname());
          if ($mime === false || !in_array($mime, $allowedMimes, true)) continue;
          $rel = str_replace('\\', '/', str_replace($mediaDir, '', $f->getPathname()));
          $dest = $root . '/public/media' . $rel;
          $destDir = dirname($dest);
          if (!is_dir($destDir)) mkdir($destDir, 0775, true);
          copy($f->getPathname(), $dest);
          if ($ext === 'svg') {
            \App\Core\Sanitizer::svgFile($dest);
          }
          $count++;
        }
        $imported[] = $count . ' Medien';
      }
    }

    // Aufräumen
    $this->deleteDir($extractDir);
    unset($_SESSION['_import_analysis']);

    Sitemap::write();

    $_SESSION['_flash'] = 'Importiert: ' . implode(', ', $imported) . '.';
    header('Location: /admin.php?a=settings');
    exit;
  }

  public function importCancel(): void {
    $analysis = $_SESSION['_import_analysis'] ?? null;
    if (is_array($analysis) && !empty($analysis['extract_dir']) && is_dir($analysis['extract_dir'])) {
      $this->deleteDir($analysis['extract_dir']);
    }
    unset($_SESSION['_import_analysis']);
    header('Location: /admin.php?a=settings');
    exit;
  }

  /** Prueft, ob alle Eintraege per realpath innerhalb von $dir liegen. */
  private function dirIsClean(string $dir): bool {
    $base = realpath($dir);
    if ($base === false) return false;
    $base = str_replace('\\', '/', $base);
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $item) {
      $real = realpath($item->getPathname());
      if ($real === false) return false;
      $real = str_replace('\\', '/', $real);
      if (!str_starts_with($real, $base . '/') && $real !== $base) return false;
    }
    return true;
  }

  private function deleteDir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
      $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}
