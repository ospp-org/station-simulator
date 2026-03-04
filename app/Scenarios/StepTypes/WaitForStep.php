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
        $searchFrom = $context->waitForCursor;

        // Poll for matching message
        while ((microtime(true) - $startTime) * 1000 < $timeoutMs) {
            // Pump MQTT to receive incoming messages
            $context->mqtt->pollOnce();

            // Process event loop timers (handlers use DelaySimulator which schedules timers)
            if ($context->loop !== null) {
                $context->loop->addTimer(0, fn () => $context->loop->stop());
                $context->loop->run();
            }

            $messages = $context->receivedMessages;
            for ($i = $searchFrom, $len = count($messages); $i < $len; $i++) {
                $envelope = $messages[$i];
                if ($envelope->action === $action) {
                    if ($messageType === null || strcasecmp($envelope->messageType->value, $messageType) === 0) {
                        $context->lastReceivedMessage = $envelope;
                        $context->waitForCursor = $i + 1;
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
