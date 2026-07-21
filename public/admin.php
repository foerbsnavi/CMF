<?php
declare(strict_types=1);

require __DIR__ . '/../app/core/Bootstrap.php';

use App\Core\Auth;
use App\Admin\PagesController;
use App\Admin\ThemeController;
use App\Admin\MediaController;
use App\Admin\PartialsController;
use App\Admin\UsersController;
use App\Admin\SettingsController;
use App\Admin\BlogController;
use App\Admin\UpdateController;
use App\Admin\FormsController;

Auth::requireLogin();

if (($_GET['a'] ?? '') === 'logout') {
  Auth::logout();
}

$action = $_GET['a'] ?? 'pages';

$map = [
  'pages' => [PagesController::class, 'index'],
  'page_edit' => [PagesController::class, 'edit'],
  'page_save' => [PagesController::class, 'save'],
  'page_new' => [PagesController::class, 'create'],
  'page_delete' => [PagesController::class, 'delete'],
  'page_duplicate' => [PagesController::class, 'duplicate'],
  'page_reorder' => [PagesController::class, 'reorder'],

  'blog' => [BlogController::class, 'index'],
  'blog_edit' => [BlogController::class, 'edit'],
  'blog_save' => [BlogController::class, 'save'],
  'blog_new' => [BlogController::class, 'create'],
  'blog_delete' => [BlogController::class, 'delete'],
  'blog_duplicate' => [BlogController::class, 'duplicate'],
  'blog_reorder' => [BlogController::class, 'reorder'],
  'blog_settings' => [BlogController::class, 'settings'],

  'partials' => [PartialsController::class, 'index'],
  'partial_edit' => [PartialsController::class, 'edit'],
  'partial_save' => [PartialsController::class, 'save'],

  'theme' => [ThemeController::class, 'index'],
  'theme_save' => [ThemeController::class, 'save'],

  'media' => [MediaController::class, 'index'],
  'media_upload' => [MediaController::class, 'upload'],
  'media_delete' => [MediaController::class, 'delete'],
  'media_audit' => [MediaController::class, 'audit'],

  'users' => [UsersController::class, 'index'],
  'user_create' => [UsersController::class, 'create'],
  'user_password' => [UsersController::class, 'password'],
  'user_delete' => [UsersController::class, 'delete'],

  'forms' => [FormsController::class, 'index'],
  'form_view' => [FormsController::class, 'view'],
  'form_mark' => [FormsController::class, 'mark'],
  'form_delete' => [FormsController::class, 'delete'],
  'form_clear' => [FormsController::class, 'clear'],
  'form_export' => [FormsController::class, 'export'],

  'update_run' => [UpdateController::class, 'run'],
  'update_rollback' => [UpdateController::class, 'rollback'],

  'settings' => [SettingsController::class, 'index'],
  'settings_export' => [SettingsController::class, 'export'],
  'settings_import_analyze' => [SettingsController::class, 'importAnalyze'],
  'settings_import_run' => [SettingsController::class, 'importRun'],
  'settings_import_cancel' => [SettingsController::class, 'importCancel'],
];

if (!isset($map[$action])) {
  http_response_code(404);
  echo "Unknown action";
  exit;
}

[$cls, $method] = $map[$action];
$controller = new $cls();
$controller->$method();