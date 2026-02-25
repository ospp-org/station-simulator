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

final class ChangeConfigurationHandler
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
        $key = $payload['key'] ?? '';
        $value = $payload['value'] ?? '';

        $this->output->config("ChangeConfiguration for {$stationId}: {$key} = {$value}");

        $behaviorConfig = $station->config->getBehaviorFor('change_configuration') ?? [];
        $config = AutoResponderConfig::fromArray('change_configuration', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [50, 150];

        $this->delay->afterDelay($delayRange, function () use ($station, $envelope, $config, $key, $value): void {
            $decision = $this->decider->decide($config);

            $status = match ($decision) {
                ResponseDecision::ACCEPTED => 'Accepted',
                ResponseDecision::REBOOT_REQUIRED => 'RebootRequired',
                ResponseDecision::NOT_SUPPORTED => 'NotSupported',
                ResponseDecision::REJECTED => 'Rejected',
            };

            if ($decision === ResponseDecision::ACCEPTED || $decision === ResponseDecision::REBOOT_REQUIRED) {
                $station->state->configValues[$key] = $value;
            }

            $this->sender->sendResponse($station, OsppAction::CHANGE_CONFIGURATION, [
                'status' => $status,
                'key' => $key,
            ], $envelope);

            $this->output->config("ChangeConfiguration {$key}: {$status}");
        });
    }
}
