<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

final class SetBehaviorStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $stationIndex = (int) ($config['station'] ?? 1);
        $action = $config['action'] ?? '';
        $changes = $config['config'] ?? $config['changes'] ?? [];

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";

            return false;
        }

        // Modify behavior config at runtime
        $currentBehavior = $station->config->behavior[$action] ?? [];
        $mergedBehavior = array_merge($currentBehavior, $changes);

        // StationConfig->behavior is readonly, so we need to update via reflection
        $reflection = new \ReflectionProperty($station->config, 'behavior');
        $behavior = $station->config->behavior;
        $behavior[$action] = $mergedBehavior;
        $reflection->setValue($station->config, $behavior);

        $changedKeys = implode(', ', array_keys($changes));
        $this->lastMessage = "Updated behavior for {$action}: {$changedKeys}";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
