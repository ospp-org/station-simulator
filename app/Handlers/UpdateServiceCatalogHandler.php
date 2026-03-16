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
use Ospp\Protocol\Envelope\MessageEnvelope;

final class UpdateServiceCatalogHandler
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
        $services = $payload['services'] ?? [];

        $this->output->config("UpdateServiceCatalog for {$stationId}: " . count($services) . " services");

        $behaviorConfig = $station->config->getBehaviorFor('update_service_catalog') ?? [];
        $config = AutoResponderConfig::fromArray('update_service_catalog', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [100, 300];

        $this->delay->afterDelay("update-catalog:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $services): void {
            $decision = $this->decider->decide($config);

            if ($decision !== ResponseDecision::ACCEPTED) {
                $this->sender->sendResponse($station, OsppAction::UPDATE_SERVICE_CATALOG, [
                    'status' => 'Rejected',
                ], $envelope);

                return;
            }

            // Update internal services on all bays
            foreach ($station->getBays() as $bay) {
                $bay->services = $services;
            }

            $this->sender->sendResponse($station, OsppAction::UPDATE_SERVICE_CATALOG, [
                'status' => 'Accepted',
            ], $envelope);

            // Send StatusNotification per bay to reflect service changes
            foreach ($station->getBays() as $bay) {
                $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
                    'bayId' => $bay->bayId,
                    'bayNumber' => $bay->bayNumber,
                    'status' => $bay->status->toOspp(),
                    'services' => array_map(fn (array $svc) => [
                        'serviceId' => $svc['service_id'] ?? $svc['serviceId'] ?? '',
                        'available' => $svc['available'] ?? true,
                    ], $bay->services),
                ]);
            }

            $this->output->config("Service catalog updated: " . count($services) . " services applied");
        });
    }
}
