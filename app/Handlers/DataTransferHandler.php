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

final class DataTransferHandler
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
        $vendorId = $payload['vendorId'] ?? '';
        $dataId = $payload['dataId'] ?? '';
        $data = $payload['data'] ?? null;

        $this->output->info("DataTransfer for {$stationId}: vendor={$vendorId} dataId={$dataId}");

        $behaviorConfig = $station->config->getBehaviorFor('data_transfer') ?? [];
        $config = AutoResponderConfig::fromArray('data_transfer', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [50, 150];

        $this->delay->afterDelay("data-transfer:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $vendorId, $dataId, $data): void {
            $decision = $this->decider->decide($config);

            if ($decision === ResponseDecision::ACCEPTED) {
                $station->state->dataTransfers[] = [
                    'vendorId' => $vendorId,
                    'dataId' => $dataId,
                    'data' => $data,
                ];

                $this->sender->sendResponse($station, OsppAction::DATA_TRANSFER, [
                    'status' => 'Accepted',
                ], $envelope);

                $this->output->info("DataTransfer accepted: vendor={$vendorId} dataId={$dataId}");
            } else {
                $this->sender->sendResponse($station, OsppAction::DATA_TRANSFER, [
                    'status' => 'Rejected',
                ], $envelope);
            }
        });
    }
}
