<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Event\TransactionCreated;
use App\Repository\FeatureRepository;
use App\Repository\TransactionRepository;
use RuntimeException;
use Throwable;

final class MonitoringHandler
{
    private const RISK_THRESHOLD = 0.7;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly FeatureExtractor $featureExtractor,
        private readonly FeatureRepository $features,
        private readonly InferenceClient $inference
    ) {
    }

    public function __invoke(TransactionCreated $event): void
    {
        try {
            $transaction = $this->transactions->find($event->transactionId);

            if ($transaction === null) {
                throw new RuntimeException('Transaction not found for monitoring.');
            }

            $features = $this->featureExtractor->extract($transaction);
            $this->features->save($features);
            $riskScore = $this->inference->predict($features);

            $ruleHits = [];

            if ($transaction['amount'] > 500000) {
                $ruleHits[] = 'HIGH_AMOUNT';
            }

            if (in_array($transaction['amount'], [100000, 500000, 1000000], true)) {
                $ruleHits[] = 'ROUND_AMOUNT';
            }

            $isSuspicious = $ruleHits !== [] || $riskScore >= self::RISK_THRESHOLD;

            $this->transactions->saveMonitoringResult(
                $event->transactionId,
                $ruleHits,
                $isSuspicious,
                $riskScore,
                $this->inference->modelVersion()
            );
        } catch (Throwable) {
            $this->transactions->markMonitoringFailed($event->transactionId);
        }
    }
}
