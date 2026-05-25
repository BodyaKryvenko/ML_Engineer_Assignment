<?php

declare(strict_types=1);

namespace App\Monitoring;

interface InferenceClient
{
    public function predict(array $features): float;

    public function modelVersion(): string;
}

