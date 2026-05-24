<?php

declare(strict_types=1);

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = Connection::create(dirname(__DIR__) . '/var/database.sqlite');

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
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

$app->post('/users', function (Request $request, Response $response) use ($database): Response {
    $body = $request->getParsedBody();

    if (
        !is_array($body)
        || !isset($body['username'], $body['balance'])
        || !is_string($body['username'])
        || trim($body['username']) === ''
        || !is_int($body['balance'])
        || $body['balance'] < 0
    ) {
        $response->getBody()->write(json_encode([
            'error' => 'username and a non-negative integer balance are required',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }

    $statement = $database->prepare(
        'INSERT INTO users (username, balance, created_at) VALUES (:username, :balance, :created_at)'
    );

    try {
        $statement->execute([
            'username' => trim($body['username']),
            'balance' => $body['balance'],
            'created_at' => gmdate('c'),
        ]);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: users.username')) {
            $response->getBody()->write(json_encode([
                'error' => 'username already exists',
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(409);
        }

        throw $exception;
    }

    $statement = $database->prepare('SELECT id, username, balance, created_at FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $database->lastInsertId()]);

    $response->getBody()->write(json_encode($statement->fetch(), JSON_THROW_ON_ERROR));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(201);
});

$app->get('/users/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($database): Response {
    $statement = $database->prepare('SELECT id, username, balance, created_at FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $args['id']]);
    $user = $statement->fetch();

    if ($user === false) {
        $response->getBody()->write(json_encode([
            'error' => 'user not found',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404);
    }

    $response->getBody()->write(json_encode($user, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
