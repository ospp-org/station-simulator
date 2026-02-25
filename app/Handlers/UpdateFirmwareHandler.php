<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedFirmwareFSM;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;

final class UpdateFirmwareHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedFirmwareFSM $firmwareFSM,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $firmwareUrl = $payload['firmwareUrl'] ?? '';
        $targetVersion = $payload['targetVersion'] ?? '1.3.0';

        $this->output->firmware("UpdateFirmware for {$stationId}: {$targetVersion} from {$firmwareUrl}");

        $behaviorConfig = $station->config->getBehaviorFor('update_firmware') ?? [];
        $config = AutoResponderConfig::fromArray('update_firmware', $behaviorConfig);

        $decision = $this->decider->decide($config);

        if ($decision !== ResponseDecision::ACCEPTED) {
            $this->sender->sendResponse($station, OsppAction::UPDATE_FIRMWARE, [
                'status' => 'Rejected',
                'errorCode' => $config->rejectErrorCode ?? 4001,
                'errorText' => $config->rejectErrorText ?? 'Firmware update rejected',
            ], $envelope);

            return;
        }

        // Send acceptance
        $this->sender->sendResponse($station, OsppAction::UPDATE_FIRMWARE, [
            'status' => 'Accepted',
        ], $envelope);

        // Start async firmware update FSM
        $this->firmwareFSM->startUpdate(
            station: $station,
            firmwareUrl: $firmwareUrl,
            targetVersion: $targetVersion,
            downloadDurationMs: (float) $this->randomInRange($behaviorConfig['download_duration_ms'] ?? [5000, 15000]),
            installDurationMs: (float) $this->randomInRange($behaviorConfig['install_duration_ms'] ?? [3000, 10000]),
            failureRate: (float) ($behaviorConfig['failure_rate'] ?? 0.05),
            failureAtStage: $behaviorConfig['failure_at_stage'] ?? null,
            progressIntervalMs: (float) ($behaviorConfig['progress_notification_interval_ms'] ?? 2000),
        );
    }

    /** @param array{0: int, 1: int} $range */
    private function randomInRange(array $range): int
    {
        return random_int($range[0], $range[1]);
    }
}
