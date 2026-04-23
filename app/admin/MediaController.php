<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Storage;

final class MediaController {
  private const EXTENSIONS = [
    'jpg','jpeg','png','webp','gif','svg','pdf','mp4','mp3','wav'
  ];

  public function index(): void {
    $audit = $this->auditMedia();
    $files = $audit['files'];
    $rows = '';

    foreach ($files as $file) {
      $path = (string)$file['path'];
      $refs = $file['references'];
      $used = $file['used'];
      $usageLabel = $used
        ? '<strong>' . count($refs) . ' Einbindungen</strong>'
        : '<strong>nicht eingebunden</strong>';

      $referenceHtml = $this->renderReferenceList($refs);
      $deleteHtml = $used
        ? '<span style="opacity:.6">gesperrt</span>'
        : '<form method="post" action="/admin.php?a=media_delete" onsubmit="return confirm(\'Datei wirklich löschen?\');">'
          . '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">'
          . '<input type="hidden" name="path" value="' . htmlspecialchars($path, ENT_QUOTES) . '">'
          . '<button class="btn" type="submit">Löschen</button>'
          . '</form>';

      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true);
      $thumb = $isImage
        ? '<a href="' . htmlspecialchars($path, ENT_QUOTES) . '" target="_blank" rel="noopener"><img src="' . htmlspecialchars($path, ENT_QUOTES) . '" alt="" style="width:60px;height:45px;object-fit:cover;border-radius:4px;display:block"></a>'
        : '<span style="display:inline-block;width:60px;height:45px;background:rgba(127,127,127,.1);border-radius:4px;text-align:center;line-height:45px;font-size:.7em">' . htmlspecialchars(strtoupper($ext), ENT_QUOTES) . '</span>';

      $rows .= '<tr>'
        . '<td style="width:70px">' . $thumb . '</td>'
        . '<td><a href="' . htmlspecialchars($path, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($path, ENT_QUOTES) . '</a></td>'
        . '<td><small>' . htmlspecialchars((string)$file['size'], ENT_QUOTES) . '</small></td>'
        . '<td><small>' . htmlspecialchars((string)$file['modified'], ENT_QUOTES) . '</small></td>'
        . '<td>' . $usageLabel . $referenceHtml . '</td>'
        . '<td style="white-space:nowrap">' . $deleteHtml . '</td>'
        . '</tr>';
    }

    if ($rows === '') {
      $rows = '<tr><td colspan="5"><small>Keine Medien gefunden.</small></td></tr>';
    }

    $content = $this->flashHtml()
      . '<form method="post" action="/admin.php?a=media_upload" enctype="multipart/form-data">'
      . '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">'
      . '<div class="actions" style="margin:0 0 14px 0">'
      . '<input type="file" name="file" required>'
      . '<button class="btn primary" type="submit">Upload</button>'
      . '<a class="btn" href="/admin.php?a=media_audit">Auf Einbindung prüfen</a>'
      . '</div>'
      . '</form>'
      . '<table><thead><tr><th></th><th>Datei</th><th>Bytes</th><th>Geändert</th><th>Einbindung</th><th style="white-space:nowrap">Aktion</th></tr></thead><tbody>' . $rows . '</tbody></table>';

    $this->render('Media', $content);
  }

  public function audit(): void {
    $audit = $this->auditMedia();
    $unused = array_values(array_filter($audit['files'], fn(array $file): bool => !$file['used']));
    $rows = '';

    foreach ($unused as $file) {
      $path = (string)$file['path'];

      $rows .= '<tr>'
        . '<td><a href="' . htmlspecialchars($path, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($path, ENT_QUOTES) . '</a></td>'
        . '<td><small>' . htmlspecialchars((string)$file['size'], ENT_QUOTES) . '</small></td>'
        . '<td><small>' . htmlspecialchars((string)$file['modified'], ENT_QUOTES) . '</small></td>'
        . '<td><strong>nicht eingebunden</strong></td>'
        . '<td>'
        . '<form method="post" action="/admin.php?a=media_delete" onsubmit="return confirm(\'Datei wirklich löschen?\');">'
        . '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">'
        . '<input type="hidden" name="path" value="' . htmlspecialchars($path, ENT_QUOTES) . '">'
        . '<button class="btn" type="submit">Löschen</button>'
        . '</form>'
        . '</td>'
        . '</tr>';
    }

    if ($rows === '') {
      $rows = '<tr><td colspan="5"><small>Keine unbenutzten Medien gefunden.</small></td></tr>';
    }

    $content = $this->flashHtml()
      . '<div class="actions" style="margin:0 0 14px 0">'
      . '<a class="btn" href="/admin.php?a=media">← Zur Medienliste</a>'
      . '</div>'
      . '<p><strong>Unbenutzte Medien:</strong> ' . count($unused) . ' von ' . count($audit['files']) . '</p>'
      . '<table><thead><tr><th>Datei</th><th>Bytes</th><th>Geändert</th><th>Status</th><th>Aktion</th></tr></thead><tbody>' . $rows . '</tbody></table>';

    $this->render('Media prüfen', $content);
  }

  public function upload(): void {
    Csrf::check();

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
      $this->redirect('/admin.php?a=media&msg=upload_failed');
    }

    $f = $_FILES['file'];

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $this->redirect('/admin.php?a=media&msg=upload_failed');
    }

