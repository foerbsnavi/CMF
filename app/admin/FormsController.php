<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Storage;

/**
 * Admin-Ansicht "Einsendungen": listet gespeicherte Formular-Einsendungen,
 * markiert sie als gelesen, loescht einzelne oder alle und exportiert als CSV.
 * Die Daten liegen in content/form-submissions/<seite>_<block>.json.
 */
final class FormsController {

  private const DIR = 'content/form-submissions';

  public function index(): void {
    $forms = $this->allForms();

    if ($forms === []) {
      $content = '<p>Es liegen noch keine Formular-Einsendungen vor. Sobald ein Besucher ein Formular abschickt, erscheint es hier.</p>';
      $this->render('Einsendungen', $content);
      return;
    }

    $rows = '';
    foreach ($forms as $f) {
      $unread = $f['unread'] > 0
        ? '<strong>' . $f['unread'] . ' neu</strong>'
        : '<span class="muted">–</span>';
      $title = $f['title'] !== '' ? $f['title'] : $f['key'];
      $rows .= '<tr>'
        . '<td>' . $this->e($title) . '<br><small class="muted">/' . $this->e($f['page']) . '</small></td>'
        . '<td>' . $f['total'] . '</td>'
        . '<td>' . $unread . '</td>'
        . '<td><a class="btn" href="/admin.php?a=form_view&f=' . urlencode($f['key']) . '">Ansehen</a></td>'
        . '</tr>';
    }

    $content = '<table><thead><tr><th>Formular</th><th>Einsendungen</th><th>Neu</th><th>Aktion</th></tr></thead><tbody>'
      . $rows . '</tbody></table>';
    $this->render('Einsendungen', $content);
  }

  public function view(): void {
    $key = $this->key();
    $store = $this->read($key);
    if ($store === null) { http_response_code(404); echo 'Nicht gefunden'; exit; }

    $subs = is_array($store['submissions'] ?? null) ? $store['submissions'] : [];
    $title = trim((string)($store['form']['title'] ?? '')) ?: $key;
    $csrf = $this->e(Csrf::token());
    $keyEnc = urlencode($key);

    $head = '<div class="actions">'
      . '<a class="btn" href="/admin.php?a=forms">← Zur Liste</a>'
      . '<a class="btn" href="/admin.php?a=form_export&f=' . $keyEnc . '">CSV-Export</a>'
      . '<form method="post" action="/admin.php?a=form_mark&f=' . $keyEnc . '" style="display:inline">'
      . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
      . '<button class="btn" type="submit">Alle als gelesen</button></form>'
      . '<form method="post" action="/admin.php?a=form_clear&f=' . $keyEnc . '" style="display:inline" onsubmit="return confirm(\'Wirklich ALLE Einsendungen dieses Formulars löschen?\')">'
      . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
      . '<button class="btn" type="submit">Alle löschen</button></form>'
      . '</div>';

    if ($subs === []) {
      $this->render('Einsendungen: ' . $title, $head . '<p>Keine Einsendungen.</p>');
      return;
    }

    // Neueste zuerst
    $items = array_reverse($subs, true);
    $cards = '';
    foreach ($items as $i => $sub) {
      $ts = trim((string)($sub['ts'] ?? ''));
      $when = $ts !== '' ? date('d.m.Y H:i', strtotime($ts) ?: time()) : '';
      $isNew = empty($sub['read']);
      $fields = is_array($sub['data'] ?? null) ? $sub['data'] : [];
      $lines = '';
      foreach ($fields as $fld) {
        if (!is_array($fld)) continue;
        $lines .= '<tr><th>' . $this->e((string)($fld['label'] ?? '')) . '</th><td>'
          . nl2br($this->e((string)($fld['value'] ?? ''))) . '</td></tr>';
      }
      $delForm = '<form method="post" action="/admin.php?a=form_delete&f=' . $keyEnc . '" style="display:inline" onsubmit="return confirm(\'Diese Einsendung löschen?\')">'
        . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="i" value="' . (int)$i . '">'
        . '<button class="btn" type="submit">Löschen</button></form>';
      $cards .= '<div class="card' . ($isNew ? ' is-new' : '') . '">'
        . '<div class="actions"><span class="muted">' . $this->e($when) . ($isNew ? ' · <strong>neu</strong>' : '') . '</span>' . $delForm . '</div>'
        . '<table class="form-submission">' . $lines . '</table>'
        . '</div>';
    }

    $this->render('Einsendungen: ' . $title, $head . $cards);
  }

