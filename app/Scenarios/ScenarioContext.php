<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Logging\MessageLogger;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;

final class ScenarioContext
{
    /** @var array<string, SimulatedStation> */
    public array $stations;

    /** @var list<MessageEnvelope> Recently received messages for matching */
    public array $receivedMessages = [];

    public ?MessageEnvelope $lastSentMessage = null;
    public ?MessageEnvelope $lastReceivedMessage = null;

    public function __construct(
        public readonly MessageSender $sender,
        public readonly MqttConnectionManager $mqtt,
        public readonly TimerManager $timers,
        public readonly MessageLogger $logger,
    ) {}

    public function getStation(int $index = 1): ?SimulatedStation
    {
        $stations = array_values($this->stations);

        return $stations[$index - 1] ?? null;
    }

    public function addReceivedMessage(MessageEnvelope $envelope): void
    {
        $this->receivedMessages[] = $envelope;
        $this->lastReceivedMessage = $envelope;

        // Keep last 1000 messages
        if (count($this->receivedMessages) > 1000) {
            $this->receivedMessages = array_slice($this->receivedMessages, -1000);
        }
    }
}
