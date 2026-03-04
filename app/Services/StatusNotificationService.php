<?php

declare(strict_types=1);

namespace App\Services;

use App\Mqtt\MessageSender;
use App\Station\BayState;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;

final class StatusNotificationService
{
    public function __construct(
        private readonly MessageSender $sender,
    ) {}

    public function sendAllBays(SimulatedStation $station, BayStatus $status): void
    {
        foreach ($station->getBays() as $bay) {
            $bay->transitionTo($status);
            $this->sendForBay($station, $bay);
        }
    }

    public function sendForBay(SimulatedStation $station, BayState $bay): void
    {
        $payload = [
            'bayId' => $bay->bayId,
            'bayNumber' => $bay->bayNumber,
            'status' => $bay->status->toOspp(),
            'services' => array_map(fn (array $svc) => [
                'serviceId' => $svc['service_id'] ?? $svc['serviceId'] ?? '',
                'available' => $svc['available'] ?? true,
            ], $bay->services),
        ];

        $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, $payload);
    }
}
