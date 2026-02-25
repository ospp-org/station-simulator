<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;
use Ospp\Protocol\Actions\OsppAction;

final class MeterValuesMonotonicAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $meterMessages = array_filter(
            $context->receivedMessages,
            fn ($e) => $e->action === OsppAction::METER_VALUES,
        );

        if (count($meterMessages) < 2) {
            $this->lastMessage = "Not enough MeterValues messages to verify monotonicity (found " . count($meterMessages) . ")";
            return count($meterMessages) <= 1; // 0 or 1 is trivially monotonic
        }

        $meterMessages = array_values($meterMessages);
        $previousWater = 0.0;
        $previousEnergy = 0.0;

        foreach ($meterMessages as $msg) {
            $values = $msg->payload['meterValues'] ?? [];
            $water = (float) ($values['waterConsumptionMl'] ?? 0);
            $energy = (float) ($values['energyConsumptionWh'] ?? 0);

            if ($water < $previousWater || $energy < $previousEnergy) {
                $this->lastMessage = "Meter values not monotonic: water {$previousWater}→{$water}, energy {$previousEnergy}→{$energy}";
                return false;
            }

            $previousWater = $water;
            $previousEnergy = $energy;
        }

        $this->lastMessage = "All " . count($meterMessages) . " MeterValues are monotonically increasing";
        return true;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
