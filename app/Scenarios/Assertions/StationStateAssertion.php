<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class StationStateAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $stationIndex = (int) ($params['station'] ?? 1);
        $expected = $params['expected'] ?? '';

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";
            return false;
        }

        $actual = $station->state->lifecycle;
        if ($actual === $expected) {
            $this->lastMessage = "Station lifecycle is {$actual}";
            return true;
        }

        $this->lastMessage = "Expected lifecycle {$expected}, got {$actual}";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
