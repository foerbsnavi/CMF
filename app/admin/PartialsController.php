<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Csrf;
use App\Core\Storage;
use App\Core\Slug;

final class PartialsController {
  public function index(): void {
    $content = "<table><thead><tr><th>Bereich</th><th>Aktion</th></tr></thead><tbody>"
      . "<tr><td>Header</td><td><a class=\"btn\" href=\"/admin.php?a=partial_edit&part=header\">Bearbeiten</a></td></tr>"
      . "<tr><td>Footer</td><td><a class=\"btn\" href=\"/admin.php?a=partial_edit&part=footer\">Bearbeiten</a></td></tr>"
      . "</tbody></table>";
    $this->render('Header/Footer', $content);
  }

  public function edit(): void {
    $part = (string)($_GET['part'] ?? '');
    if (!in_array($part, ['header', 'footer'], true)) { http_response_code(404); echo "Not found"; exit; }

    $data = Storage::readJson('content/globals/' . $part . '.json');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $json = $json === false ? "{}" : $json;
    $mediaJson = htmlspecialchars(Slug::imageMediaJson(), ENT_QUOTES);
    $titleValue = htmlspecialchars((string)($data['meta']['title'] ?? strtoupper($part)), ENT_QUOTES);

    $content = "<form method=\"post\" action=\"/admin.php?a=partial_save\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<input type=\"hidden\" name=\"part\" value=\"" . htmlspecialchars($part, ENT_QUOTES) . "\">"
      . "<div class=\"actions\">"
      . "<a class=\"btn\" href=\"/admin.php?a=partials\">← Zur Liste</a>"
      . "<button class=\"btn primary\" type=\"submit\">Speichern</button>"
      . "</div>"
      . "<div><label>Titel<br><input type=\"text\" name=\"partial_meta_title\" value=\"{$titleValue}\"></label></div>"
      . "<div class=\"editor-switch\">"
      . "<button class=\"btn primary\" type=\"button\" data-be-mode=\"visual\">Block-Editor</button>"
      . "<button class=\"btn\" type=\"button\" data-be-mode=\"json\">JSON</button>"
      . "</div>"
      . "<p class=\"be-help\"><strong>" . htmlspecialchars(strtoupper($part), ENT_QUOTES) . "</strong> <small>Header/Footer mit denselben Blocktypen wie Seiten</small></p>"
      . "<div class=\"block-editor-shell is-active\" data-block-editor data-block-editor-title-selector=\"input[name='partial_meta_title']\" data-block-editor-media=\"{$mediaJson}\"></div>"
      . "<div class=\"json-editor-shell\" data-block-editor-json-wrap>"
      . "<p class=\"json-editor-label\"><strong>" . htmlspecialchars(strtoupper($part), ENT_QUOTES) . " JSON</strong></p>"
      . "<textarea name=\"json\" data-block-editor-source>" . htmlspecialchars($json, ENT_QUOTES) . "</textarea>"
      . "</div>"
      . "</form>";

    $this->render('Header/Footer bearbeiten', $content);
  }

  public function save(): void {
    Csrf::check();
    $part = (string)($_POST['part'] ?? '');
    if (!in_array($part, ['header', 'footer'], true)) { http_response_code(404); echo "Not found"; exit; }

    $raw = (string)($_POST['json'] ?? '{}');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = [];

    Storage::writeJson('content/globals/' . $part . '.json', $decoded);
    header('Location: /admin.php?a=partial_edit&part=' . urlencode($part));
    exit;
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}