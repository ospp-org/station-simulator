<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Generators\MeterValueGenerator;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\StatusNotificationService;
use App\StateMachines\SimulatedBayFSM;
use App\StateMachines\SimulatedSessionFSM;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Enums\SessionStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class StopServiceHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedBayFSM $bayFSM,
        private readonly SimulatedSessionFSM $sessionFSM,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly TimerManager $timers,
        private readonly MeterValueGenerator $meterGenerator,
        private readonly StatusNotificationService $statusService,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $payload = $envelope->payload;
        $bayId = $payload['bayId'] ?? '';
        $sessionId = $payload['sessionId'] ?? '';

        $bay = $station->getBay($bayId);
        $config = $this->getConfig($station);

        // Validate bay exists
        if ($bay === null) {
            $this->sendReject($station, $envelope, 3005, 'Bay not found');

            return;
        }

        // Validate bay is occupied
        if ($bay->status !== BayStatus::OCCUPIED) {
            $this->sendReject($station, $envelope, 3006, 'No active session');

            return;
        }

        // Validate session match
        if ($bay->currentSessionId !== $sessionId) {
            $this->sendReject($station, $envelope, 3007, 'Session mismatch');

            return;
        }

        $stationId = $station->getStationId();
        $this->delay->afterConfigDelay("stop-service:{$stationId}:{$bayId}", $config, function () use (
            $station, $envelope, $bay, $bayId, $sessionId, $stationId,
        ): void {
            // TOCTOU guard: re-check bay status inside async callback
            if ($bay->status !== BayStatus::OCCUPIED) {
                return;
            }
            if ($bay->currentSessionId !== $sessionId) {
                return;
            }

            // Stop meter timer
            $this->timers->cancelTimer("meter:{$bayId}");
            $this->timers->cancelTimer("session-timeout:{$bayId}");

            // Final meter tick
            $this->meterGenerator->tick($bay, $station->config);

            // Calculate charges
            $durationSeconds = $bay->getSessionDurationSeconds();
            $serviceId = $bay->currentServiceId ?? '';
            $service = $station->config->getServiceById($serviceId);

            $creditsCharged = 0;
            if ($service !== null) {
                if ($service['pricing_type'] === 'fixed') {
                    $creditsCharged = (int) ($service['price_credits_fixed'] ?? 0);
                } else {
                    $minutes = (int) ceil($durationSeconds / 60);
                    $creditsCharged = $minutes * (int) ($service['price_credits_per_minute'] ?? 0);
                }
            }

            // Transition to STOPPING then COMPLETED
            $this->sessionFSM->transition($bayId, SessionStatus::STOPPING);

            // Transition bay to Finishing
            $this->bayFSM->transition($station, $bay, BayStatus::FINISHING);

            $meterPayload = $this->meterGenerator->buildPayload($bay, $station->getStationId());

            $this->output->session("Session {$sessionId} stopped on bay {$bayId} (duration: {$durationSeconds}s, credits: {$creditsCharged})");

            // Send accept response
            $this->sender->sendResponse($station, OsppAction::STOP_SERVICE, [
                'status' => 'Accepted',
            ], $envelope);

            // Send TransactionEvent with session summary
            $this->sender->sendEvent($station, OsppAction::TRANSACTION_EVENT, [
                'stationId' => $station->getStationId(),
                'bayId' => $bayId,
                'sessionId' => $sessionId,
                'serviceId' => $serviceId,
                'eventType' => 'Ended',
                'durationSeconds' => $durationSeconds,
                'creditsCharged' => $creditsCharged,
                'meterValues' => $meterPayload['values'] ?? [],
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            ]);

            $this->sessionFSM->transition($bayId, SessionStatus::COMPLETED);

            $station->emit('session.updated', [
                'bayId' => $bayId,
                'sessionId' => $sessionId,
                'status' => 'completed',
                'durationSeconds' => $durationSeconds,
                'creditsCharged' => $creditsCharged,
            ]);

            // Cleanup delay (2-5s) → Finishing → Available
            $cleanupDelay = random_int(2000, 5000) / 1000;
            $this->delay->afterDelay("stop-cleanup:{$stationId}:{$bayId}", [(int) ($cleanupDelay * 1000), (int) ($cleanupDelay * 1000)], function () use (
                $station, $bay, $bayId,
            ): void {
                $bay->endSession();
                $this->bayFSM->transition($station, $bay, BayStatus::AVAILABLE);
                $this->statusService->sendForBay($station, $bay);
                $this->output->bay("Bay {$bayId} returned to Available");
            });
        });
    }

    private function sendReject(
        SimulatedStation $station,
        MessageEnvelope $envelope,
        int $errorCode,
        string $errorText,
    ): void {
        $this->output->session("StopService rejected for {$station->getStationId()}: {$errorText} ({$errorCode})");

        $this->sender->sendResponse($station, OsppAction::STOP_SERVICE, [
            'status' => 'Rejected',
            'errorCode' => $errorCode,
            'errorText' => $errorText,
        ], $envelope);
    }

    private function getConfig(SimulatedStation $station): AutoResponderConfig
    {
        $behaviorConfig = $station->config->getBehaviorFor('stop_service');

        return AutoResponderConfig::fromArray('stop_service', $behaviorConfig ?? []);
    }
}
