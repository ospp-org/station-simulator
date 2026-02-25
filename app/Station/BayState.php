<?php

declare(strict_types=1);

namespace App\Station;

use DateTimeImmutable;
use OneStopPay\OsppProtocol\Enums\BayStatus;

final class BayState
{
    public BayStatus $status = BayStatus::UNKNOWN;
    public ?BayStatus $previousStatus = null;
    public ?string $currentSessionId = null;
    public ?string $currentReservationId = null;
    public ?string $currentServiceId = null;
    public ?int $errorCode = null;
    public ?string $errorText = null;
    public ?DateTimeImmutable $sessionStartTime = null;

    /** @var array<string, float> */
    public array $meterAccumulator = [];

    /** @var list<array<string, mixed>> */
    public array $services = [];

    public function __construct(
        public readonly string $bayId,
        public readonly int $bayNumber,
    ) {}

    public static function create(string $stationId, int $bayNumber): self
    {
        return new self(
            bayId: "bay_{$stationId}_{$bayNumber}",
            bayNumber: $bayNumber,
        );
    }

    public function transitionTo(BayStatus $newStatus): void
    {
        $this->previousStatus = $this->status;
        $this->status = $newStatus;
    }

    public function startSession(string $sessionId, string $serviceId): void
    {
        $this->currentSessionId = $sessionId;
        $this->currentServiceId = $serviceId;
        $this->sessionStartTime = new DateTimeImmutable();
        $this->meterAccumulator = [
            'water_ml' => 0.0,
            'chemical_ml' => 0.0,
            'energy_wh' => 0.0,
        ];
    }

    public function endSession(): void
    {
        $this->currentSessionId = null;
        $this->currentServiceId = null;
        $this->sessionStartTime = null;
        $this->meterAccumulator = [];
    }

    public function setReservation(string $reservationId): void
    {
        $this->currentReservationId = $reservationId;
    }

    public function clearReservation(): void
    {
        $this->currentReservationId = null;
    }

    public function setFault(int $errorCode, string $errorText): void
    {
        $this->errorCode = $errorCode;
        $this->errorText = $errorText;
    }

    public function clearFault(): void
    {
        $this->errorCode = null;
        $this->errorText = null;
    }

    public function getSessionDurationSeconds(): int
    {
        if ($this->sessionStartTime === null) {
            return 0;
        }

        return (new DateTimeImmutable())->getTimestamp() - $this->sessionStartTime->getTimestamp();
    }
}
