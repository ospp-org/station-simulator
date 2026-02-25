<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

final class AssertionRunner
{
    /** @var array<string, AssertionInterface> */
    private array $assertions = [];

    private string $lastMessage = '';

    public function __construct()
    {
        $this->assertions = [
            'bay_status' => new BayStatusAssertion(),
            'station_state' => new StationStateAssertion(),
            'session_active' => new SessionActiveAssertion(),
            'message_received' => new MessageReceivedAssertion(),
            'message_count' => new MessageCountAssertion(),
            'response_status' => new ResponseStatusAssertion(),
            'response_time' => new ResponseTimeAssertion(),
            'payload_field' => new PayloadFieldAssertion(),
            'meter_values_monotonic' => new MeterValuesMonotonicAssertion(),
            'no_errors' => new NoErrorsAssertion(),
            'hmac_valid' => new HmacValidAssertion(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function run(string $type, array $params, ScenarioContext $context): bool
    {
        $assertion = $this->assertions[$type] ?? null;
        if ($assertion === null) {
            $this->lastMessage = "Unknown assertion type: {$type}";

            return false;
        }

        $result = $assertion->evaluate($params, $context);
        $this->lastMessage = $assertion->getLastMessage();

        return $result;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
