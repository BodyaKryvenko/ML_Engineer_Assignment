<?php

declare(strict_types=1);

namespace App\Monitoring;

use PDO;

final class FeatureExtractor
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function extract(array $transaction): array
    {
        $createdAt = strtotime($transaction['created_at']);

        $statement = $this->database->prepare(
            'SELECT COUNT(*)
             FROM transactions
             WHERE sender_id = :sender_id
               AND id < :transaction_id
               AND status = :status
               AND created_at >= :since'
        );
        $statement->execute([
            'sender_id' => $transaction['sender_id'],
            'transaction_id' => $transaction['id'],
            'status' => 'completed',
            'since' => gmdate('c', $createdAt - 3600),
        ]);
        $lastHourCount = (int) $statement->fetchColumn();

        $statement = $this->database->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM transactions
             WHERE sender_id = :sender_id
               AND id < :transaction_id
               AND status = :status
               AND created_at >= :since'
        );
        $statement->execute([
            'sender_id' => $transaction['sender_id'],
            'transaction_id' => $transaction['id'],
            'status' => 'completed',
            'since' => gmdate('c', $createdAt - 86400),
        ]);
        $lastDayAmount = (int) $statement->fetchColumn();

        $statement = $this->database->prepare(
            'SELECT COUNT(*)
             FROM transactions
             WHERE sender_id = :sender_id
               AND receiver_id = :receiver_id
               AND id < :transaction_id
               AND status = :status'
        );
        $statement->execute([
            'sender_id' => $transaction['sender_id'],
            'receiver_id' => $transaction['receiver_id'],
            'transaction_id' => $transaction['id'],
            'status' => 'completed',
        ]);
        $previousRecipientTransfers = (int) $statement->fetchColumn();

        return [
            'transaction_id' => $transaction['id'],
            'amount' => $transaction['amount'],
            'hour_of_day' => (int) gmdate('G', $createdAt),
            'sender_balance_before' => $transaction['sender_balance_before'],
            'sender_balance_after' => $transaction['sender_balance_after'],
            'receiver_balance_before' => $transaction['receiver_balance_before'],
            'sender_tx_count_last_hour' => $lastHourCount,
            'sender_tx_sum_last_24h' => $lastDayAmount,
            'is_new_receiver' => $previousRecipientTransfers === 0 ? 1 : 0,
            'created_at' => gmdate('c'),
        ];
    }
}

