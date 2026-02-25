<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Station\SimulatedStation;

final class EventBroadcaster
{
    private const EVENTS = [
        'station.stateChanged',
        'bay.statusChanged',
        'session.updated',
        'message.sent',
        'message.received',
        'heartbeat.tick',
        'scenario.progress',
        'scenario.completed',
        'firmware.progress',
        'connection.changed',
    ];

    public function __construct(
        private readonly WebSocketServer $ws,
    ) {}

    public function subscribe(SimulatedStation $station): void
    {
        foreach (self::EVENTS as $event) {
            $station->on($event, function (array $data) use ($event): void {
                $this->broadcast($event, $data);
            });
        }
    }

    /** @param array<string, mixed> $data */
    public function broadcast(string $event, array $data = []): void
    {
        $payload = json_encode([
            'event' => $event,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
        ], JSON_THROW_ON_ERROR);

        $this->ws->broadcast($payload);
    }
}
