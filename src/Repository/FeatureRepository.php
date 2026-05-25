<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class FeatureRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function save(array $features): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO transaction_features
                (transaction_id, amount, hour_of_day, sender_balance_before, sender_balance_after,
                 receiver_balance_before, sender_tx_count_last_hour, sender_tx_sum_last_24h,
                 is_new_receiver, created_at)
             VALUES
                (:transaction_id, :amount, :hour_of_day, :sender_balance_before, :sender_balance_after,
                 :receiver_balance_before, :sender_tx_count_last_hour, :sender_tx_sum_last_24h,
                 :is_new_receiver, :created_at)'
        );
        $statement->execute($features);
    }
}

