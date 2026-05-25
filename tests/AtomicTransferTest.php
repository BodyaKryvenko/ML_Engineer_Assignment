<?php

declare(strict_types=1);

use App\Database\Connection;
use PHPUnit\Framework\TestCase;

final class AtomicTransferTest extends TestCase
{
    public function testBalancesAreRolledBackWhenTransactionInsertFails(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'transactions-');
        self::assertNotFalse($databasePath);

        $database = Connection::create($databasePath);
        $migration = file_get_contents(dirname(__DIR__) . '/src/Database/migrations.sql');
        self::assertNotFalse($migration);
        $database->exec($migration);
        $database->exec(
            "INSERT INTO users (username, balance, created_at)
             VALUES ('alice', 1000, 'now'), ('bob', 100, 'now')"
        );
        $database->exec(
            "CREATE TRIGGER reject_transaction
             BEFORE INSERT ON transactions
             BEGIN
                 SELECT RAISE(ABORT, 'forced insert failure');
             END"
        );

        $port = random_int(10000, 50000);
        $environment = array_merge($_ENV, ['DATABASE_PATH' => $databasePath]);
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
            $environment
        );

        self::assertIsResource($server);

        try {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $socket = @fsockopen('127.0.0.1', $port);

                if (is_resource($socket)) {
                    fclose($socket);
                    break;
                }

                usleep(100000);
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode([
                        'sender_id' => 1,
                        'receiver_id' => 2,
                        'amount' => 500,
                        'currency' => 'EUR',
                    ], JSON_THROW_ON_ERROR),
                    'ignore_errors' => true,
                ],
            ]);

            file_get_contents('http://127.0.0.1:' . $port . '/transactions', false, $context);

            self::assertStringContainsString('500 Internal Server Error', $http_response_header[0]);
            self::assertSame(
                [1000, 100],
                array_map('intval', $database->query('SELECT balance FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
            );
            self::assertSame(
                0,
                (int) $database->query('SELECT COUNT(*) FROM transactions')->fetchColumn()
            );
        } finally {
            proc_terminate($server);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($server);
            $database = null;
            unlink($databasePath);
        }
    }
}

