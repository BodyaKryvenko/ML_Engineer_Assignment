<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Event\SyncEventDispatcher;
use App\Event\TransactionCreated;
use App\Monitoring\FeatureExtractor;
use App\Monitoring\MonitoringHandler;
use App\Monitoring\MockInferenceClient;
use App\Repository\FeatureRepository;
use App\Repository\LabelRepository;
use App\Repository\TransactionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$databasePath = getenv('DATABASE_PATH') ?: dirname(__DIR__) . '/var/database.sqlite';
$database = Connection::create($databasePath);
$transactions = new TransactionRepository($database);
$features = new FeatureRepository($database);
$labels = new LabelRepository($database);
$inference = new MockInferenceClient(dirname(__DIR__) . '/resources/models/fraud_model_custom.json');
$events = new SyncEventDispatcher(
    new MonitoringHandler($transactions, new FeatureExtractor($database), $features, $labels, $inference)
);

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

$app->post('/transactions', function (Request $request, Response $response) use ($database, $transactions, $events): Response {
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

    $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
    $idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;

    if ($idempotencyKey !== null && strlen($idempotencyKey) > 255) {
        $response->getBody()->write(json_encode([
            'error' => 'idempotency key must not be longer than 255 characters',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }

    $requestHash = $idempotencyKey === null ? null : hash('sha256', json_encode([
        'sender_id' => $body['sender_id'],
        'receiver_id' => $body['receiver_id'],
        'amount' => $body['amount'],
        'currency' => 'EUR',
    ], JSON_THROW_ON_ERROR));

    $database->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;

    try {
        if ($idempotencyKey !== null) {
            $existingTransaction = $transactions->findByIdempotencyKey($idempotencyKey);

            if ($existingTransaction !== null) {
                $database->exec('ROLLBACK');
                $transactionOpen = false;

                if (!hash_equals((string) $existingTransaction['request_hash'], (string) $requestHash)) {
                    $response->getBody()->write(json_encode([
                        'error' => 'idempotency key has already been used for another transaction',
                    ], JSON_THROW_ON_ERROR));

                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withStatus(409);
                }

                unset($existingTransaction['request_hash']);

                $response->getBody()->write(json_encode($existingTransaction, JSON_THROW_ON_ERROR));

                return $response->withHeader('Content-Type', 'application/json');
            }
        }

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

        $senderBalanceAfter = $sender['balance'] - $body['amount'];
        $receiverBalanceAfter = $receiver['balance'] + $body['amount'];

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

        $transactionId = $transactions->create([
            'sender_id' => $body['sender_id'],
            'receiver_id' => $body['receiver_id'],
            'amount' => $body['amount'],
            'currency' => 'EUR',
            'status' => 'completed',
            'sender_balance_before' => $sender['balance'],
            'sender_balance_after' => $senderBalanceAfter,
            'receiver_balance_before' => $receiver['balance'],
            'receiver_balance_after' => $receiverBalanceAfter,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'created_at' => gmdate('c'),
        ]);

        $database->exec('COMMIT');
        $transactionOpen = false;
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $database->exec('ROLLBACK');
        }

        throw $exception;
    }

    $events->dispatch(new TransactionCreated($transactionId));
    $transaction = $transactions->find($transactionId);

    $response->getBody()->write(json_encode($transaction, JSON_THROW_ON_ERROR));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(201);
});

$app->get('/transactions/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($transactions): Response {
    $transaction = $transactions->find((int) $args['id']);

    if ($transaction === null) {
        $response->getBody()->write(json_encode([
            'error' => 'transaction not found',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404);
    }

    $response->getBody()->write(json_encode($transaction, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->patch('/transactions/{id:[0-9]+}/label', function (Request $request, Response $response, array $args) use ($transactions, $labels): Response {
    if ($transactions->find((int) $args['id']) === null) {
        $response->getBody()->write(json_encode([
            'error' => 'transaction not found',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(404);
    }

    $body = $request->getParsedBody();

    if (
        !is_array($body)
        || !isset($body['label'])
        || !is_string($body['label'])
        || !in_array($body['label'], ['legit', 'fraud'], true)
    ) {
        $response->getBody()->write(json_encode([
            'error' => 'label must be legit or fraud',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(422);
    }

    $label = $labels->update(
        (int) $args['id'],
        $body['label'],
        isset($body['labelled_by']) && is_string($body['labelled_by']) ? $body['labelled_by'] : null,
        isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null
    );

    if ($label === null) {
        $response->getBody()->write(json_encode([
            'error' => 'transaction has no extracted features to label',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(409);
    }

    $response->getBody()->write(json_encode($label, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/ml/training-dataset', function (Request $request, Response $response) use ($labels): Response {
    $response->getBody()->write(json_encode($labels->trainingDataset(), JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/users/{id:[0-9]+}/transactions', function (Request $request, Response $response, array $args) use ($database, $transactions): Response {
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

    $history = $transactions->findForUser((int) $args['id']);

    $response->getBody()->write(json_encode($history, JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
