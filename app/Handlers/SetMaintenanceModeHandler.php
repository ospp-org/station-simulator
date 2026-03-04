<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class SetMaintenanceModeHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $bayId = $payload['bayId'] ?? '';
        $enabled = (bool) ($payload['enabled'] ?? true);

        $this->output->config("SetMaintenanceMode for {$stationId} bay {$bayId}: " . ($enabled ? 'enabled' : 'disabled'));

        // Determine target bays: specific bay or all bays
        $targetBays = [];
        if ($bayId !== '') {
            $bay = $station->getBay($bayId);
            if ($bay === null) {
                $this->sender->sendResponse($station, OsppAction::SET_MAINTENANCE_MODE, [
                    'status' => 'Rejected',
                    'errorCode' => 3005,
                    'errorText' => 'Bay not found',
                ], $envelope);

                return;
            }
            $targetBays = [$bay];
        } else {
            $targetBays = array_values($station->getBays());
        }

        $behaviorConfig = $station->config->getBehaviorFor('set_maintenance_mode') ?? [];
        $config = AutoResponderConfig::fromArray('set_maintenance_mode', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [100, 300];

        $this->delay->afterDelay("set-maintenance:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $targetBays, $enabled, $bayId): void {
            $decision = $this->decider->decide($config);

            if ($decision !== ResponseDecision::ACCEPTED) {
                $this->sender->sendResponse($station, OsppAction::SET_MAINTENANCE_MODE, [
                    'status' => 'Rejected',
                ], $envelope);

                return;
            }

            $newStatus = $enabled ? BayStatus::UNAVAILABLE : BayStatus::AVAILABLE;

            foreach ($targetBays as $bay) {
                $bay->transitionTo($newStatus);

                $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
                    'stationId' => $station->getStationId(),
                    'bayId' => $bay->bayId,
                    'bayNumber' => $bay->bayNumber,
                    'status' => $newStatus->toOspp(),
                    'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
                ]);

                $station->emit('bay.statusChanged', [
                    'bayId' => $bay->bayId,
                    'status' => $newStatus->value,
                ]);
            }

            $this->sender->sendResponse($station, OsppAction::SET_MAINTENANCE_MODE, [
                'status' => 'Accepted',
            ], $envelope);
        });
    }
}