  public function mark(): void {
    Csrf::check();
    $key = $this->key();
    $store = $this->read($key);
    if ($store === null) { http_response_code(404); echo 'Nicht gefunden'; exit; }
    foreach ($store['submissions'] as &$s) {
      if (is_array($s)) $s['read'] = true;
    }
    unset($s);
    Storage::writeJson(self::DIR . '/' . $key . '.json', $store);
    $this->back($key);
  }

  public function delete(): void {
    Csrf::check();
    $key = $this->key();
    $store = $this->read($key);
    if ($store === null) { http_response_code(404); echo 'Nicht gefunden'; exit; }
    $i = (int)($_POST['i'] ?? -1);
    if (isset($store['submissions'][$i])) {
      array_splice($store['submissions'], $i, 1);
      Storage::writeJson(self::DIR . '/' . $key . '.json', $store);
    }
    $this->back($key);
  }

  public function clear(): void {
    Csrf::check();
    $key = $this->key();
    $full = Storage::root() . '/' . self::DIR . '/' . $key . '.json';
    if (is_file($full)) @unlink($full);
    header('Location: /admin.php?a=forms');
    exit;
  }

  public function export(): void {
    $key = $this->key();
    $store = $this->read($key);
    if ($store === null) { http_response_code(404); echo 'Nicht gefunden'; exit; }
    $subs = is_array($store['submissions'] ?? null) ? $store['submissions'] : [];

    // Spalten aus allen Einsendungen sammeln (Reihenfolge des ersten Vorkommens)
    $cols = ['Datum'];
    foreach ($subs as $sub) {
      foreach (($sub['data'] ?? []) as $fld) {
        $label = (string)($fld['label'] ?? '');
        if ($label !== '' && !in_array($label, $cols, true)) $cols[] = $label;
      }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="einsendungen_' . $key . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM fuer Excel/Umlaute
    fputcsv($out, array_map([$this, 'csvSafe'], $cols));
    foreach ($subs as $sub) {
      $map = [];
      foreach (($sub['data'] ?? []) as $fld) {
        $map[(string)($fld['label'] ?? '')] = (string)($fld['value'] ?? '');
      }
      $line = [trim((string)($sub['ts'] ?? ''))];
      foreach (array_slice($cols, 1) as $c) $line[] = $map[$c] ?? '';
      fputcsv($out, array_map([$this, 'csvSafe'], $line));
    }
    fclose($out);
    exit;
  }

  /**
   * Entschaerft CSV-Formel-Injection: Werte, die mit = + - @ (oder Tab/CR)
   * beginnen, koennen in Excel/LibreOffice als Formel ausgefuehrt werden.
   * Ein vorangestelltes Apostroph zwingt die Tabelle zur Text-Interpretation.
   */
  private function csvSafe(string $v): string {
    if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) {
      return "'" . $v;
    }
    return $v;
  }

  // ── intern ──────────────────────────────────────────────────────────

  /** Alle Formulare mit Einsendungen (aus dem Dateisystem). */
  private function allForms(): array {
    $dir = Storage::root() . '/' . self::DIR;
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
      $key = basename($file, '.json');
      $store = $this->read($key);
      if ($store === null) continue;
      $subs = is_array($store['submissions'] ?? null) ? $store['submissions'] : [];
      $unread = 0;
      foreach ($subs as $s) { if (is_array($s) && empty($s['read'])) $unread++; }
      $out[] = [
        'key' => $key,
        'page' => (string)($store['form']['page'] ?? ''),
        'title' => trim((string)($store['form']['title'] ?? '')),
        'total' => count($subs),
        'unread' => $unread,
      ];
    }
    usort($out, fn($a, $b) => $b['unread'] <=> $a['unread'] ?: strcmp($a['title'], $b['title']));
    return $out;
  }

  private function read(string $key): ?array {
    $full = Storage::root() . '/' . self::DIR . '/' . $key . '.json';
    if (!is_file($full)) return null;
    $data = json_decode((string)file_get_contents($full), true);
    if (!is_array($data)) return null;
    if (!isset($data['submissions']) || !is_array($data['submissions'])) $data['submissions'] = [];
    return $data;
  }

  /** Liest & saeubert den ?f=-Parameter (kein Pfad-Traversal). */
  private function key(): string {
    $raw = (string)($_GET['f'] ?? '');
    $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw) ?? '';
    if ($key === '') { http_response_code(400); echo 'Ungültig'; exit; }
    return $key;
  }

  private function back(string $key): void {
    header('Location: /admin.php?a=form_view&f=' . urlencode($key));
    exit;
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }

  private function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
