<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Formular-Baustein: Rendering, Empfang, Speicherung und optionaler Mailversand.
 *
 * Ein Ort fuer beides — Ausgabe (vom Renderer) UND Verarbeitung (vom Router) —,
 * damit die Feld-Definitionen nur EINMAL interpretiert werden. Die Feldliste ist
 * dabei serverseitig die alleinige Wahrheit: beim Empfang wird die Block-
 * Definition aus der gespeicherten Seite gelesen, NIE der vom Browser gesendeten
 * Feldliste vertraut.
 *
 * Spam-Schutz ohne Cookie (oeffentliche Seiten sollen cookiefrei/cachebar
 * bleiben): Honeypot-Feld + HMAC-signierte Zeit-Falle. Session-CSRF wird bewusst
 * NICHT verwendet, weil es jedem anonymen Besucher ein Cookie aufzwingen wuerde.
 */
final class FormHandler {

  private const FIELD_PREFIX = 'f_';
  private const HP_FIELD = '__cmf_hp';        // Honeypot — muss leer bleiben
  private const TS_FIELD = '__cmf_ts';        // signierter Zeitstempel
  private const ID_FIELD = '__cmf_form';      // Block-ID des Formulars
  private const MIN_SECONDS = 2;              // schneller = Bot
  private const MAX_SECONDS = 3600;           // aelter = abgelaufen
  private const MAX_TEXT = 200;
  private const MAX_TEXTAREA = 5000;
  private const MAX_SUBMISSIONS = 2000;       // Kappung pro Formular

  /** @var array<string,array<string,string>> blockId => feld => Fehlermeldung */
  private static array $errors = [];
  /** @var array<string,array<string,string>> blockId => feld => alter Wert */
  private static array $old = [];

  // ── Ausgabe ─────────────────────────────────────────────────────────

  /**
   * Rendert den <form>-Block. Wird vom Renderer aufgerufen. Zeigt nach
   * erfolgreichem Absenden (PRG-Redirect mit ?sent=<id>) die Erfolgsmeldung
   * statt des Formulars.
   */
  public static function render(array $data, string $blockId): string {
    $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
    if ($fields === []) return '';

    $sent = trim((string)($_GET['sent'] ?? ''));
    if ($sent !== '' && $sent === $blockId) {
      $msg = trim((string)($data['success_message'] ?? '')) ?: 'Vielen Dank! Ihre Nachricht wurde gesendet.';
      return '<section class="cmf-form-section" id="' . self::e($blockId) . '"><p class="cmf-form-success" role="status">'
        . self::e($msg) . '</p></section>';
    }

    $title = trim((string)($data['title'] ?? ''));
    $intro = trim((string)($data['intro'] ?? ''));
    $submitLabel = trim((string)($data['submit_label'] ?? '')) ?: 'Absenden';

    $out = '<section class="cmf-form-section" id="' . self::e($blockId) . '">';
    if ($title !== '') $out .= '<h2>' . self::e($title) . '</h2>';
    if ($intro !== '') $out .= '<p class="cmf-form-intro">' . self::e($intro) . '</p>';

    if (isset(self::$errors[$blockId])) {
      // Eine allgemeine Meldung (Schluessel '') — z.B. abgelaufenes Token — wird
      // woertlich ausgegeben; sonst der Sammelhinweis auf markierte Felder.
      $general = trim((string)(self::$errors[$blockId][''] ?? ''));
      $msg = $general !== '' ? $general : 'Bitte prüfen Sie die markierten Felder.';
      $out .= '<p class="cmf-form-error" role="alert">' . self::e($msg) . '</p>';
    }

    // action="" postet auf die aktuelle Seiten-URL (erfuellt CSP form-action 'self').
    // Native Browser-Validierung bleibt an (bessere UX); der Server validiert
    // unabhaengig davon erneut und ist die alleinige Autoritaet.
    $out .= '<form class="cmf-form" method="post" action="">';
    $out .= '<input type="hidden" name="' . self::ID_FIELD . '" value="' . self::e($blockId) . '">';
    $out .= '<input type="hidden" name="' . self::TS_FIELD . '" value="' . self::e(self::makeTsToken()) . '">';
    // Honeypot: visuell und fuer Screenreader verborgen, fuer Bots sichtbar
    $out .= '<div class="cmf-hp" aria-hidden="true">'
      . '<label>Dieses Feld bitte leer lassen'
      . '<input type="text" name="' . self::HP_FIELD . '" tabindex="-1" autocomplete="off"></label></div>';

    foreach ($fields as $field) {
      if (!is_array($field)) continue;
      $out .= self::renderField($field, $blockId);
    }

    $out .= '<div class="cmf-form-actions"><button class="btn primary" type="submit">'
      . self::e($submitLabel) . '</button></div>';
    $out .= '</form></section>';
    return $out;
  }

