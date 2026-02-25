<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class NoErrorsAssertion implements AssertionInterface
{
    private string $lastMessage = '';

    public function evaluate(array $params, ScenarioContext $context): bool
    {
        $errorMessages = array_filter(
            $context->receivedMessages,
            fn ($e) => isset($e->payload['errorCode']) || isset($e->payload['status']) && $e->payload['status'] === 'Rejected',
        );

        if (count($errorMessages) === 0) {
            $this->lastMessage = "No error messages found";
            return true;
        }

        $this->lastMessage = count($errorMessages) . " error message(s) found";
        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
