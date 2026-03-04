<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;

final class HeartbeatService
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function start(SimulatedStation $station): void
    {
        $stationId = $station->getStationId();
        $interval = (float) $station->state->heartbeatInterval;

        $this->timers->addPeriodicTimer("heartbeat:{$stationId}", $interval, function () use ($station): void {
            if (! $station->state->isOnline()) {
                return;
            }

            $this->sender->sendRequest($station, OsppAction::HEARTBEAT, []);

            $station->state->lastHeartbeat = new \DateTimeImmutable();

            $station->emit('heartbeat.tick', [
                'timestamp' => $station->state->lastHeartbeat->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        });
    }

    public function stop(SimulatedStation $station): void
    {
        $this->timers->cancelTimer("heartbeat:{$station->getStationId()}");
    }

    public function handleResponse(SimulatedStation $station, array $payload): void
    {
        $serverTime = $payload['serverTime'] ?? null;
        if ($serverTime !== null) {
            $this->output->heartbeat("Heartbeat ACK for {$station->getStationId()} (server: {$serverTime})");
        }
    }
}
