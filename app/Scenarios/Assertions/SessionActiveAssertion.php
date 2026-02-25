<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class SessionActiveAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $stationIndex = (int) ($params['station'] ?? 1);
        $expectActive = (bool) ($params['expected'] ?? $params['active'] ?? true);

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";
            return false;
        }

        // Support both bay_id (full ID) and bay_number (integer)
        $bay = null;
        $bayRef = '';
        if (isset($params['bay_id'])) {
            $bayRef = $params['bay_id'];
            $bay = $station->getBay($bayRef);
        } elseif (isset($params['bay_number'])) {
            $bayNumber = (int) $params['bay_number'];
            $bayRef = "bay #{$bayNumber}";
            foreach ($station->getBays() as $b) {
                if ($b->bayNumber === $bayNumber) {
                    $bay = $b;
                    break;
                }
            }
        }

        if ($bay === null) {
            $this->lastMessage = "Bay {$bayRef} not found";
            return false;
        }

        $hasSession = $bay->currentSessionId !== null;
        if ($hasSession === $expectActive) {
            $this->lastMessage = $hasSession ? "Session active on {$bayRef}" : "No session on {$bayRef}";
            return true;
        }

        $this->lastMessage = $expectActive
            ? "Expected active session on {$bayRef}, none found"
            : "Expected no session on {$bayRef}, found {$bay->currentSessionId}";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
