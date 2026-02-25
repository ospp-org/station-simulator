<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Logging\MessageLogger;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Crypto\CanonicalJsonSerializer;
use OneStopPay\OsppProtocol\Crypto\MacSigner;
use OneStopPay\OsppProtocol\Enums\MessageType;
use OneStopPay\OsppProtocol\Enums\SigningMode;
use OneStopPay\OsppProtocol\Envelope\MessageBuilder;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;

final class MessageSender
{
    private readonly MacSigner $macSigner;
    private SigningMode $signingMode = SigningMode::NONE;

    public function __construct(
        private readonly MqttConnectionManager $mqtt,
        private readonly MessageLogger $logger,
    ) {
        $this->macSigner = new MacSigner(new CanonicalJsonSerializer());
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
            $mac = $this->macSigner->sign($envelope->payload, $station->state->sessionKey);
            $envelope = $envelope->withMac($mac);
        }

        $json = $envelope->toJson();
        $this->mqtt->publish($station->getStationId(), $json);
        $this->logger->logOutbound($station->getStationId(), $envelope);

        $station->emit('message.sent', [
            'action' => $envelope->action,
            'messageType' => $envelope->messageType->value,
            'messageId' => (string) $envelope->messageId,
        ]);

        return $envelope;
    }
}
