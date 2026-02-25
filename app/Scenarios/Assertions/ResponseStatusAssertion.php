<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class ResponseStatusAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $expected = $params['expected'] ?? 'Accepted';

        $lastReceived = $context->lastReceivedMessage;
        if ($lastReceived === null) {
            $this->lastMessage = "No response received";
            return false;
        }

        $actual = $lastReceived->payload['status'] ?? 'unknown';
        if ($actual === $expected) {
            $this->lastMessage = "Response status is {$actual}";
            return true;
        }

        $this->lastMessage = "Expected response status {$expected}, got {$actual}";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
