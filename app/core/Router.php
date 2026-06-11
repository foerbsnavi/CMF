<?php
declare(strict_types=1);

namespace App\Core;

final class Router {
  public function dispatch(): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    $slug = trim($path, '/');
    if ($slug === '') $slug = 'home';

    // Markdown-Variante: /{slug}.md liefert die Seite als Markdown
    $wantsMarkdown = false;
    if (str_ends_with($slug, '.md')) {
      $wantsMarkdown = true;
      $slug = substr($slug, 0, -3);
      if ($slug === '') $slug = 'home';
    }

    $pagesIndex = Storage::readJson('content/pages.json');
    $site = Storage::readJson('config/site.json');
    $baseUrl = rtrim((string)($site['baseUrl'] ?? ''), '/');

    // Blog-Post-Routing: /{blog-slug}/{post-slug}
    if (str_contains($slug, '/')) {
      $blogIndex = Storage::readJson('content/blog.json');
      $blogSlug = trim((string)($blogIndex['slug'] ?? 'blog'));
      $prefix = $blogSlug . '/';
      if (str_starts_with($slug, $prefix) && strlen($slug) > strlen($prefix)) {
        $postSlug = substr($slug, strlen($prefix));
        if (preg_match('#^[a-z0-9][a-z0-9\-]*$#', $postSlug)) {
          foreach ($blogIndex['posts'] ?? [] as $post) {
            if (($post['slug'] ?? '') === $postSlug && ($post['status'] ?? 'draft') === 'published') {
              $postData = Storage::readJson('content/blog/' . $post['id'] . '.json');
              if ($wantsMarkdown) {
                self::sendMarkdown(Markdown::page($blogSlug . '/' . $postSlug, $postData, $baseUrl));
                return;
              }
              // Index-Daten fuer Article-Schema (ld+json) mitgeben
              $postData['_post'] = [
                'title' => (string)($post['title'] ?? ''),
                'description' => (string)($post['description'] ?? ''),
                'image' => (string)($post['image'] ?? ''),
                'created' => (string)($post['created'] ?? ''),
                'updated' => (string)($post['updated'] ?? '')
              ];
              echo Renderer::renderPage($blogSlug . '/' . $postSlug, $postData, $pagesIndex);
              return;
            }
          }
        }
      }
    }
    $pages = $pagesIndex['pages'] ?? [];
    $page = null;
    foreach ($pages as $p) {
      if (($p['slug'] ?? '') === $slug && ($p['status'] ?? 'draft') === 'published') { $page = $p; break; }
    }

    if (!$page) {
      if ($wantsMarkdown) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 — Seite nicht gefunden.\n";
        return;
      }
      http_response_code(404);
      $pageData = [
        'meta' => ['title' => 'Seite nicht gefunden', 'description' => 'Die angeforderte Seite existiert nicht.'],
        'content' => ['blocks' => [
          ['id'=>'b1','type'=>'heading','data'=>['level'=>1,'text'=>'Seite nicht gefunden']],
          ['id'=>'b2','type'=>'text','data'=>['html'=>'<p>Die angeforderte Seite <strong>/' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</strong> existiert nicht oder wurde verschoben.</p>']],
          ['id'=>'b3','type'=>'buttons','data'=>['items'=>[
            ['label'=>'Zur Startseite','href'=>'/','style'=>'primary'],
            ['label'=>'Anleitungen','href'=>'/anleitungen','style'=>''],
          ]]]
        ]]
      ];
      echo Renderer::renderPage('404', $pageData, $pagesIndex);
      return;
    }

    $pageData = Storage::readJson('content/pages/' . $page['id'] . '.json');

    if ($wantsMarkdown) {
      self::sendMarkdown(Markdown::page($slug, $pageData, $baseUrl));
      return;
    }

    echo Renderer::renderPage($slug, $pageData, $pagesIndex);
  }

  private static function sendMarkdown(string $md): void {
    header('Content-Type: text/markdown; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    echo $md;
  }
}
