<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class MessageCountAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $action = $params['action'] ?? '';
        $expected = (int) ($params['count'] ?? $params['min'] ?? 0);
        $operator = $params['operator'] ?? 'gte'; // gte|eq|lte

        $count = 0;
        foreach ($context->receivedMessages as $envelope) {
            if ($envelope->action === $action) {
                $count++;
            }
        }

        $result = match ($operator) {
            'eq' => $count === $expected,
            'gte' => $count >= $expected,
            'lte' => $count <= $expected,
            'gt' => $count > $expected,
            'lt' => $count < $expected,
            default => $count >= $expected,
        };

        $this->lastMessage = "{$action} count: {$count} (expected {$operator} {$expected})";
        return $result;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
