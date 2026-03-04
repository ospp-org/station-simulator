<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class MessageReceivedAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $action = $params['action'] ?? '';
        $messageType = $params['message_type'] ?? null;

        foreach (array_reverse($context->receivedMessages) as $envelope) {
            if ($envelope->action === $action) {
                if ($messageType === null || strcasecmp($envelope->messageType->value, $messageType) === 0) {
                    $this->lastMessage = "Found {$action} message";
                    return true;
                }
            }
        }

        $this->lastMessage = "No {$action} message found";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
