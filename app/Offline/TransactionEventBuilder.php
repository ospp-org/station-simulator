<?php

declare(strict_types=1);

namespace App\Offline;

use App\Generators\ReceiptGenerator;
use App\Station\SimulatedStation;

final class TransactionEventBuilder
{
    private int $txCounter = 1;

    public function __construct(
        private readonly ReceiptGenerator $receiptGenerator,
        int $txCounterStart = 1,
    ) {
        $this->txCounter = $txCounterStart;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(SimulatedStation $station, string $passId, array $overrides = []): array
    {
        $stationId = $station->getStationId();
        $txId = sprintf('tx_%s_%d', $stationId, $this->txCounter);
        $bayId = $overrides['bayId'] ?? "bay_{$stationId}_1";
        $serviceId = $overrides['serviceId'] ?? 'wash_basic';
        $startedAt = $overrides['startedAt'] ?? (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d\TH:i:s.v\Z');
        $endedAt = $overrides['endedAt'] ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
        $creditsCharged = $overrides['creditsCharged'] ?? 50;
        $durationSeconds = $overrides['durationSeconds'] ?? 300;

        $receiptData = [
            'offlineTxId' => $txId,
            'offlinePassId' => $passId,
            'stationId' => $stationId,
            'bayId' => $bayId,
            'serviceId' => $serviceId,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'creditsCharged' => $creditsCharged,
        ];

        $signedReceipt = $this->receiptGenerator->signReceipt($receiptData);

        $event = [
            'offlineTxId' => $txId,
            'offlinePassId' => $passId,
            'userId' => $overrides['userId'] ?? 'unknown',
            'bayId' => $bayId,
            'serviceId' => $serviceId,
            'txCounter' => $this->txCounter,
            'durationSeconds' => $durationSeconds,
            'creditsCharged' => $creditsCharged,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'receipt' => $signedReceipt,
            'meterValues' => $overrides['meterValues'] ?? [
                'liquidMl' => 2500,
                'consumableMl' => 150,
                'energyWh' => 85,
            ],
        ];

        $this->txCounter++;

        return $event;
    }

    public function getTxCounter(): int
    {
        return $this->txCounter;
    }
}