  private static function renderField(array $field, string $blockId): string {
    $name = trim((string)($field['name'] ?? ''));
    $type = trim((string)($field['type'] ?? 'text'));
    $label = trim((string)($field['label'] ?? ''));
    if ($name === '' || $label === '') return '';
    $required = !empty($field['required']);

    $inputName = self::FIELD_PREFIX . $name;
    $fieldId = 'ff_' . self::e($blockId) . '_' . self::e($name);
    $old = (string)(self::$old[$blockId][$name] ?? '');
    $err = (string)(self::$errors[$blockId][$name] ?? '');
    $reqAttr = $required ? ' required' : '';
    $reqMark = $required ? ' <span class="cmf-req" aria-hidden="true">*</span>' : '';
    $errId = $err !== '' ? $fieldId . '_err' : '';
    $descAttr = $err !== '' ? ' aria-describedby="' . $errId . '" aria-invalid="true"' : '';

    $labelHtml = '<label for="' . $fieldId . '">' . self::e($label) . $reqMark . '</label>';
    $control = '';

    switch ($type) {
      case 'textarea':
        $control = '<textarea id="' . $fieldId . '" name="' . self::e($inputName) . '" rows="5" maxlength="'
          . self::MAX_TEXTAREA . '"' . $reqAttr . $descAttr . '>' . self::e($old) . '</textarea>';
        break;

      case 'select':
        $opts = '<option value="">Bitte wählen…</option>';
        foreach (self::options($field) as $opt) {
          $sel = ($old === $opt) ? ' selected' : '';
          $opts .= '<option value="' . self::e($opt) . '"' . $sel . '>' . self::e($opt) . '</option>';
        }
        $control = '<select id="' . $fieldId . '" name="' . self::e($inputName) . '"' . $reqAttr . $descAttr . '>'
          . $opts . '</select>';
        break;

      case 'radio':
        // Radio-Gruppe barrierefrei: fieldset + legend als Gruppenbeschriftung
        $control = '<fieldset class="cmf-radio-group"' . $descAttr . '>'
          . '<legend class="cmf-label">' . self::e($label) . $reqMark . '</legend>';
        $i = 0;
        foreach (self::options($field) as $opt) {
          $optId = $fieldId . '_' . $i++;
          $chk = ($old === $opt) ? ' checked' : '';
          $control .= '<label class="cmf-radio" for="' . $optId . '">'
            . '<input type="radio" id="' . $optId . '" name="' . self::e($inputName) . '" value="'
            . self::e($opt) . '"' . ($required ? ' required' : '') . $chk . '> ' . self::e($opt) . '</label>';
        }
        $control .= '</fieldset>';
        $labelHtml = ''; // Beschriftung steckt in der <legend>
        break;

      case 'checkbox':
        $chk = ($old !== '') ? ' checked' : '';
        $control = '<label class="cmf-checkbox" for="' . $fieldId . '">'
          . '<input type="checkbox" id="' . $fieldId . '" name="' . self::e($inputName) . '" value="1"'
          . $reqAttr . $descAttr . $chk . '> ' . self::e($label) . $reqMark . '</label>';
        $labelHtml = ''; // Label steckt im Checkbox-Label
        break;

      case 'email':
      case 'tel':
      case 'text':
      default:
        $inputType = in_array($type, ['email', 'tel'], true) ? $type : 'text';
        // autocomplete nur wo der Zweck eindeutig ist (WCAG 1.3.5 Input Purpose)
        $auto = $type === 'email' ? ' autocomplete="email"' : ($type === 'tel' ? ' autocomplete="tel"' : '');
        $control = '<input type="' . $inputType . '" id="' . $fieldId . '" name="' . self::e($inputName)
          . '" value="' . self::e($old) . '" maxlength="' . self::MAX_TEXT . '"' . $auto . $reqAttr . $descAttr . '>';
        break;
    }

    $errHtml = $err !== '' ? '<span class="cmf-field-error" id="' . $errId . '">' . self::e($err) . '</span>' : '';
    return '<div class="cmf-field cmf-field-' . self::e($type) . '">' . $labelHtml . $control . $errHtml . '</div>';
  }

  // ── Empfang ─────────────────────────────────────────────────────────

