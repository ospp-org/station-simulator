<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class ResponseTimeAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $maxMs = (float) ($params['max_ms'] ?? 5000);

        if ($context->lastSentMessage === null || $context->lastReceivedMessage === null) {
            $this->lastMessage = "No sent/received pair to measure";
            return false;
        }

        $sentTime = $context->lastSentMessage->timestamp->getTimestamp();
        $receivedTime = $context->lastReceivedMessage->timestamp->getTimestamp();
        $durationMs = ($receivedTime - $sentTime) * 1000.0;

        if ($durationMs <= $maxMs) {
            $this->lastMessage = "Response time: {$durationMs}ms (max: {$maxMs}ms)";
            return true;
        }

        $this->lastMessage = "Response time {$durationMs}ms exceeds max {$maxMs}ms";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
