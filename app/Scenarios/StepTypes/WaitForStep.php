<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

final class WaitForStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $action = $config['action'] ?? '';
        $messageType = $config['message_type'] ?? null;
        $timeoutMs = (int) ($config['timeout_ms'] ?? 5000);

        $startTime = microtime(true);
        $initialCount = count($context->receivedMessages);

        // Poll for matching message
        while ((microtime(true) - $startTime) * 1000 < $timeoutMs) {
            foreach (array_slice($context->receivedMessages, $initialCount) as $envelope) {
                if ($envelope->action === $action) {
                    if ($messageType === null || $envelope->messageType->value === $messageType) {
                        $this->lastMessage = "Received {$action} ({$envelope->messageType->value})";

                        return true;
                    }
                }
            }

            usleep(10000); // 10ms polling
        }

        $this->lastMessage = "Timeout waiting for {$action}" . ($messageType ? " ({$messageType})" : '');

        return false;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
