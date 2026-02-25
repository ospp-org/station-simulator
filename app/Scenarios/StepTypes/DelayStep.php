<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

final class DelayStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $ms = (int) ($config['duration_ms'] ?? $config['ms'] ?? $config['delay_ms'] ?? 1000);

        usleep($ms * 1000);
        $this->lastMessage = "Waited {$ms}ms";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
