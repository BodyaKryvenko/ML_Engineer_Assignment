<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Event\TransactionCreated;
use App\Monitoring\FeatureExtractor;
use App\Monitoring\InferenceClient;
use App\Monitoring\MonitoringHandler;
use App\Repository\FeatureRepository;
use App\Repository\LabelRepository;
use App\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;

final class MonitoringHandlerTest extends TestCase
{
    private \PDO $database;
    private TransactionRepository $transactions;
    private FeatureRepository $features;
    private LabelRepository $labels;

    protected function setUp(): void
    {
        $this->database = Connection::create(':memory:');
        $migration = file_get_contents(dirname(__DIR__) . '/src/Database/migrations.sql');
        self::assertNotFalse($migration);
        $this->database->exec($migration);
        $this->database->exec(
            "INSERT INTO users (username, balance, created_at)
             VALUES ('alice', 2000000, '2026-05-26T10:00:00+00:00'),
                    ('bob', 0, '2026-05-26T10:00:00+00:00')"
        );

        $this->transactions = new TransactionRepository($this->database);
        $this->features = new FeatureRepository($this->database);
        $this->labels = new LabelRepository($this->database);
    }

    public function testItCompletesMonitoringAndCreatesUnknownLabel(): void
    {
        $transactionId = $this->createTransaction(1000);
        $handler = $this->createHandler(new class implements InferenceClient {
            public function predict(array $features): float
            {
                return 0.2;
            }

            public function modelVersion(): string
            {
                return 'test-model';
            }
        });

        $handler(new TransactionCreated($transactionId));

        $transaction = $this->transactions->find($transactionId);
        self::assertNotNull($transaction);
        self::assertSame('completed', $transaction['monitoring_status']);
        self::assertNull($transaction['monitoring_error']);
        self::assertFalse($transaction['is_suspicious']);
        self::assertSame(0.2, $transaction['risk_score']);
        self::assertSame('test-model', $transaction['model_version']);
        self::assertSame([], $transaction['rule_hits']);

        $featureCount = $this->database->query('SELECT COUNT(*) FROM transaction_features')->fetchColumn();
        self::assertSame(1, (int) $featureCount);

        $label = $this->database->query('SELECT label FROM transaction_labels')->fetchColumn();
        self::assertSame('unknown', $label);
    }

    public function testHighModelScoreFlagsTransactionWithoutRuleHit(): void
    {
        $transactionId = $this->createTransaction(400001);
        $handler = $this->createHandler(new class implements InferenceClient {
            public function predict(array $features): float
            {
                return 0.71;
            }

            public function modelVersion(): string
            {
                return 'test-model';
            }
        });

        $handler(new TransactionCreated($transactionId));

        $transaction = $this->transactions->find($transactionId);
        self::assertNotNull($transaction);
        self::assertSame([], $transaction['rule_hits']);
        self::assertSame(0.71, $transaction['risk_score']);
        self::assertTrue($transaction['is_suspicious']);
    }

    public function testInferenceFailureDoesNotUndoCompletedTransaction(): void
    {
        $transactionId = $this->createTransaction(1000);
        $handler = $this->createHandler(new class implements InferenceClient {
            public function predict(array $features): float
            {
                throw new \RuntimeException('model unavailable');
            }

            public function modelVersion(): string
            {
                return 'test-model';
            }
        });

        $handler(new TransactionCreated($transactionId));

        $transaction = $this->transactions->find($transactionId);
        self::assertNotNull($transaction);
        self::assertSame('completed', $transaction['status']);
        self::assertSame('failed', $transaction['monitoring_status']);
        self::assertSame('monitoring failed', $transaction['monitoring_error']);
        self::assertNull($transaction['is_suspicious']);
        self::assertNull($transaction['risk_score']);
        self::assertNull($transaction['model_version']);
        self::assertNull($transaction['rule_hits']);

        $featureCount = $this->database->query('SELECT COUNT(*) FROM transaction_features')->fetchColumn();
        self::assertSame(1, (int) $featureCount);

        $label = $this->database->query('SELECT label FROM transaction_labels')->fetchColumn();
        self::assertSame('unknown', $label);
    }

    public function testOnlyConfirmedLabelsAreIncludedInTrainingDataset(): void
    {
        $transactionId = $this->createTransaction(1000);
        $handler = $this->createHandler(new class implements InferenceClient {
            public function predict(array $features): float
            {
                return 0.2;
            }

            public function modelVersion(): string
            {
                return 'test-model';
            }
        });
        $handler(new TransactionCreated($transactionId));

        self::assertSame([], $this->labels->trainingDataset());

        $label = $this->labels->update($transactionId, 'fraud', 'reviewer', 'confirmed');
        self::assertNotNull($label);

        $dataset = $this->labels->trainingDataset();
        self::assertCount(1, $dataset);
        self::assertSame($transactionId, $dataset[0]['transaction_id']);
        self::assertSame(1000, $dataset[0]['amount']);
        self::assertSame('fraud', $dataset[0]['label']);
    }

    private function createTransaction(int $amount): int
    {
        return $this->transactions->create([
            'sender_id' => 1,
            'receiver_id' => 2,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => 'completed',
            'sender_balance_before' => 2000000,
            'sender_balance_after' => 2000000 - $amount,
            'receiver_balance_before' => 0,
            'receiver_balance_after' => $amount,
            'idempotency_key' => null,
            'request_hash' => null,
            'created_at' => '2026-05-26T12:00:00+00:00',
        ]);
    }

    private function createHandler(InferenceClient $inference): MonitoringHandler
    {
        return new MonitoringHandler(
            $this->transactions,
            new FeatureExtractor($this->database),
            $this->features,
            $this->labels,
            $inference
        );
    }
}
