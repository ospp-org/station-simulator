<?php

declare(strict_types=1);

namespace App\Offline;

use App\Generators\ReceiptGenerator;
use App\Station\SimulatedStation;

final class OfflinePassBuilder
{
    private int $passCounter = 0;

    public function __construct(
        private readonly ReceiptGenerator $receiptGenerator,
    ) {}

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(SimulatedStation $station, array $overrides = []): array
    {
        $this->passCounter++;
        $stationId = $station->getStationId();

        $passData = array_merge([
            'passId' => sprintf('pass_%s_%d', $stationId, $this->passCounter),
            'sub' => $overrides['userId'] ?? 'offline-user-001',
            'deviceId' => $stationId,
            'issuedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'expiresAt' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d\TH:i:s.v\Z'),
            'offlineAllowance' => [
                'maxCredits' => $overrides['maxCredits'] ?? 500,
                'maxUses' => $overrides['maxUses'] ?? 10,
            ],
            'constraints' => [
                'allowedServices' => $overrides['allowedServices'] ?? ['wash_basic', 'vacuum', 'air'],
                'maxSingleTransaction' => $overrides['maxSingleTransaction'] ?? 200,
            ],
        ], $overrides);

        // Remove override-only fields
        unset($passData['userId'], $passData['maxCredits'], $passData['maxUses'],
            $passData['allowedServices'], $passData['maxSingleTransaction']);

        return $this->receiptGenerator->signReceipt($passData);
    }

    public function getPassCount(): int
    {
        return $this->passCounter;
    }
}
