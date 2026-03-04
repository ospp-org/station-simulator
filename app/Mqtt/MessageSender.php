<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Logging\MessageLogger;
use App\Station\SimulatedStation;
use Ospp\Protocol\Crypto\CanonicalJsonSerializer;
use Ospp\Protocol\Crypto\MacSigner;
use Ospp\Protocol\Enums\MessageType;
use Ospp\Protocol\Enums\SigningMode;
use Ospp\Protocol\Envelope\MessageBuilder;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class MessageSender
{
    private readonly MacSigner $macSigner;
    private SigningMode $signingMode = SigningMode::CRITICAL;
    private ?\Closure $onSentCallback = null;

    public function __construct(
        private readonly MqttConnectionManager $mqtt,
        private readonly MessageLogger $logger,
    ) {
        $this->macSigner = new MacSigner(new CanonicalJsonSerializer());
    }

    public function setOnSentCallback(\Closure $callback): void
    {
        $this->onSentCallback = $callback;
    }

    public function setSigningMode(SigningMode $mode): void
    {
        $this->signingMode = $mode;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendRequest(SimulatedStation $station, string $action, array $payload): MessageEnvelope
    {
        $envelope = MessageBuilder::stationRequest($action)
            ->withPayload($payload)
            ->build();

        return $this->signAndSend($station, $envelope);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendEvent(SimulatedStation $station, string $action, array $payload): MessageEnvelope
    {
        $envelope = MessageBuilder::stationEvent($action)
            ->withPayload($payload)
            ->build();

        return $this->signAndSend($station, $envelope);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendResponse(
        SimulatedStation $station,
        string $action,
        array $payload,
        MessageEnvelope $inResponseTo,
    ): MessageEnvelope {
        $envelope = MessageBuilder::response($action)
            ->correlatedTo($inResponseTo)
            ->withPayload($payload)
            ->build();

        return $this->signAndSend($station, $envelope);
    }

    private function signAndSend(SimulatedStation $station, MessageEnvelope $envelope): MessageEnvelope
    {
        // Apply HMAC signing if session key available and signing mode allows
        if (
            $station->state->sessionKey !== null
            && $this->signingMode->shouldSign($envelope->action)
        ) {
            $envelopeArray = $envelope->toArray();
            unset($envelopeArray['mac']);
            $mac = $this->macSigner->sign($envelopeArray, $station->state->sessionKey);
            $envelope = $envelope->withMac($mac);
        }

        $json = $envelope->toJson();
        // Ensure empty payload serializes as JSON object {} not array []
        $json = str_replace('"payload":[]', '"payload":{}', $json);
        $this->mqtt->publish($station->getStationId(), $json);
        // Process any responses that arrived during the blocking publish (QoS 1)
        $this->mqtt->pollOnce();
        $this->logger->logOutbound($station->getStationId(), $envelope);

        $station->emit('message.sent', [
            'action' => $envelope->action,
            'messageType' => $envelope->messageType->value,
            'messageId' => (string) $envelope->messageId,
        ]);

        if ($this->onSentCallback !== null) {
            ($this->onSentCallback)($envelope);
        }

        return $envelope;
    }
}
