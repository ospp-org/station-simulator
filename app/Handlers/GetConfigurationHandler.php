<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class GetConfigurationHandler
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
        $requestedKeys = $payload['keys'] ?? [];

        $this->output->config("GetConfiguration for {$stationId}: " . implode(', ', $requestedKeys));

        $config = $station->config->getBehaviorFor('get_configuration') ?? [];
        $delayRange = $config['response_delay_ms'] ?? [50, 100];

        $this->delay->afterDelay("get-config:{$stationId}", $delayRange, function () use ($station, $envelope, $requestedKeys): void {
            $configValues = $station->state->configValues;
            $known = [];
            $unknown = [];

            if (count($requestedKeys) === 0) {
                // Return all known config
                foreach ($configValues as $key => $value) {
                    $known[] = ['key' => $key, 'value' => $value, 'readonly' => false];
                }
            } else {
                foreach ($requestedKeys as $key) {
                    if (array_key_exists($key, $configValues)) {
                        $known[] = ['key' => $key, 'value' => $configValues[$key], 'readonly' => false];
                    } else {
                        // Check unknown_key_rate
                        $unknown[] = $key;
                    }
                }
            }

            $this->sender->sendResponse($station, OsppAction::GET_CONFIGURATION, [
                'status' => 'Accepted',
                'configuration' => $known,
                'unknownKeys' => $unknown,
            ], $envelope);
        });
    }
}
