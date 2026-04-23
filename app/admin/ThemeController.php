<?php
declare(strict_types=1);

namespace App\Admin;

use App\Api\GlobalsController;
use App\Core\Csrf;
use App\Core\Storage;
use App\Core\Theme;

final class ThemeController {
  public function index(): void {
    $cfg = Storage::readJson('config/styles.json');
    if (!is_array($cfg)) $cfg = [];
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';

    $fonts = Theme::availableFonts();
    $fontFamilies = [];
    foreach ($fonts as $f) {
      $family = (string)$f['family'];
      if (!in_array($family, $fontFamilies, true)) $fontFamilies[] = $family;
    }

    $flash = '';
    if (!empty($_SESSION['_flash'])) {
      $flash = (string)$_SESSION['_flash'];
      unset($_SESSION['_flash']);
    }

    $content = "<form method=\"post\" action=\"/admin.php?a=theme_save\">"
      . "<input type=\"hidden\" name=\"_csrf\" value=\"" . htmlspecialchars(Csrf::token(), ENT_QUOTES) . "\">"
      . "<div class=\"actions\" style=\"margin:0 0 14px 0\">"
      . "<button class=\"btn primary\" type=\"submit\">Theme speichern</button>"
      . "<a class=\"btn\" href=\"/\" target=\"_blank\" rel=\"noopener\">Frontend prüfen</a>"
      . "</div>"

      // Layout
      . "<div class=\"theme-group\">"
      . "<h3>Layout</h3>"
      . "<div class=\"theme-grid\">"
      . $this->textField('container', 'Container-Breite', $cfg['container'] ?? '1100px')
      . $this->textField('pad', 'Innenabstand', $cfg['pad'] ?? '16px')
      . $this->textField('gap', 'Spaltenabstand', $cfg['gap'] ?? '16px')
      . "</div>"
      . "</div>"

      // Radius
      . "<div class=\"theme-group\">"
      . "<h3>Ecken</h3>"
      . "<div class=\"theme-grid\">"
      . $this->textField('radius_sm', 'Klein', $cfg['radius']['sm'] ?? '8px')
      . $this->textField('radius_md', 'Mittel', $cfg['radius']['md'] ?? '14px')
      . $this->textField('radius_lg', 'Gross', $cfg['radius']['lg'] ?? '22px')
      . "</div>"
      . "</div>"

      // Farben
      . "<div class=\"theme-group\">"
      . "<h3>Farben</h3>"
      . "<div class=\"theme-grid\">"
      . $this->colorField('color_bg', 'Hintergrund', $cfg['colors']['bg'] ?? '#ffffff')
      . $this->colorField('color_text', 'Text', $cfg['colors']['text'] ?? '#111111')
      . $this->colorField('color_muted', 'Gedaempft', $cfg['colors']['muted'] ?? '#666666')
      . $this->colorField('color_border', 'Rahmen', $cfg['colors']['border'] ?? '#dddddd')
      . $this->colorField('color_primary', 'Primaer', $cfg['colors']['primary'] ?? '#0d6efd')
      . $this->colorField('color_secondary', 'Sekundaer', $cfg['colors']['secondary'] ?? '#ff0000')
      . $this->colorField('color_primary_text', 'Primaer-Text', $cfg['colors']['primary_text'] ?? '#ffffff')
      . $this->colorField('color_link', 'Links', $cfg['colors']['link'] ?? '#0d6efd')
      . "</div>"
      . "</div>"

      // Schriftgroessen
      . "<div class=\"theme-group\">"
      . "<h3>Schriftgroessen</h3>"
      . "<div class=\"theme-grid\">"
      . $this->textField('type_body', 'Body', $cfg['type']['body'] ?? '0.9rem')
      . $this->textField('type_h1', 'H1', $cfg['type']['h1'] ?? '3rem')
      . $this->textField('type_h2', 'H2', $cfg['type']['h2'] ?? '2rem')
      . $this->textField('type_h3', 'H3', $cfg['type']['h3'] ?? '1.5rem')
      . $this->textField('type_h4', 'H4', $cfg['type']['h4'] ?? '1.2rem')
      . $this->textField('type_h5', 'H5', $cfg['type']['h5'] ?? '1rem')
      . "</div>"
      . "</div>"

      // Fonts
      . "<div class=\"theme-group\">"
      . "<h3>Schriftarten</h3>"
      . "<div class=\"theme-grid\">"
      . $this->selectField('font_body', 'Body-Font', $cfg['fonts']['body'] ?? '', $fontFamilies)
      . $this->selectField('font_body_weight', 'Body-Gewicht', $cfg['fonts']['body_weight'] ?? 'regular', ['light', 'regular', 'bold'])
      . $this->selectField('font_heading', 'Heading-Font', $cfg['fonts']['heading'] ?? '', $fontFamilies)
      . $this->selectField('font_heading_weight', 'Heading-Gewicht', $cfg['fonts']['heading_weight'] ?? 'bold', ['light', 'regular', 'bold'])
      . "</div>"
      . "</div>"

      // Custom CSS
      . "<div class=\"theme-group\">"
      . "<h3>Custom CSS</h3>"
      . "<p style=\"margin:0 0 10px;opacity:.7;font-size:.9em\">Eigene CSS-Regeln und wiederverwendbare Klassen fuer HTML-Bloecke. Wird nach theme.css geladen.</p>"
      . "<textarea name=\"custom_css\" style=\"min-height:300px\">" . htmlspecialchars(GlobalsController::readCustomCss(), ENT_QUOTES) . "</textarea>"
      . "</div>"

      . "</form>";

    $this->render('Theme', $content, $flash);
  }

