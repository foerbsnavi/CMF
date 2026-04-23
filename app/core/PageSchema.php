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
    'blog_overview'
  ];

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
    }
  }
}