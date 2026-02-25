<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedDiagnosticsFSM;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;

final class GetDiagnosticsHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedDiagnosticsFSM $diagnosticsFSM,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $uploadUrl = $payload['uploadUrl'] ?? '';

        $this->output->diag("GetDiagnostics for {$stationId}: upload to {$uploadUrl}");

        $behaviorConfig = $station->config->getBehaviorFor('get_diagnostics') ?? [];
        $config = AutoResponderConfig::fromArray('get_diagnostics', $behaviorConfig);

        $decision = $this->decider->decide($config);

        if ($decision !== ResponseDecision::ACCEPTED) {
            $this->sender->sendResponse($station, OsppAction::GET_DIAGNOSTICS, [
                'status' => 'Rejected',
                'errorCode' => $config->rejectErrorCode ?? 4002,
                'errorText' => $config->rejectErrorText ?? 'Diagnostics rejected',
            ], $envelope);

            return;
        }

        $this->sender->sendResponse($station, OsppAction::GET_DIAGNOSTICS, [
            'status' => 'Accepted',
            'fileName' => "diag_{$stationId}_" . date('Ymd_His') . '.tar.gz',
        ], $envelope);

        // Start async diagnostics FSM
        $this->diagnosticsFSM->startDiagnostics(
            station: $station,
            uploadUrl: $uploadUrl,
            collectionDurationMs: (float) $this->randomInRange($behaviorConfig['collection_duration_ms'] ?? [2000, 5000]),
            uploadDurationMs: (float) $this->randomInRange($behaviorConfig['upload_duration_ms'] ?? [1000, 3000]),
            failureRate: (float) ($behaviorConfig['failure_rate'] ?? 0.02),
        );
    }

    /** @param array{0: int, 1: int} $range */
    private function randomInRange(array $range): int
    {
        return random_int($range[0], $range[1]);
    }
}
