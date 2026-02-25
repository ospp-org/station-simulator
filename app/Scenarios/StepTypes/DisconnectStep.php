<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;
use App\Station\StationState;

final class DisconnectStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $stationRef = $config['station'] ?? 1;
        $reconnectDelayMs = $config['reconnect_delay_ms'] ?? null;

        // Support both integer index and string station ID
        if (is_numeric($stationRef)) {
            $station = $context->getStation((int) $stationRef);
        } else {
            $station = $context->stations[(string) $stationRef] ?? $context->getStation(1);
        }

        if ($station === null) {
            $this->lastMessage = "Station {$stationRef} not found";

            return false;
        }

        // Mark station as offline
        $station->state->setLifecycle(StationState::LIFECYCLE_OFFLINE);

        $this->lastMessage = "Disconnected station {$station->getStationId()}";
        if ($reconnectDelayMs !== null) {
            $this->lastMessage .= " (reconnect in {$reconnectDelayMs}ms)";
        }

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
