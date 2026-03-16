<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\StationLifecycle;
use App\Station\BootReason;
use App\Station\SimulatedStation;
use App\Station\StationState;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class ResetHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly StationLifecycle $lifecycle,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
        private readonly \Closure $rebootCallback,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $resetType = $payload['type'] ?? 'Soft';

        $this->output->reset("Reset for {$stationId}: {$resetType}");

        // Guard: only accept reset when station is ONLINE
        if ($station->state->lifecycle !== StationState::LIFECYCLE_ONLINE) {
            $this->sender->sendResponse($station, OsppAction::RESET, [
                'status' => 'Rejected',
                'errorCode' => 3020,
                'errorText' => 'Station not in resetable state',
            ], $envelope);

            return;
        }

        $behaviorConfig = $station->config->getBehaviorFor('reset') ?? [];
        $config = AutoResponderConfig::fromArray('reset', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [200, 1000];

        $this->delay->afterDelay("reset:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $resetType, $behaviorConfig): void {
            // TOCTOU guard: re-check state inside async callback
            if ($station->state->lifecycle !== StationState::LIFECYCLE_ONLINE) {
                return;
            }

            $decision = $this->decider->decide($config);

            if ($decision !== ResponseDecision::ACCEPTED) {
                $this->sender->sendResponse($station, OsppAction::RESET, [
                    'status' => 'Rejected',
                ], $envelope);

                return;
            }

            $this->sender->sendResponse($station, OsppAction::RESET, [
                'status' => 'Accepted',
            ], $envelope);

            // Stop heartbeat
            $this->timers->cancelTimer("heartbeat:{$station->getStationId()}");

            // Set bays to Unavailable
            foreach ($station->getBays() as $bay) {
                $bay->transitionTo(BayStatus::UNAVAILABLE);
                $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
                    'bayId' => $bay->bayId,
                    'bayNumber' => $bay->bayNumber,
                    'status' => BayStatus::UNAVAILABLE->toOspp(),
                    'services' => array_map(fn (array $svc) => [
                        'serviceId' => $svc['service_id'] ?? $svc['serviceId'] ?? '',
                        'available' => $svc['available'] ?? true,
                    ], $bay->services),
                ]);
            }

            // Transition to RESETTING
            $this->lifecycle->transition($station->state, StationState::LIFECYCLE_RESETTING);

            // Simulate reboot delay
            $rebootDuration = $this->randomInRange($behaviorConfig['reboot_duration_ms'] ?? [3000, 8000]);

            $this->timers->addTimer("reset-reboot:{$station->getStationId()}", $rebootDuration / 1000, function () use ($station): void {
                // Transition to OFFLINE then re-boot
                $this->lifecycle->transition($station->state, StationState::LIFECYCLE_OFFLINE);
                $station->state->bootReason = BootReason::MANUAL_RESET;

                // Trigger re-boot via callback
                ($this->rebootCallback)($station);
            });
        });
    }

    /** @param array{0: int, 1: int} $range */
    private function randomInRange(array $range): int
    {
        return random_int($range[0], $range[1]);
    }
}
