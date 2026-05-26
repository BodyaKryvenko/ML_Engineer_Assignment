<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class LabelRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function createUnknown(int $transactionId): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO transaction_labels (transaction_id, label, created_at)
             VALUES (:transaction_id, :label, :created_at)'
        );
        $statement->execute([
            'transaction_id' => $transactionId,
            'label' => 'unknown',
            'created_at' => gmdate('c'),
        ]);
    }

    public function update(int $transactionId, string $label, ?string $labelledBy, ?string $reason): ?array
    {
        $statement = $this->database->prepare(
            'UPDATE transaction_labels
             SET label = :label, labelled_by = :labelled_by, label_reason = :label_reason, created_at = :created_at
             WHERE transaction_id = :transaction_id'
        );
        $statement->execute([
            'label' => $label,
            'labelled_by' => $labelledBy,
            'label_reason' => $reason,
            'created_at' => gmdate('c'),
            'transaction_id' => $transactionId,
        ]);

        if ($statement->rowCount() === 0) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT transaction_id, label, labelled_by, label_reason, created_at
             FROM transaction_labels WHERE transaction_id = :transaction_id'
        );
        $statement->execute(['transaction_id' => $transactionId]);

        return $statement->fetch() ?: null;
    }

    public function trainingDataset(): array
    {
        $statement = $this->database->query(
            "SELECT f.transaction_id, f.amount, f.hour_of_day, f.sender_balance_before,
                    f.sender_balance_after, f.receiver_balance_before, f.sender_tx_count_last_hour,
                    f.sender_tx_sum_last_24h, f.is_new_receiver, l.label
             FROM transaction_features f
             JOIN transaction_labels l ON l.transaction_id = f.transaction_id
             WHERE l.label IN ('legit', 'fraud')
             ORDER BY f.transaction_id"
        );

        return $statement->fetchAll();
    }
}