    $name = trim((string)($f['name'] ?? ''));
    $tmp = trim((string)($f['tmp_name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($name === '' || $tmp === '' || $ext === '' || !in_array($ext, self::EXTENSIONS, true)) {
      http_response_code(415);
      echo 'Filetype';
      exit;
    }

    // MIME-Type serverseitig pruefen
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowedMimes = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml','application/pdf','video/mp4','audio/mpeg','audio/wav','audio/mp3'];
    if ($mime === false || !in_array($mime, $allowedMimes, true)) {
      http_response_code(415);
      echo 'MIME-Type ungueltig';
      exit;
    }

    $sub = date('Y/m');
    $destDir = Storage::root() . '/public/media/' . $sub;

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
      http_response_code(500);
      echo 'Media dir';
      exit;
    }

    $safe = preg_replace('/[^a-z0-9\-_\.]/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?? 'file';
    $safe = trim((string)(preg_replace('/-+/', '-', $safe) ?? $safe), '-');
    if ($safe === '') {
      $safe = 'file';
    }

    $dest = $destDir . '/' . $safe . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($tmp, $dest)) {
      http_response_code(500);
      echo 'Move failed';
      exit;
    }

    $this->redirect('/admin.php?a=media&msg=uploaded');
  }

  public function delete(): void {
    Csrf::check();

    $path = trim((string)($_POST['path'] ?? ''));

    if ($path === '') {
      $this->redirect('/admin.php?a=media&msg=missing_path');
    }

    $full = $this->resolveMediaPath($path);

    if ($full === null || !is_file($full)) {
      $this->redirect('/admin.php?a=media&msg=not_found');
    }

    $references = $this->findReferencesForPath($path);

    if ($references !== []) {
      $this->redirect('/admin.php?a=media&msg=in_use');
    }

    if (!@unlink($full)) {
      $this->redirect('/admin.php?a=media&msg=delete_failed');
    }

    $this->cleanupEmptyMediaDirs(dirname($full));
    $this->redirect('/admin.php?a=media&msg=deleted');
  }

  private function auditMedia(): array {
    $files = $this->collectFiles();
    $out = [];

    foreach ($files as $file) {
      $refs = $this->findReferencesForPath((string)$file['path']);
      $file['references'] = $refs;
      $file['used'] = $refs !== [];
      $out[] = $file;
    }

    return [
      'files' => $out,
      'unused_count' => count(array_filter($out, fn(array $file): bool => !$file['used'])),
      'used_count' => count(array_filter($out, fn(array $file): bool => $file['used']))
    ];
  }

  private function collectFiles(): array {
    $dir = Storage::root() . '/public/media';
    $files = [];

    if (!is_dir($dir)) {
      return $files;
    }

    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $f) {
      if (!$f->isFile()) {
        continue;
      }

      $relative = str_replace(Storage::root() . '/public', '', $f->getPathname());
      $relative = str_replace('\\', '/', $relative);

      $files[] = [
        'path' => $relative,
        'size' => (int)$f->getSize(),
        'modified' => date('c', (int)$f->getMTime())
      ];
    }

    usort($files, fn(array $a, array $b): int => strcmp((string)$b['modified'], (string)$a['modified']));

