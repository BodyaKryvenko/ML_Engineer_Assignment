<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Monitoring\FeatureExtractor;
use PHPUnit\Framework\TestCase;

final class FeatureExtractorTest extends TestCase
{
    public function testItBuildsFeaturesFromOnlyEarlierTransactions(): void
    {
        $database = Connection::create(':memory:');
        $migration = file_get_contents(dirname(__DIR__) . '/src/Database/migrations.sql');
        self::assertNotFalse($migration);
        $database->exec($migration);
        $database->exec(
            "INSERT INTO users (username, balance, created_at)
             VALUES ('alice', 100000, '2026-05-25T10:00:00+00:00'),
                    ('bob', 0, '2026-05-25T10:00:00+00:00')"
        );

        $insert = $database->prepare(
            'INSERT INTO transactions
                (sender_id, receiver_id, amount, currency, status, sender_balance_before,
                 sender_balance_after, receiver_balance_before, receiver_balance_after, created_at)
             VALUES
                (1, 2, :amount, :currency, :status, :sender_before, :sender_after,
                 :receiver_before, :receiver_after, :created_at)'
        );
        $insert->execute([
            'amount' => 1000,
            'currency' => 'EUR',
            'status' => 'completed',
            'sender_before' => 100000,
            'sender_after' => 99000,
            'receiver_before' => 0,
            'receiver_after' => 1000,
            'created_at' => '2026-05-25T11:30:00+00:00',
        ]);
        $insert->execute([
            'amount' => 2000,
            'currency' => 'EUR',
            'status' => 'completed',
            'sender_before' => 99000,
            'sender_after' => 97000,
            'receiver_before' => 1000,
            'receiver_after' => 3000,
            'created_at' => '2026-05-25T12:00:00+00:00',
        ]);

        $currentTransaction = [
            'id' => 2,
            'sender_id' => 1,
            'receiver_id' => 2,
            'amount' => 2000,
            'sender_balance_before' => 99000,
            'sender_balance_after' => 97000,
            'receiver_balance_before' => 1000,
            'created_at' => '2026-05-25T12:00:00+00:00',
        ];

        $features = (new FeatureExtractor($database))->extract($currentTransaction);

        self::assertSame(2000, $features['amount']);
        self::assertSame(12, $features['hour_of_day']);
        self::assertSame(1, $features['sender_tx_count_last_hour']);
        self::assertSame(1000, $features['sender_tx_sum_last_24h']);
        self::assertSame(0, $features['is_new_receiver']);
    }

    public function testFirstPaymentToRecipientIsMarkedAsNew(): void
    {
        $database = Connection::create(':memory:');
        $migration = file_get_contents(dirname(__DIR__) . '/src/Database/migrations.sql');
        self::assertNotFalse($migration);
        $database->exec($migration);

        $features = (new FeatureExtractor($database))->extract([
            'id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'amount' => 500,
            'sender_balance_before' => 1000,
            'sender_balance_after' => 500,
            'receiver_balance_before' => 0,
            'created_at' => '2026-05-25T12:00:00+00:00',
        ]);

        self::assertSame(0, $features['sender_tx_count_last_hour']);
        self::assertSame(0, $features['sender_tx_sum_last_24h']);
        self::assertSame(1, $features['is_new_receiver']);
    }
}

