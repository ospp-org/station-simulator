<?php

declare(strict_types=1);

namespace App\Generators;

use App\Station\BayState;
use App\Station\StationConfig;

final class MeterValueGenerator
{
    /**
     * Generate one tick of meter values and accumulate into bay state.
     *
     * @return array<string, float> The accumulated meter values
     */
    public function tick(BayState $bay, StationConfig $config): array
    {
        if ($bay->currentServiceId === null) {
            return $bay->meterAccumulator;
        }

        $profile = $config->getMeterProfile($bay->currentServiceId);
        if ($profile === null) {
            return $bay->meterAccumulator;
        }

        $jitterPercent = $config->getMeterJitterPercent();
        $intervalSeconds = $config->getMeterIntervalSeconds();

        // Generate consumption for this interval with jitter
        $waterRate = $this->randomInRange($profile['water_ml_per_s'] ?? [0, 0]);
        $chemicalRate = $this->randomInRange($profile['chemical_ml_per_s'] ?? [0, 0]);
        $energyRate = $this->randomInRange($profile['energy_wh_per_s'] ?? [0, 0]);

        // Apply jitter
        $waterRate = $this->applyJitter($waterRate, $jitterPercent);
        $chemicalRate = $this->applyJitter($chemicalRate, $jitterPercent);
        $energyRate = $this->applyJitter($energyRate, $jitterPercent);

        // Accumulate (cumulative, monotonic)
        $bay->meterAccumulator['water_ml'] = ($bay->meterAccumulator['water_ml'] ?? 0.0) + ($waterRate * $intervalSeconds);
        $bay->meterAccumulator['chemical_ml'] = ($bay->meterAccumulator['chemical_ml'] ?? 0.0) + ($chemicalRate * $intervalSeconds);
        $bay->meterAccumulator['energy_wh'] = ($bay->meterAccumulator['energy_wh'] ?? 0.0) + ($energyRate * $intervalSeconds);

        return $bay->meterAccumulator;
    }

    /**
     * Build the meter values payload for a message.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(BayState $bay, string $stationId): array
    {
        return [
            'bayId' => $bay->bayId,
            'sessionId' => $bay->currentSessionId,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'values' => [
                'waterMl' => (int) ($bay->meterAccumulator['water_ml'] ?? 0.0),
                'chemicalMl' => (int) ($bay->meterAccumulator['chemical_ml'] ?? 0.0),
                'energyWh' => (int) ($bay->meterAccumulator['energy_wh'] ?? 0.0),
            ],
        ];
    }

    /**
     * @param array{int|float, int|float} $range [min, max]
     */
    private function randomInRange(array $range): float
    {
        $min = (float) $range[0];
        $max = (float) $range[1];

        if ($min >= $max) {
            return $min;
        }

        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function applyJitter(float $value, int $jitterPercent): float
    {
        if ($value <= 0.0 || $jitterPercent <= 0) {
            return max(0.0, $value);
        }

        $jitterFactor = 1.0 + ((mt_rand(-$jitterPercent * 100, $jitterPercent * 100) / 10000));

        return max(0.0, $value * $jitterFactor);
    }
}
