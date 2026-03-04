<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\StatusNotificationService;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class TriggerMessageHandler
{
    private const SUPPORTED_MESSAGES = [
        OsppAction::STATUS_NOTIFICATION,
        OsppAction::HEARTBEAT,
        OsppAction::SECURITY_EVENT,
    ];

    private const NOT_IMPLEMENTED_MESSAGES = [
        OsppAction::METER_VALUES,
        OsppAction::BOOT_NOTIFICATION,
        OsppAction::DIAGNOSTICS_NOTIFICATION,
        OsppAction::FIRMWARE_STATUS_NOTIFICATION,
        OsppAction::SIGN_CERTIFICATE,
    ];

    public function __construct(
        private readonly MessageSender $sender,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly StatusNotificationService $statusService,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $requestedMessage = $payload['requestedMessage'] ?? '';
        $bayId = $payload['bayId'] ?? null;

        $this->output->info("TriggerMessage for {$stationId}: {$requestedMessage}");

        if (in_array($requestedMessage, self::NOT_IMPLEMENTED_MESSAGES, true)) {
            $this->sender->sendResponse($station, OsppAction::TRIGGER_MESSAGE, [
                'status' => 'NotImplemented',
            ], $envelope);

            return;
        }

        if (! in_array($requestedMessage, self::SUPPORTED_MESSAGES, true)) {
            $this->sender->sendResponse($station, OsppAction::TRIGGER_MESSAGE, [
                'status' => 'Rejected',
            ], $envelope);

            return;
        }

        $behaviorConfig = $station->config->getBehaviorFor('trigger_message') ?? [];
        $config = AutoResponderConfig::fromArray('trigger_message', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [50, 150];

        $this->delay->afterDelay("trigger-message:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $requestedMessage, $bayId): void {
            $decision = $this->decider->decide($config);

            if ($decision !== ResponseDecision::ACCEPTED) {
                $this->sender->sendResponse($station, OsppAction::TRIGGER_MESSAGE, [
                    'status' => 'Rejected',
                ], $envelope);

                return;
            }

            $this->sender->sendResponse($station, OsppAction::TRIGGER_MESSAGE, [
                'status' => 'Accepted',
            ], $envelope);

            $this->executeTriggeredMessage($station, $requestedMessage, $bayId);
        });
    }

    private function executeTriggeredMessage(SimulatedStation $station, string $requestedMessage, ?string $bayId): void
    {
        match ($requestedMessage) {
            OsppAction::STATUS_NOTIFICATION => $this->triggerStatusNotification($station, $bayId),
            OsppAction::HEARTBEAT => $this->sender->sendRequest($station, OsppAction::HEARTBEAT, []),
            OsppAction::SECURITY_EVENT => $this->triggerSecurityEvent($station),
            default => null,
        };
    }

    private function triggerStatusNotification(SimulatedStation $station, ?string $bayId): void
    {
        if ($bayId !== null) {
            $bay = $station->getBay($bayId);
            if ($bay !== null) {
                $this->statusService->sendForBay($station, $bay);
            }
        } else {
            foreach ($station->getBays() as $bay) {
                $this->statusService->sendForBay($station, $bay);
            }
        }
    }

    private function triggerSecurityEvent(SimulatedStation $station): void
    {
        $this->sender->sendEvent($station, OsppAction::SECURITY_EVENT, [
            'eventId' => 'sec_' . bin2hex(random_bytes(8)),
            'type' => 'HardwareFault',
            'severity' => 'Info',
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'details' => ['source' => 'TriggerMessage', 'stationId' => $station->getStationId()],
        ]);
    }
}
