<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Logging\MessageLogger;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Envelope\MessageEnvelope;
use React\EventLoop\LoopInterface;

final class ScenarioContext
{
    /** @var array<string, SimulatedStation> */
    public array $stations;

    /** @var list<MessageEnvelope> Recently received messages for matching */
    public array $receivedMessages = [];

    public ?MessageEnvelope $lastSentMessage = null;
    public ?MessageEnvelope $lastReceivedMessage = null;

    /** @var int Cursor for WaitForStep — index to start searching from */
    public int $waitForCursor = 0;

    /** @var array<string, mixed> Captured values from api_call steps */
    public array $captured = [];

    public string $csmsBaseUrl = '';
    public string $csmsJwtToken = '';

    public function __construct(
        public readonly MessageSender $sender,
        public readonly MqttConnectionManager $mqtt,
        public readonly TimerManager $timers,
        public readonly MessageLogger $logger,
        public readonly ?LoopInterface $loop = null,
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
