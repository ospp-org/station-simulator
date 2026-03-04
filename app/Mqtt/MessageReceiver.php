<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Logging\ColoredConsoleOutput;
use App\Logging\MessageLogger;
use App\Station\SimulatedStation;
use Ospp\Protocol\Crypto\CanonicalJsonSerializer;
use Ospp\Protocol\Crypto\MacSigner;
use Ospp\Protocol\Enums\MessageType;
use Ospp\Protocol\Envelope\MessageEnvelope;
use Ospp\Protocol\ValueObjects\MessageId;
use Ospp\Protocol\ValueObjects\ProtocolVersion;

final class MessageReceiver
{
    private readonly MacSigner $macSigner;

    /** @var array<string, float> Circular buffer: messageId => receivedAt timestamp */
    private array $seenMessages = [];
    private const DEDUP_CAPACITY = 1000;
    private const DEDUP_TTL_SECONDS = 300;

    /** @var array<string, SimulatedStation> */
    private array $stations = [];

    /** @var callable|null */
    private $commandRouter = null;

    /** @var callable|null Called with (MessageEnvelope) for each received message */
    private $onMessageCallback = null;

    public function __construct(
        private readonly MessageLogger $logger,
        private readonly ColoredConsoleOutput $output,
    ) {
        $this->macSigner = new MacSigner(new CanonicalJsonSerializer());
    }

    /** @param array<string, SimulatedStation> $stations */
    public function setStations(array $stations): void
    {
        $this->stations = $stations;
    }

    public function setCommandRouter(callable $router): void
    {
        $this->commandRouter = $router;
    }

    public function setOnMessageCallback(callable $callback): void
    {
        $this->onMessageCallback = $callback;
    }

    public function handleMessage(string $stationId, string $rawJson): void
    {
        $station = $this->stations[$stationId] ?? null;
        if ($station === null) {
            return;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->output->error("Invalid JSON from {$stationId}: {$e->getMessage()}");

            return;
        }

        $envelope = $this->parseEnvelope($data);
        if ($envelope === null) {
            $this->output->error("Failed to parse envelope from {$stationId}");

            return;
        }

        // QoS 1 deduplication
        $messageIdStr = (string) $envelope->messageId;
        if ($this->isDuplicate($messageIdStr)) {
            $this->output->warning("Duplicate message {$messageIdStr} from {$stationId}, skipping");

            return;
        }
        $this->markSeen($messageIdStr);

        // Optional HMAC verification
        if ($envelope->isSigned() && $station->state->sessionKey !== null) {
            $envelopeArray = $envelope->toArray();
            unset($envelopeArray['mac']);
            $valid = $this->macSigner->verify(
                $envelopeArray,
                $envelope->mac,
                $station->state->sessionKey,
            );
            if (! $valid) {
                $this->output->warning("HMAC verification failed for {$messageIdStr} from {$stationId}");
            }
        }

        $this->logger->logInbound($stationId, $envelope);

        // Notify scenario context (if wired)
        if ($this->onMessageCallback !== null) {
            ($this->onMessageCallback)($envelope);
        }

        $station->emit('message.received', [
            'action' => $envelope->action,
            'messageType' => $envelope->messageType->value,
            'messageId' => $messageIdStr,
        ]);

        // Route to handler
        if ($this->commandRouter !== null) {
            ($this->commandRouter)($station, $envelope);
        }
    }

    /** @param array<string, mixed> $data */
    private function parseEnvelope(array $data): ?MessageEnvelope
    {
        try {
            return new MessageEnvelope(
                messageId: MessageId::fromString($data['messageId'] ?? ''),
                messageType: MessageType::from($data['messageType'] ?? ''),
                action: $data['action'] ?? '',
                timestamp: new \DateTimeImmutable($data['timestamp'] ?? 'now'),
                source: $data['source'] ?? 'Server',
                protocolVersion: ProtocolVersion::fromString($data['protocolVersion'] ?? '0.1.0'),
                payload: $data['payload'] ?? [],
                mac: $data['mac'] ?? null,
            );
        } catch (\Throwable $e) {
            $this->output->error("Envelope parse error: {$e->getMessage()}");

            return null;
        }
    }

    private function isDuplicate(string $messageId): bool
    {
        if (! isset($this->seenMessages[$messageId])) {
            return false;
        }

        $age = microtime(true) - $this->seenMessages[$messageId];

        return $age < self::DEDUP_TTL_SECONDS;
    }

    private function markSeen(string $messageId): void
    {
        $this->seenMessages[$messageId] = microtime(true);

        // Evict oldest if over capacity
        if (count($this->seenMessages) > self::DEDUP_CAPACITY) {
            asort($this->seenMessages);
            $this->seenMessages = array_slice($this->seenMessages, -self::DEDUP_CAPACITY, preserve_keys: true);
        }
    }
}
