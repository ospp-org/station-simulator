<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Enums\BayStatus;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;

final class CancelReservationHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly SimulatedBayFSM $bayFSM,
        private readonly DelaySimulator $delay,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $payload = $envelope->payload;
        $reservationId = $payload['reservationId'] ?? '';

        $bay = $station->findBayByReservation($reservationId);
        $config = $this->getConfig($station);

        if ($bay === null) {
            $this->sender->sendResponse($station, OsppAction::CANCEL_RESERVATION, [
                'status' => 'Rejected',
                'stationId' => $station->getStationId(),
                'errorCode' => 3012,
                'errorText' => 'Reservation not found',
            ], $envelope);

            return;
        }

        if ($bay->status !== BayStatus::RESERVED) {
            $this->sender->sendResponse($station, OsppAction::CANCEL_RESERVATION, [
                'status' => 'Rejected',
                'stationId' => $station->getStationId(),
                'errorCode' => 3013,
                'errorText' => 'Reservation expired',
            ], $envelope);

            return;
        }

        $this->delay->afterConfigDelay($config, function () use (
            $station, $envelope, $bay, $reservationId,
        ): void {
            // Cancel expiration timer
            $this->timers->cancelTimer("reservation-expire:{$bay->bayId}");

            // Clear reservation, transition to Available
            $bay->clearReservation();
            $this->bayFSM->transition($station, $bay, BayStatus::AVAILABLE);

            $this->output->bay("Reservation {$reservationId} cancelled on bay {$bay->bayId}");

            $this->sender->sendResponse($station, OsppAction::CANCEL_RESERVATION, [
                'status' => 'Accepted',
                'stationId' => $station->getStationId(),
                'bayId' => $bay->bayId,
                'reservationId' => $reservationId,
            ], $envelope);
        });
    }

    private function getConfig(SimulatedStation $station): AutoResponderConfig
    {
        $behaviorConfig = $station->config->getBehaviorFor('cancel_reservation');

        return AutoResponderConfig::fromArray('cancel_reservation', $behaviorConfig ?? []);
    }
}
