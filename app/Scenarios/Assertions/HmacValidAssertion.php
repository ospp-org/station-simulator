<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class HmacValidAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $lastReceived = $context->lastReceivedMessage;
        if ($lastReceived === null) {
            $this->lastMessage = "No message received";
            return false;
        }

        if ($lastReceived->isSigned()) {
            $this->lastMessage = "Last message has HMAC signature";
            return true;
        }

        $this->lastMessage = "Last message has no HMAC signature";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
