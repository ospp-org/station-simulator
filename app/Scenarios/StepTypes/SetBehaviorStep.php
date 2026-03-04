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
        $station->config->behavior[$action] = array_merge($currentBehavior, $changes);

        $changedKeys = implode(', ', array_keys($changes));
        $this->lastMessage = "Updated behavior for {$action}: {$changedKeys}";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