  /**
   * Verarbeitet einen Formular-POST. Wird vom Router aufgerufen, wenn
   * $_POST[ID_FIELD] gesetzt ist. Bei Erfolg: 303-Redirect (PRG) und exit.
   * Bei Validierungsfehler: kein Redirect — Fehler/alte Eingaben werden fuer
   * das anschliessende Neu-Rendern der Seite gemerkt und die Methode kehrt
   * zurueck, damit der Router die Seite normal ausliefert.
   */
  public static function handle(string $slug, array $pageData): void {
    $blockId = trim((string)($_POST[self::ID_FIELD] ?? ''));
    if ($blockId === '') return;

    $block = self::findFormBlock($pageData['content']['blocks'] ?? [], $blockId);
    if ($block === null) {
      // Unbekanntes/entferntes Formular — stillschweigend zur Seite zurueck
      self::redirectBack($slug);
      return;
    }
    $data = is_array($block['data'] ?? null) ? $block['data'] : [];
    $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

    // Spam-Schutz: Honeypot gefuellt → so tun als waere alles gut (kein Hinweis fuer Bots)
    if (trim((string)($_POST[self::HP_FIELD] ?? '')) !== '') {
      self::redirectSuccess($slug, $blockId);
      return;
    }
    // Zeit-Falle: signierten Zeitstempel pruefen
    if (!self::checkTsToken((string)($_POST[self::TS_FIELD] ?? ''))) {
      self::$errors[$blockId] = ['' => 'Das Formular ist abgelaufen. Bitte erneut absenden.'];
      return;
    }

    $errors = [];
    $clean = [];
    foreach ($fields as $field) {
      if (!is_array($field)) continue;
      $name = trim((string)($field['name'] ?? ''));
      $type = trim((string)($field['type'] ?? 'text'));
      $label = trim((string)($field['label'] ?? $name));
      if ($name === '') continue;

      $raw = $_POST[self::FIELD_PREFIX . $name] ?? '';
      if (!is_string($raw)) $raw = '';
      $value = trim($raw);
      $required = !empty($field['required']);

      // Laengenkappung je Typ
      $cap = ($type === 'textarea') ? self::MAX_TEXTAREA : self::MAX_TEXT;
      if (mb_strlen($value) > $cap) $value = mb_substr($value, 0, $cap);

      self::$old[$blockId][$name] = $value; // fuer Neu-Rendern bei Fehler

      if ($type === 'checkbox') {
        $checked = ($value !== '');
        if ($required && !$checked) {
          $errors[$name] = 'Bitte bestätigen.';
        }
        $clean[$name] = ['label' => $label, 'value' => $checked ? 'Ja' : 'Nein'];
        continue;
      }

      if ($required && $value === '') {
        $errors[$name] = 'Pflichtfeld.';
        $clean[$name] = ['label' => $label, 'value' => ''];
        continue;
      }

      if ($value !== '') {
        if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
          $errors[$name] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (in_array($type, ['select', 'radio'], true)
                  && !in_array($value, self::options($field), true)) {
          $errors[$name] = 'Bitte eine gültige Auswahl treffen.';
        }
      }

      $clean[$name] = ['label' => $label, 'value' => $value];
    }

    if ($errors !== []) {
      self::$errors[$blockId] = $errors;
      return;
    }

    // Speichern (optional, Default an)
    if (($data['store'] ?? true) !== false) {
      self::store($slug, $blockId, $data, $clean);
    }

    // Mail (optional)
    $emailTo = trim((string)($data['email_to'] ?? ''));
    if ($emailTo !== '' && filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
      self::sendMail($emailTo, $data, $clean);
    }

    self::redirectSuccess($slug, $blockId);
  }

  // ── Speicherung ─────────────────────────────────────────────────────

  private static function store(string $slug, string $blockId, array $data, array $clean): void {
    $path = 'content/form-submissions/' . self::safeName($slug) . '_' . self::safeName($blockId) . '.json';
    $store = Storage::readJson($path);
    if (!isset($store['submissions']) || !is_array($store['submissions'])) {
      $store = [
        'form' => [
          'page' => $slug,
          'block' => $blockId,
          'title' => trim((string)($data['title'] ?? '')),
        ],
        'submissions' => [],
      ];
    }
    // Titel aktuell halten (kann sich im Editor geaendert haben)
    $store['form']['title'] = trim((string)($data['title'] ?? ($store['form']['title'] ?? '')));

    $ipHash = '';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '') $ipHash = substr(hash('sha256', $ip . '|' . self::secret()), 0, 16);

    $store['submissions'][] = [
      'ts' => gmdate('c'),
      'ip_hash' => $ipHash,
      'read' => false,
      'data' => array_map(
        fn(array $f): array => ['label' => (string)$f['label'], 'value' => (string)$f['value']],
        $clean
      ),
    ];

    // Kappung: nur die juengsten MAX_SUBMISSIONS behalten
    if (count($store['submissions']) > self::MAX_SUBMISSIONS) {
      $store['submissions'] = array_slice($store['submissions'], -self::MAX_SUBMISSIONS);
    }

