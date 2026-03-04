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

        // Poll MQTT and tick event loop during delay to process messages and timers
        $endTime = microtime(true) + ($ms / 1000);
        while (microtime(true) < $endTime) {
            $context->mqtt->pollOnce();

            // Tick event loop to fire pending timers (handlers use DelaySimulator)
            if ($context->loop !== null) {
                $context->loop->addTimer(0, fn () => $context->loop->stop());
                $context->loop->run();
            }

            usleep(10000); // 10ms intervals
        }

        $this->lastMessage = "Waited {$ms}ms";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
