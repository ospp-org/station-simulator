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
            function ($e) {
                // Check for non-null, non-zero errorCode
                if (isset($e->payload['errorCode']) && $e->payload['errorCode'] !== null && $e->payload['errorCode'] !== 0) {
                    return true;
                }

                // Check for Rejected status
                if (isset($e->payload['status']) && $e->payload['status'] === 'Rejected') {
                    return true;
                }

                return false;
            },
        );

        if (count($errorMessages) === 0) {
            $this->lastMessage = 'No error messages found';

            return true;
        }

        // Show details of error messages for debugging
        $details = [];
        foreach ($errorMessages as $e) {
            $details[] = $e->action . '(' . $e->messageType->value . ')';
        }

        $this->lastMessage = count($errorMessages) . ' error(s): ' . implode(', ', $details);

        return false;
    }

    public function getLastMessage(): string { return $this->lastMessage; }
}
