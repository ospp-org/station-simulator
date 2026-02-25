<?php

declare(strict_types=1);

namespace App\Station;

use Ospp\Protocol\Enums\BayStatus;

final class SimulatedStation
{
    public readonly StationIdentity $identity;
    public readonly StationConfig $config;
    public readonly StationState $state;

    /** @var array<string, BayState> */
    private array $bays = [];

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function __construct(
        StationIdentity $identity,
        StationConfig $config,
    ) {
        $this->identity = $identity;
        $this->config = $config;
        $this->state = new StationState($config);

        $this->initializeBays();
    }

    public static function create(StationConfig $config, int $index): self
    {
        $identity = StationIdentity::fromConfig($config, $index);

        return new self($identity, $config);
    }

    public function getStationId(): string
    {
        return $this->identity->stationId;
    }

    public function getBay(string $bayId): ?BayState
    {
        return $this->bays[$bayId] ?? null;
    }

    public function getBayByNumber(int $bayNumber): ?BayState
    {
        foreach ($this->bays as $bay) {
            if ($bay->bayNumber === $bayNumber) {
                return $bay;
            }
        }

        return null;
    }

    /** @return array<string, BayState> */
    public function getBays(): array
    {
        return $this->bays;
    }

    public function findBayByReservation(string $reservationId): ?BayState
    {
        foreach ($this->bays as $bay) {
            if ($bay->currentReservationId === $reservationId) {
                return $bay;
            }
        }

        return null;
    }

    public function findBayBySession(string $sessionId): ?BayState
    {
        foreach ($this->bays as $bay) {
            if ($bay->currentSessionId === $sessionId) {
                return $bay;
            }
        }

        return null;
    }

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** @param array<string, mixed> $data */
    public function emit(string $event, array $data = []): void
    {
        $data['stationId'] = $this->identity->stationId;

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }
    }

    public function updateIdentity(StationIdentity $identity): void
    {
        // We need to use reflection since identity is readonly but we need to update firmware version
        $reflection = new \ReflectionProperty($this, 'identity');
        $reflection->setValue($this, $identity);
    }

    private function initializeBays(): void
    {
        $bayCount = $this->config->getBayCount();

        for ($i = 1; $i <= $bayCount; $i++) {
            $bay = BayState::create($this->identity->stationId, $i);
            $bay->status = BayStatus::UNKNOWN;
            $bay->services = $this->config->services;
            $this->bays[$bay->bayId] = $bay;
        }
    }
}
