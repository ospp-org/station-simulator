<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class PayloadFieldAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $field = $params['field'] ?? '';
        $expected = $params['value'] ?? null;

        $lastReceived = $context->lastReceivedMessage;
        if ($lastReceived === null) {
            $this->lastMessage = "No message received";
            return false;
        }

        // Support dot notation: "meterValues.waterConsumptionMl"
        $actual = $this->getNestedValue($lastReceived->payload, $field);

        if ($actual === $expected) {
            $this->lastMessage = "Payload field {$field} = " . json_encode($actual);
            return true;
        }

        $this->lastMessage = "Expected {$field} = " . json_encode($expected) . ", got " . json_encode($actual);
        return false;
    }

    private function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
