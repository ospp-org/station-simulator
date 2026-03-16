<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class ReserveBayHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedBayFSM $bayFSM,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $payload = $envelope->payload;
        $bayId = $payload['bayId'] ?? '';
        $reservationId = $payload['reservationId'] ?? '';
        $ttlMinutes = (int) ($payload['ttlMinutes'] ?? 5);

        $bay = $station->getBay($bayId);
        $config = $this->getConfig($station);

        if ($bay === null) {
            $this->sendReject($station, $envelope, 3005, 'Bay not found');

            return;
        }

        if (! $bay->status->canReserve()) {
            $this->sendReject($station, $envelope, 3014, 'Bay not available for reservation');

            return;
        }

        $decision = $this->decider->decide($config);

        $stationId = $station->getStationId();
        $this->delay->afterConfigDelay("reserve-bay:{$stationId}:{$bayId}", $config, function () use (
            $station, $envelope, $bay, $bayId, $reservationId, $ttlMinutes, $decision, $config,
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
            if ($bay->status === BayStatus::RESERVED && $bay->currentReservationId === $reservationId) {
                // Idempotent: same reservation already set, re-send Accepted
                $this->sender->sendResponse($station, OsppAction::RESERVE_BAY, [
                    'status' => 'Accepted',
                ], $envelope);

                return;
            }

            if (! $bay->status->canReserve()) {
                $this->sendReject($station, $envelope, 3014, 'Bay not available for reservation');

                return;
            }

            // Accept: transition bay, set reservation
            $this->bayFSM->transition($station, $bay, BayStatus::RESERVED);
            $bay->setReservation($reservationId);

            $this->output->bay("Bay {$bayId} reserved (reservation: {$reservationId}, TTL: {$ttlMinutes}min)");

            $this->sender->sendResponse($station, OsppAction::RESERVE_BAY, [
                'status' => 'Accepted',
            ], $envelope);

            // Start expiration timer
            $this->timers->addTimer(
                "reservation-expire:{$bayId}",
                (float) ($ttlMinutes * 60),
                function () use ($station, $bay, $bayId, $reservationId): void {
                    if ($bay->currentReservationId === $reservationId && $bay->status === BayStatus::RESERVED) {
                        $bay->clearReservation();
                        $this->bayFSM->transition($station, $bay, BayStatus::AVAILABLE);
                        $this->output->bay("Reservation {$reservationId} expired on bay {$bayId}");

                        // Send StatusNotification for bay returning to Available
                        $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
                            'bayId' => $bay->bayId,
                            'bayNumber' => $bay->bayNumber,
                            'status' => BayStatus::AVAILABLE->toOspp(),
                            'services' => array_map(fn (array $svc) => [
                                'serviceId' => $svc['service_id'] ?? $svc['serviceId'] ?? '',
                                'available' => $svc['available'] ?? true,
                            ], $bay->services),
                        ]);
                    }
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
        $this->sender->sendResponse($station, OsppAction::RESERVE_BAY, [
            'status' => 'Rejected',
            'errorCode' => $errorCode,
            'errorText' => $errorText,
        ], $envelope);
    }

    private function getConfig(SimulatedStation $station): AutoResponderConfig
    {
        $behaviorConfig = $station->config->getBehaviorFor('reserve_bay');

        return AutoResponderConfig::fromArray('reserve_bay', $behaviorConfig ?? []);
    }
}
