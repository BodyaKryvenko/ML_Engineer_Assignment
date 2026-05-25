<?php

declare(strict_types=1);

namespace App\Event;

final readonly class TransactionCreated
{
    public function __construct(public int $transactionId)
    {
    }
}

