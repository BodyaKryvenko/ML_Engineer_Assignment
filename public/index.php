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

$app->post('/transactions', function (Request $request, Response $response) use ($database): Response {
    $body = $request->getParsedBody();

    if (
        !is_array($body)
        || !isset($body['sender_id'], $body['receiver_id'], $body['amount'], $body['currency'])
        || !is_int($body['sender_id'])
        || !is_int($body['receiver_id'])
        || !is_int($body['amount'])
        || $body['amount'] <= 0
        || $body['currency'] !== 'EUR'
    ) {
        $response->getBody()->write(json_encode([
            'error' => 'sender_id, receiver_id, positive integer amount and currency EUR are required',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }

    if ($body['sender_id'] === $body['receiver_id']) {
        $response->getBody()->write(json_encode([
            'error' => 'sender and receiver must be different users',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }

    $database->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;

    try {
        $statement = $database->prepare('SELECT id, balance FROM users WHERE id = :id');
        $statement->execute(['id' => $body['sender_id']]);
        $sender = $statement->fetch();
        $statement->execute(['id' => $body['receiver_id']]);
        $receiver = $statement->fetch();

        if ($sender === false || $receiver === false) {
            $database->exec('ROLLBACK');
            $transactionOpen = false;

            $response->getBody()->write(json_encode([
                'error' => 'sender or receiver not found',
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        if ($sender['balance'] < $body['amount']) {
            $database->exec('ROLLBACK');
            $transactionOpen = false;

            $response->getBody()->write(json_encode([
                'error' => 'insufficient balance',
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(422);
        }

        $ruleHits = [];

        if ($body['amount'] > 500000) {
            $ruleHits[] = 'HIGH_AMOUNT';
        }

        if (in_array($body['amount'], [100000, 500000, 1000000], true)) {
            $ruleHits[] = 'ROUND_AMOUNT';
        }

        $statement = $database->prepare('UPDATE users SET balance = balance - :amount WHERE id = :id');
        $statement->execute([
            'amount' => $body['amount'],
            'id' => $body['sender_id'],
        ]);
        $statement = $database->prepare('UPDATE users SET balance = balance + :amount WHERE id = :id');
        $statement->execute([
            'amount' => $body['amount'],
            'id' => $body['receiver_id'],
        ]);

        $statement = $database->prepare(
            'INSERT INTO transactions
                (sender_id, receiver_id, amount, currency, status, is_suspicious, rule_hits, created_at)
             VALUES
                (:sender_id, :receiver_id, :amount, :currency, :status, :is_suspicious, :rule_hits, :created_at)'
        );
        $statement->execute([
            'sender_id' => $body['sender_id'],
            'receiver_id' => $body['receiver_id'],
            'amount' => $body['amount'],
            'currency' => 'EUR',
            'status' => 'completed',
            'is_suspicious' => $ruleHits !== [] ? 1 : 0,
            'rule_hits' => json_encode($ruleHits, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('c'),
        ]);

        $transactionId = (int) $database->lastInsertId();
        $database->exec('COMMIT');
        $transactionOpen = false;
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $database->exec('ROLLBACK');
        }

        throw $exception;
    }

    $statement = $database->prepare(
        'SELECT id, sender_id, receiver_id, amount, currency, status, is_suspicious, rule_hits, created_at
         FROM transactions WHERE id = :id'
    );
    $statement->execute(['id' => $transactionId]);
    $transaction = $statement->fetch();
    $transaction['is_suspicious'] = (bool) $transaction['is_suspicious'];
    $transaction['rule_hits'] = json_decode($transaction['rule_hits'], true, 512, JSON_THROW_ON_ERROR);

    $response->getBody()->write(json_encode($transaction, JSON_THROW_ON_ERROR));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(201);
});

$app->get('/transactions/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($database): Response {
    $statement = $database->prepare(
        'SELECT id, sender_id, receiver_id, amount, currency, status, is_suspicious, rule_hits, created_at
         FROM transactions WHERE id = :id'
    );
    $statement->execute(['id' => (int) $args['id']]);
    $transaction = $statement->fetch();

    if ($transaction === false) {
        $response->getBody()->write(json_encode([
            'error' => 'transaction not found',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404);
    }

    $transaction['is_suspicious'] = (bool) $transaction['is_suspicious'];
    $transaction['rule_hits'] = json_decode($transaction['rule_hits'], true, 512, JSON_THROW_ON_ERROR);

    $response->getBody()->write(json_encode($transaction, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/users/{id:[0-9]+}/transactions', function (Request $request, Response $response, array $args) use ($database): Response {
    $statement = $database->prepare('SELECT id FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $args['id']]);

    if ($statement->fetch() === false) {
        $response->getBody()->write(json_encode([
            'error' => 'user not found',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404);
    }

    $statement = $database->prepare(
        'SELECT id, sender_id, receiver_id, amount, currency, status, is_suspicious, rule_hits, created_at
         FROM transactions
         WHERE sender_id = :id OR receiver_id = :id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute(['id' => (int) $args['id']]);
    $transactions = $statement->fetchAll();

    foreach ($transactions as &$transaction) {
        $transaction['is_suspicious'] = (bool) $transaction['is_suspicious'];
        $transaction['rule_hits'] = json_decode($transaction['rule_hits'], true, 512, JSON_THROW_ON_ERROR);
    }
    unset($transaction);

    $response->getBody()->write(json_encode($transactions, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
