<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class HmacValidAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $source = $params['source'] ?? 'received';

        $message = $source === 'sent'
            ? $context->lastSentMessage
            : $context->lastReceivedMessage;

        if ($message === null) {
            $this->lastMessage = "No {$source} message available";

            return false;
        }

        $expectSigned = ($params['expected'] ?? true) !== false;

        if ($expectSigned) {
            if ($message->isSigned()) {
                $this->lastMessage = "Last {$source} message has HMAC signature";

                return true;
            }

            $this->lastMessage = "Expected last {$source} message to have HMAC, but it was unsigned";

            return false;
        }

        // expected: false — assert message is NOT signed
        if (! $message->isSigned()) {
            $this->lastMessage = "Last {$source} message is unsigned (as expected)";

            return true;
        }

        $this->lastMessage = "Expected last {$source} message to be unsigned, but it has HMAC";

        return false;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
