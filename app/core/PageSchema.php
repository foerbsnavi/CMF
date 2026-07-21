<?php
declare(strict_types=1);

namespace App\Core;

final class PageSchema {

  private const BLOCK_TYPES = [
    'heading',
    'text',
    'image',
    'list',
    'buttons',
    'columns',
    'html',
    'blog_overview',
    'form'
  ];

  private const FORM_FIELD_TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'radio'];

  public static function validate(array $page): array {
    $errors = [];
    $ids = [];

    if (!isset($page['meta']) || !is_array($page['meta'])) {
      $errors[] = 'meta fehlt';
    }

    if (!array_key_exists('title', $page['meta'] ?? []) || trim((string)($page['meta']['title'] ?? '')) === '') {
      $errors[] = 'meta.title fehlt';
    }

    if (!isset($page['content']) || !is_array($page['content'])) {
      $errors[] = 'content fehlt';
      return [
        'ok' => false,
        'errors' => $errors
      ];
    }

    if (!isset($page['content']['blocks']) || !is_array($page['content']['blocks'])) {
      $errors[] = 'content.blocks fehlt';
      return [
        'ok' => false,
        'errors' => $errors
      ];
    }

    foreach ($page['content']['blocks'] as $i => $block) {
      self::validateBlock($block, $ids, $errors, 'content.blocks.' . $i);
    }

    return [
      'ok' => $errors === [],
      'errors' => $errors
    ];
  }

  private static function validateBlock(mixed $block, array &$ids, array &$errors, string $path): void {
    if (!is_array($block)) {
      $errors[] = "{$path} ist kein objekt";
      return;
    }

    $id = trim((string)($block['id'] ?? ''));
    if ($id === '') {
      $errors[] = "{$path} ohne id";
      return;
    }

    if (in_array($id, $ids, true)) {
      $errors[] = "doppelte id: {$id}";
    }

    $ids[] = $id;

    $type = trim((string)($block['type'] ?? ''));
    if ($type === '') {
      $errors[] = "block {$id} ohne type";
      return;
    }

    if (!in_array($type, self::BLOCK_TYPES, true)) {
      $errors[] = "unbekannter blocktyp: {$type}";
      return;
    }

    $data = $block['data'] ?? null;
    if (!is_array($data)) {
      $errors[] = "block {$id} ohne data";
      return;
    }

    switch ($type) {
      case 'heading':
        $level = (int)($data['level'] ?? 0);
        if ($level < 1 || $level > 6) {
          $errors[] = "heading {$id} level ungültig";
        }
        if (trim((string)($data['text'] ?? '')) === '') {
          $errors[] = "heading {$id} text fehlt";
        }
        break;

      case 'text':
        if (!array_key_exists('html', $data) || !is_string($data['html'])) {
          $errors[] = "text {$id} html fehlt";
        }
        break;

      case 'image':
        if (trim((string)($data['src'] ?? '')) === '') {
          $errors[] = "image {$id} src fehlt";
        }
        if (!array_key_exists('alt', $data) || !is_string($data['alt'])) {
          $errors[] = "image {$id} alt fehlt";
        }
        // width/height optional — verhindern Layout-Spruenge (CLS)
        foreach (['width', 'height'] as $dim) {
          if (array_key_exists($dim, $data) && (!is_numeric($data[$dim]) || (int)$data[$dim] < 1)) {
            $errors[] = "image {$id} {$dim} ungültig";
          }
        }
        break;

      case 'list':
        if (!array_key_exists('ordered', $data) || !is_bool($data['ordered'])) {
          $errors[] = "list {$id} ordered fehlt oder ungültig";
        }
        if (!isset($data['items']) || !is_array($data['items'])) {
          $errors[] = "list {$id} items fehlen";
          break;
        }
        foreach ($data['items'] as $i => $item) {
          if (!is_string($item)) {
            $errors[] = "list {$id} item {$i} ist kein string";
          }
        }
        break;

      case 'buttons':
        if (!isset($data['items']) || !is_array($data['items']) || $data['items'] === []) {
          $errors[] = "buttons {$id} items fehlen";
          break;
        }
        foreach ($data['items'] as $i => $item) {
          if (!is_array($item)) {
            $errors[] = "buttons {$id} item {$i} ungültig";
            continue;
          }
          if (trim((string)($item['label'] ?? '')) === '') {
            $errors[] = "buttons {$id} item {$i} label fehlt";
          }
          if (trim((string)($item['href'] ?? '')) === '') {
            $errors[] = "buttons {$id} item {$i} href fehlt";
          }
          if (array_key_exists('style', $item) && !is_string($item['style'])) {
            $errors[] = "buttons {$id} item {$i} style ungültig";
          }
        }
        break;

      case 'columns':
        $columns = (int)($data['columns'] ?? 0);
        if ($columns < 2 || $columns > 5) {
          $errors[] = "columns {$id} spaltenzahl ungültig";
          break;
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
          $errors[] = "columns {$id} items fehlen";
          break;
        }

        if (count($data['items']) !== $columns) {
          $errors[] = "columns {$id} items passen nicht zur spaltenzahl";
        }

        foreach ($data['items'] as $colIndex => $column) {
          if (!is_array($column)) {
            $errors[] = "columns {$id} spalte {$colIndex} ungültig";
            continue;
          }

          foreach ($column as $blockIndex => $subBlock) {
            self::validateBlock($subBlock, $ids, $errors, "columns {$id} spalte {$colIndex} block {$blockIndex}");
          }
        }
        break;

      case 'html':
        if (!array_key_exists('code', $data) || !is_string($data['code'])) {
          $errors[] = "html {$id} code fehlt";
        }
        break;

      case 'blog_overview':
        break;

      case 'form':
        if (!isset($data['fields']) || !is_array($data['fields']) || $data['fields'] === []) {
          $errors[] = "form {$id} fields fehlen";
          break;
        }
        if (array_key_exists('email_to', $data) && trim((string)$data['email_to']) !== ''
            && !filter_var(trim((string)$data['email_to']), FILTER_VALIDATE_EMAIL)) {
          $errors[] = "form {$id} email_to ungültig";
        }
        $names = [];
        foreach ($data['fields'] as $fi => $field) {
          if (!is_array($field)) {
            $errors[] = "form {$id} feld {$fi} ungültig";
            continue;
          }
          $fname = trim((string)($field['name'] ?? ''));
          if ($fname === '') {
            $errors[] = "form {$id} feld {$fi} ohne name";
          } elseif (!preg_match('/^[a-z0-9_]+$/', $fname)) {
            $errors[] = "form {$id} feld {$fi} name ungültig (nur a-z 0-9 _)";
          } elseif (in_array($fname, $names, true)) {
            $errors[] = "form {$id} doppelter feldname: {$fname}";
          } else {
            $names[] = $fname;
          }
          if (trim((string)($field['label'] ?? '')) === '') {
            $errors[] = "form {$id} feld {$fi} label fehlt";
          }
          $ftype = trim((string)($field['type'] ?? ''));
          if (!in_array($ftype, self::FORM_FIELD_TYPES, true)) {
            $errors[] = "form {$id} feld {$fi} unbekannter typ: {$ftype}";
          }
          if (array_key_exists('required', $field) && !is_bool($field['required'])) {
            $errors[] = "form {$id} feld {$fi} required ungültig";
          }
          if (in_array($ftype, ['select', 'radio'], true)) {
            if (!isset($field['options']) || !is_array($field['options']) || $field['options'] === []) {
              $errors[] = "form {$id} feld {$fi} options fehlen";
            } else {
              foreach ($field['options'] as $oi => $opt) {
                if (!is_string($opt) || trim($opt) === '') {
                  $errors[] = "form {$id} feld {$fi} option {$oi} ungültig";
                }
              }
            }
          }
        }
        break;
    }
  }
}