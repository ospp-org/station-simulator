<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Generators\MeterValueGenerator;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\StateMachines\SimulatedSessionFSM;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Enums\SessionStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class StartServiceHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedBayFSM $bayFSM,
        private readonly SimulatedSessionFSM $sessionFSM,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly TimerManager $timers,
        private readonly MeterValueGenerator $meterGenerator,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $payload = $envelope->payload;
        $bayId = $payload['bayId'] ?? '';
        $sessionId = $payload['sessionId'] ?? '';
        $serviceId = $payload['serviceId'] ?? '';
        $reservationId = $payload['reservationId'] ?? null;

        $bay = $station->getBay($bayId);
        $config = $this->getConfig($station);

        // Validate bay exists
        if ($bay === null) {
            $this->sendReject($station, $envelope, 3005, 'Bay not found');

            return;
        }

        // Validate bay status
        if ($bay->status === BayStatus::OCCUPIED) {
            $this->sendReject($station, $envelope, 3001, 'Bay already in use');

            return;
        }

        if ($bay->status === BayStatus::FAULTED) {
            $this->sendReject($station, $envelope, 3002, 'Bay faulted');

            return;
        }

        if ($bay->status === BayStatus::UNAVAILABLE) {
            $this->sendReject($station, $envelope, 3011, 'Bay under maintenance');

            return;
        }

        if (! $bay->status->canStartSession()) {
            $this->sendReject($station, $envelope, 3003, 'Service unavailable');

            return;
        }

        // Check reservation match if bay is Reserved
        if ($bay->status === BayStatus::RESERVED) {
            if ($bay->currentReservationId !== $reservationId) {
                $this->sendReject($station, $envelope, 3014, 'Bay reserved');

                return;
            }
        }

        // Consult auto-responder
        $decision = $this->decider->decide($config);

        $stationId = $station->getStationId();
        $this->delay->afterConfigDelay("start-service:{$stationId}:{$bayId}", $config, function () use (
            $station, $envelope, $bay, $bayId, $sessionId, $serviceId, $decision, $config,
        ): void {
            if ($decision !== ResponseDecision::ACCEPTED) {
                $this->sendReject(
                    $station,
                    $envelope,
                    $config->rejectErrorCode ?? 3003,
                    $config->rejectErrorText ?? 'Service unavailable',
                );

                return;
            }

            // TOCTOU guard: re-check bay status inside async callback
            if ($bay->status === BayStatus::OCCUPIED) {
                if ($bay->currentSessionId === $sessionId) {
                    // Idempotent: same session already started, re-send Accepted
                    $this->sender->sendResponse($station, OsppAction::START_SERVICE, [
                        'status' => 'Accepted',
                    ], $envelope);

                    return;
                }
                $this->sendReject($station, $envelope, 3001, 'Bay already in use');

                return;
            }

            // Accept: transition bay, start session
            $this->bayFSM->transition($station, $bay, BayStatus::OCCUPIED);
            $bay->startSession($sessionId, $serviceId);
            $this->sessionFSM->startSession($bayId);
            $this->sessionFSM->transition($bayId, SessionStatus::AUTHORIZED);
            $this->sessionFSM->transition($bayId, SessionStatus::ACTIVE);

            $this->output->session("Session {$sessionId} started on bay {$bayId} (service: {$serviceId})");

            // Send accept response
            $this->sender->sendResponse($station, OsppAction::START_SERVICE, [
                'status' => 'Accepted',
            ], $envelope);

            $station->emit('session.updated', [
                'bayId' => $bayId,
                'sessionId' => $sessionId,
                'status' => 'active',
                'serviceId' => $serviceId,
            ]);

            // Start meter values timer
            $intervalSeconds = $station->config->getMeterIntervalSeconds();
            $this->timers->addPeriodicTimer(
                "meter:{$bayId}",
                (float) $intervalSeconds,
                function () use ($station, $bay): void {
                    $this->meterGenerator->tick($bay, $station->config);
                    $payload = $this->meterGenerator->buildPayload($bay, $station->getStationId());
                    $this->sender->sendEvent($station, OsppAction::METER_VALUES, $payload);

                    $this->output->meter("MeterValues for bay {$bay->bayId}: liquid={$payload['values']['liquidMl']}ml energy={$payload['values']['energyWh']}Wh");
                },
            );

            // Start max duration timer
            $maxDuration = (int) ($station->state->configValues['MaxSessionDurationSeconds'] ?? 3600);
            $this->timers->addTimer(
                "session-timeout:{$bayId}",
                (float) $maxDuration,
                function () use ($station, $bay, $sessionId): void {
                    $this->output->session("Session {$sessionId} timed out on bay {$bay->bayId}");
                    $station->emit('session.timedOut', [
                        'bayId' => $bay->bayId,
                        'sessionId' => $sessionId,
                    ]);
                },
            );
        });
    }

    private function sendReject(
        SimulatedStation $station,
        MessageEnvelope $envelope,
        int $errorCode,
        string $errorText,
    ): void {
        $this->output->session("StartService rejected for {$station->getStationId()}: {$errorText} ({$errorCode})");

        $this->sender->sendResponse($station, OsppAction::START_SERVICE, [
            'status' => 'Rejected',
            'errorCode' => $errorCode,
            'errorText' => $errorText,
        ], $envelope);
    }

    private function getConfig(SimulatedStation $station): AutoResponderConfig
    {
        $behaviorConfig = $station->config->getBehaviorFor('start_service');

        return AutoResponderConfig::fromArray('start_service', $behaviorConfig ?? []);
    }
}
