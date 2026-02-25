<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class BayStatusAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $stationIndex = (int) ($params['station'] ?? 1);
        $expectedStatus = $params['expected'] ?? '';

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

        // Compare using both raw value and PascalCase
        $actual = $bay->status->toOspp();
        $actualLower = $bay->status->value;
        if ($actual === $expectedStatus || $actualLower === $expectedStatus) {
            $this->lastMessage = "Bay {$bayRef} status is {$actual}";
            return true;
        }

        $this->lastMessage = "Expected bay {$bayRef} status {$expectedStatus}, got {$actual}";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