  public function save(): void {
    Csrf::check();

    $cfg = [
      'container' => trim((string)($_POST['container'] ?? '1100px')),
      'pad' => trim((string)($_POST['pad'] ?? '16px')),
      'gap' => trim((string)($_POST['gap'] ?? '16px')),
      'radius' => [
        'sm' => trim((string)($_POST['radius_sm'] ?? '8px')),
        'md' => trim((string)($_POST['radius_md'] ?? '14px')),
        'lg' => trim((string)($_POST['radius_lg'] ?? '22px')),
      ],
      'colors' => [
        'bg' => trim((string)($_POST['color_bg'] ?? '#ffffff')),
        'text' => trim((string)($_POST['color_text'] ?? '#111111')),
        'muted' => trim((string)($_POST['color_muted'] ?? '#666666')),
        'border' => trim((string)($_POST['color_border'] ?? '#dddddd')),
        'primary' => trim((string)($_POST['color_primary'] ?? '#0d6efd')),
        'secondary' => trim((string)($_POST['color_secondary'] ?? '#ff0000')),
        'primary_text' => trim((string)($_POST['color_primary_text'] ?? '#ffffff')),
        'link' => trim((string)($_POST['color_link'] ?? '#0d6efd')),
      ],
      'type' => [
        'body' => trim((string)($_POST['type_body'] ?? '0.9rem')),
        'h1' => trim((string)($_POST['type_h1'] ?? '3rem')),
        'h2' => trim((string)($_POST['type_h2'] ?? '2rem')),
        'h3' => trim((string)($_POST['type_h3'] ?? '1.5rem')),
        'h4' => trim((string)($_POST['type_h4'] ?? '1.2rem')),
        'h5' => trim((string)($_POST['type_h5'] ?? '1rem')),
      ],
      'fonts' => [
        'body' => trim((string)($_POST['font_body'] ?? '')),
        'body_weight' => trim((string)($_POST['font_body_weight'] ?? 'regular')),
        'heading' => trim((string)($_POST['font_heading'] ?? '')),
        'heading_weight' => trim((string)($_POST['font_heading_weight'] ?? 'bold')),
      ],
    ];

    Storage::writeJson('config/styles.json', $cfg);
    Theme::writeThemeCss($cfg);

    $customCss = (string)($_POST['custom_css'] ?? '');
    GlobalsController::writeCustomCss($customCss);

    $_SESSION['_flash'] = 'Theme gespeichert.';
    header('Location: /admin.php?a=theme');
    exit;
  }

  private function colorField(string $name, string $label, string $value): string {
    $eName = htmlspecialchars($name, ENT_QUOTES);
    $eLabel = htmlspecialchars($label, ENT_QUOTES);
    $eValue = htmlspecialchars($value, ENT_QUOTES);
    return "<div class=\"theme-field\">"
      . "<label>{$eLabel}</label>"
      . "<div class=\"theme-color-wrap\">"
      . "<input type=\"color\" value=\"{$eValue}\" oninput=\"this.nextElementSibling.value=this.value\" aria-label=\"{$eLabel} Farbwahl\">"
      . "<input type=\"text\" name=\"{$eName}\" value=\"{$eValue}\" oninput=\"this.previousElementSibling.value=this.value\">"
      . "</div>"
      . "</div>";
  }

  private function textField(string $name, string $label, string $value): string {
    $eName = htmlspecialchars($name, ENT_QUOTES);
    $eLabel = htmlspecialchars($label, ENT_QUOTES);
    $eValue = htmlspecialchars($value, ENT_QUOTES);
    return "<div class=\"theme-field\">"
      . "<label>{$eLabel}</label>"
      . "<input type=\"text\" name=\"{$eName}\" value=\"{$eValue}\">"
      . "</div>";
  }

  private function selectField(string $name, string $label, string $value, array $options): string {
    $eName = htmlspecialchars($name, ENT_QUOTES);
    $eLabel = htmlspecialchars($label, ENT_QUOTES);
    $opts = '';
    foreach ($options as $opt) {
      $eOpt = htmlspecialchars((string)$opt, ENT_QUOTES);
      $selected = (string)$opt === $value ? ' selected' : '';
      $opts .= "<option value=\"{$eOpt}\"{$selected}>{$eOpt}</option>";
    }
    return "<div class=\"theme-field\">"
      . "<label>{$eLabel}</label>"
      . "<select name=\"{$eName}\">{$opts}</select>"
      . "</div>";
  }

  private function render(string $title, string $content, string $flash = ''): void {
    $tpl = Storage::root() . '/app/views/admin/layout.php';
    require $tpl;
  }
}