    return $files;
  }

  private ?array $cachedSources = null;

  private function findReferencesForPath(string $path): array {
    if ($this->cachedSources === null) {
      $this->cachedSources = $this->contentSources();
    }
    $sources = $this->cachedSources;
    $hits = [];

    foreach ($sources as $source) {
      $sourceHits = [];
      $this->scanValueForPath($source['data'], $path, '', $sourceHits);

      if ($sourceHits === []) {
        continue;
      }

      foreach ($sourceHits as $hit) {
        $hits[] = [
          'source' => $source['label'],
          'location' => $hit
        ];
      }
    }

    return $hits;
  }

  private function contentSources(): array {
    $sources = [];
    $index = Storage::readJson('content/pages.json');
    $pages = $index['pages'] ?? [];
    $titles = [];

    foreach ($pages as $page) {
      $id = trim((string)($page['id'] ?? ''));
      if ($id === '') {
        continue;
      }

      $titles[$id] = trim((string)($page['title'] ?? $id));
    }

    foreach ($titles as $id => $title) {
      $sources[] = [
        'label' => 'Seite: ' . $title,
        'data' => Storage::readJson('content/pages/' . $id . '.json')
      ];
    }

    foreach (['header', 'footer'] as $part) {
      $sources[] = [
        'label' => strtoupper($part),
        'data' => Storage::readJson('content/globals/' . $part . '.json')
      ];
    }

    // Blog-Posts + Blog-Index (Beitragsbilder)
    $blogIndex = Storage::readJson('content/blog.json');
    $blogPosts = $blogIndex['posts'] ?? [];

    // Beitragsbilder aus dem Index pruefen
    foreach ($blogPosts as $post) {
      $bId = trim((string)($post['id'] ?? ''));
      $bTitle = trim((string)($post['title'] ?? $bId));
      if ($bId === '') continue;

      // Index-Level Bild (Beitragsbild)
      $indexData = ['image' => (string)($post['image'] ?? '')];
      $sources[] = [
        'label' => 'Blog: ' . $bTitle . ' (Beitragsbild)',
        'data' => $indexData
      ];

      // Post-Inhalt (Bloecke)
      $sources[] = [
        'label' => 'Blog: ' . $bTitle,
        'data' => Storage::readJson('content/blog/' . $bId . '.json')
      ];
    }

    return $sources;
  }

  private function scanValueForPath(mixed $value, string $path, string $trail, array &$hits): void {
    if (is_array($value)) {
      foreach ($value as $key => $child) {
        $nextTrail = $trail === '' ? (string)$key : $trail . '.' . (string)$key;
        $this->scanValueForPath($child, $path, $nextTrail, $hits);
      }
      return;
    }

    if (!is_string($value) || $value === '') {
      return;
    }

    if (mb_strpos($value, $path) === false) {
      return;
    }

    $hits[] = $trail === '' ? 'root' : $trail;
  }

  private function renderReferenceList(array $refs): string {
    if ($refs === []) {
      return '';
    }

    $items = '';
    foreach ($refs as $ref) {
      $items .= '<li><small>' . htmlspecialchars((string)$ref['source'], ENT_QUOTES) . ' → ' . htmlspecialchars((string)$ref['location'], ENT_QUOTES) . '</small></li>';
    }

    return '<ul style="margin:8px 0 0 18px;padding:0">' . $items . '</ul>';
  }

  private function flashHtml(): string {
    $msg = trim((string)($_GET['msg'] ?? ''));

    if ($msg === '') {
      return '';
    }

    $map = [
      'uploaded' => 'Medium hochgeladen.',
      'deleted' => 'Medium gelöscht.',
      'in_use' => 'Medium ist eingebunden und kann nicht gelöscht werden.',
      'not_found' => 'Medium nicht gefunden.',
      'missing_path' => 'Pfad fehlt.',
      'delete_failed' => 'Medium konnte nicht gelöscht werden.',
      'upload_failed' => 'Upload fehlgeschlagen.'
    ];

    if (!isset($map[$msg])) {
      return '';
    }

    return '<p><strong>' . htmlspecialchars($map[$msg], ENT_QUOTES) . '</strong></p>';
  }

  private function resolveMediaPath(string $path): ?string {
    $path = str_replace('\\', '/', $path);

    if (!str_starts_with($path, '/media/')) {
      return null;
    }

    if (str_contains($path, '..')) {
      return null;
    }

    $full = Storage::root() . '/public' . $path;
    $base = realpath(Storage::root() . '/public/media');
    $real = realpath($full);

    if ($base === false || $real === false) {
      return null;
    }

    $base = str_replace('\\', '/', $base);
    $real = str_replace('\\', '/', $real);

    if (!str_starts_with($real, $base . '/') && $real !== $base) {
      return null;
    }

    return $real;
  }

  private function cleanupEmptyMediaDirs(string $dir): void {
    $base = realpath(Storage::root() . '/public/media');
    $current = realpath($dir);

    if ($base === false || $current === false) {
      return;
    }

    $base = str_replace('\\', '/', $base);
    $current = str_replace('\\', '/', $current);

    while ($current !== $base && str_starts_with($current, $base . '/')) {
      $items = @scandir($current);

      if (!is_array($items) || count($items) > 2) {
        break;
      }

      @rmdir($current);
      $parent = dirname($current);

      if ($parent === $current) {
        break;
      }

      $current = $parent;
    }
  }

  private function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}