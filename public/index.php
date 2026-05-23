<?php

declare(strict_types=1);

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = Connection::create(dirname(__DIR__) . '/var/database.sqlite');

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/health', function (Request $request, Response $response) use ($database): Response {
    $database->query('SELECT 1');

    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'database' => 'connected',
    ], JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

