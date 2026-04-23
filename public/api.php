<?php
declare(strict_types=1);

require __DIR__ . '/../app/core/Bootstrap.php';

use App\Core\ApiAuth;
use App\Core\Sitemap;
use App\Api\PagesController;
use App\Api\GlobalsController;
use App\Api\MediaController;
use App\Api\BlogController;

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['a'] ?? '');

// Oeffentliche Endpunkte – kein Token noetig
if ($action === 'search_index' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
  $controller = new GlobalsController();
  $controller->searchIndex();
}

if ($action === 'version_check' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
  $v = \App\Core\Storage::readJson('version.json');
  header('Access-Control-Allow-Origin: *');
  echo json_encode([
    'ok' => true,
    'data' => [
      'version' => (string)($v['version'] ?? '0.0.0'),
      'date' => (string)($v['date'] ?? ''),
      'changelog' => $v['changelog'] ?? [],
      'download_url' => '/files/cmf_latest.zip'
    ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

ApiAuth::requireToken();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$map = [
  'pages' => ['GET', PagesController::class, 'index'],
  'page' => ['GET', PagesController::class, 'show'],
  'page_create' => ['POST', PagesController::class, 'create'],
  'page_update' => ['POST', PagesController::class, 'update'],
  'page_delete' => ['POST', PagesController::class, 'delete'],

  'blog_posts' => ['GET', BlogController::class, 'index'],
  'blog_post' => ['GET', BlogController::class, 'show'],
  'blog_create' => ['POST', BlogController::class, 'create'],
  'blog_update' => ['POST', BlogController::class, 'update'],
  'blog_delete' => ['POST', BlogController::class, 'delete'],

  'site' => ['GET', GlobalsController::class, 'site'],
  'site_update' => ['POST', GlobalsController::class, 'siteUpdate'],
  'site_bundle' => ['GET', GlobalsController::class, 'bundle'],

  'partial' => ['GET', GlobalsController::class, 'partial'],
  'partial_update' => ['POST', GlobalsController::class, 'partialUpdate'],

  'styles' => ['GET', GlobalsController::class, 'styles'],
  'styles_update' => ['POST', GlobalsController::class, 'stylesUpdate'],

  'custom_css' => ['GET', GlobalsController::class, 'customCss'],
  'custom_css_update' => ['POST', GlobalsController::class, 'customCssUpdate'],

  'site_export' => ['GET', GlobalsController::class, 'siteExport'],
  'site_import' => ['POST', GlobalsController::class, 'siteImport'],

  'media' => ['GET', MediaController::class, 'index'],
  'media_upload' => ['POST', MediaController::class, 'upload'],
  'media_usage' => ['GET', MediaController::class, 'usage'],
  'media_delete' => ['POST', MediaController::class, 'delete'],

  'pages_export' => ['GET', GlobalsController::class, 'pagesExport'],
  'pages_import' => ['POST', GlobalsController::class, 'pagesImport'],

  'search_index' => ['GET', GlobalsController::class, 'searchIndex'],
];

if ($action === 'sitemap_generate') {
  Sitemap::write();
  echo json_encode(['ok' => true, 'data' => ['generated' => true]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (!isset($map[$action])) {
  http_response_code(404);
  echo json_encode([
    'ok' => false,
    'error' => 'unknown_action'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

[$allowedMethod, $class, $handler] = $map[$action];

if ($method !== $allowedMethod) {
  http_response_code(405);
  header('Allow: ' . $allowedMethod);
  echo json_encode([
    'ok' => false,
    'error' => 'method_not_allowed'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$controller = new $class();
$controller->$handler();