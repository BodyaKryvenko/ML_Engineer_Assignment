<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TransactionRepository
{
    private const COLUMNS = 'id, sender_id, receiver_id, amount, currency, status,
        sender_balance_before, sender_balance_after, receiver_balance_before, receiver_balance_after,
        is_suspicious, rule_hits, created_at';

    public function __construct(private readonly PDO $database)
    {
    }

    public function create(array $transaction): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO transactions
                (sender_id, receiver_id, amount, currency, status, sender_balance_before, sender_balance_after,
                 receiver_balance_before, receiver_balance_after, is_suspicious, rule_hits, idempotency_key,
                 request_hash, created_at)
             VALUES
                (:sender_id, :receiver_id, :amount, :currency, :status, :sender_balance_before, :sender_balance_after,
                 :receiver_balance_before, :receiver_balance_after, :is_suspicious, :rule_hits, :idempotency_key,
                 :request_hash, :created_at)'
        );
        $statement->execute($transaction);

        return (int) $this->database->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT ' . self::COLUMNS . ' FROM transactions WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $transaction = $statement->fetch();

        return $transaction === false ? null : $this->formatTransaction($transaction);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $statement = $this->database->prepare(
            'SELECT ' . self::COLUMNS . ', request_hash
             FROM transactions WHERE idempotency_key = :idempotency_key'
        );
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $transaction = $statement->fetch();

        return $transaction === false ? null : $this->formatTransaction($transaction);
    }

    public function findForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM transactions
             WHERE sender_id = :id OR receiver_id = :id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['id' => $userId]);

        $transactions = [];

        foreach ($statement->fetchAll() as $transaction) {
            $transactions[] = $this->formatTransaction($transaction);
        }

        return $transactions;
    }

    private function formatTransaction(array $transaction): array
    {
        $transaction['is_suspicious'] = (bool) $transaction['is_suspicious'];
        $transaction['rule_hits'] = json_decode($transaction['rule_hits'], true, 512, JSON_THROW_ON_ERROR);

        return $transaction;
    }
}
