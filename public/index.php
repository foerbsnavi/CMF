<?php
declare(strict_types=1);

require __DIR__ . '/../app/core/Bootstrap.php';

use App\Core\Router;

$router = new Router();
$router->dispatch();