    Storage::writeJson($path, $store);
  }

  // ── Mail ────────────────────────────────────────────────────────────

  private static function sendMail(string $to, array $data, array $clean): void {
    if (!function_exists('mail')) return; // sauberer Fallback

    $site = Storage::readJson('config/site.json');
    $siteName = self::headerSafe((string)($site['name'] ?? 'Webseite'));
    $host = parse_url((string)($site['baseUrl'] ?? ''), PHP_URL_HOST) ?: 'localhost';
    $from = 'noreply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);

    $title = trim((string)($data['title'] ?? ''));
    $subject = self::headerSafe('Neue Formular-Einsendung' . ($title !== '' ? ': ' . $title : ''));

    $lines = [];
    foreach ($clean as $f) {
      $lines[] = $f['label'] . ': ' . $f['value'];
    }
    $body = implode("\n", $lines) . "\n";

    $headers = 'From: ' . $siteName . ' <' . $from . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    // Reply-To auf eine eingesandte, gueltige E-Mail-Adresse (falls vorhanden)
    foreach ($clean as $f) {
      $v = (string)$f['value'];
      if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
        $headers .= 'Reply-To: ' . self::headerSafe($v) . "\r\n";
        break;
      }
    }

    @mail($to, $subject, $body, $headers);
  }

  /** Entfernt CR/LF (und Kontrollzeichen) — verhindert Header-Injection. */
  private static function headerSafe(string $s): string {
    return trim((string)preg_replace('/[\r\n\x00]+/', ' ', $s));
  }

  // ── Zeit-Falle (HMAC) ───────────────────────────────────────────────

  private static function makeTsToken(): string {
    $ts = (string)time();
    return $ts . '.' . hash_hmac('sha256', $ts, self::secret());
  }

  private static function checkTsToken(string $token): bool {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$ts, $sig] = $parts;
    if (!ctype_digit($ts)) return false;
    $expected = hash_hmac('sha256', $ts, self::secret());
    if (!hash_equals($expected, $sig)) return false;
    $age = time() - (int)$ts;
    return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
  }

  /** Per-Installation-Geheimnis; bei Erststart erzeugt, nie im ZIP mitgeliefert. */
  private static function secret(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $conf = Storage::readJson('config/secret.json');
    $s = trim((string)($conf['form_hmac'] ?? ''));
    if ($s === '') {
      $s = bin2hex(random_bytes(32));
      try {
        Storage::writeJson('config/secret.json', ['form_hmac' => $s]);
      } catch (\Throwable $e) {
        // config/ nicht schreibbar: oeffentliche Seite darf nicht crashen.
        // Deterministischer Fallback (pro Installation stabil), damit die
        // Zeitfalle ueber mehrere Requests hinweg trotzdem verifizierbar bleibt.
        error_log('CMF: config/secret.json nicht schreibbar — Formular-Fallback-Secret aktiv: ' . $e->getMessage());
        $s = hash('sha256', 'cmf-form-fallback|' . Storage::root());
      }
    }
    return $cached = $s;
  }

  // ── Hilfen ──────────────────────────────────────────────────────────

  /** @return list<string> */
  private static function options(array $field): array {
    $opts = is_array($field['options'] ?? null) ? $field['options'] : [];
    $out = [];
    foreach ($opts as $o) {
      if (is_string($o) && trim($o) !== '') $out[] = trim($o);
    }
    return $out;
  }

  /** Findet den Form-Block per ID (auch in Spalten verschachtelt). */
  private static function findFormBlock(array $blocks, string $blockId): ?array {
    foreach ($blocks as $b) {
      if (!is_array($b)) continue;
      if ((string)($b['type'] ?? '') === 'form' && (string)($b['id'] ?? '') === $blockId) {
        return $b;
      }
      if ((string)($b['type'] ?? '') === 'columns') {
        foreach ($b['data']['items'] ?? [] as $col) {
          if (is_array($col)) {
            $found = self::findFormBlock($col, $blockId);
            if ($found !== null) return $found;
          }
        }
      }
    }
    return null;
  }

  /** Erlaubt nur Dateiname-sichere Zeichen (kein Pfad-Traversal). */
  private static function safeName(string $s): string {
    $s = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $s) ?? '';
    return substr($s, 0, 80);
  }

  private static function redirectSuccess(string $slug, string $blockId): void {
    $base = $slug === 'home' ? '/' : '/' . ltrim($slug, '/');
    self::redirect($base . '?sent=' . rawurlencode($blockId) . '#' . rawurlencode($blockId));
  }

  private static function redirectBack(string $slug): void {
    self::redirect($slug === 'home' ? '/' : '/' . ltrim($slug, '/'));
  }

  private static function redirect(string $location): void {
    http_response_code(303);
    header('Location: ' . $location);
    exit;
  }

  public static function hasErrors(string $blockId): bool {
    return isset(self::$errors[$blockId]);
  }

  private static function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
