<?php

declare(strict_types=1);

namespace App\Event;

use App\Monitoring\MonitoringHandler;

final class SyncEventDispatcher
{
    public function __construct(private readonly MonitoringHandler $monitoringHandler)
    {
    }

    public function dispatch(TransactionCreated $event): void
    {
        ($this->monitoringHandler)($event);
    }
}

