<?php

declare(strict_types=1);

namespace App\Monitoring;

use RuntimeException;

final class MockInferenceClient implements InferenceClient
{
    private array $model;

    public function __construct(string $modelPath)
    {
        $json = file_get_contents($modelPath);

        if ($json === false) {
            throw new RuntimeException('Could not read the model file.');
        }

        $this->model = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function predict(array $features): float
    {
        $weights = $this->model['weights'];
        $linearScore = $this->model['intercept']
            + $weights['amount'] * $features['amount']
            + $weights['sender_tx_count_last_hour'] * $features['sender_tx_count_last_hour']
            + $weights['sender_tx_sum_last_24h'] * $features['sender_tx_sum_last_24h']
            + $weights['is_new_receiver'] * $features['is_new_receiver']
            + $weights['night_transaction'] * ($features['hour_of_day'] < 5 ? 1 : 0);

        return round(1 / (1 + exp(-$linearScore)), 4);
    }

    public function modelVersion(): string
    {
        return $this->model['model_version'];
    }
}

