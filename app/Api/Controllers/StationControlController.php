<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Station\SimulatedStation;
use Psr\Http\Message\ServerRequestInterface;

final class StationControlController
{
    /** @param array<string, SimulatedStation> $stations */
    public function __construct(
        private array &$stations,
        private readonly \Closure $bootCallback,
        private readonly \Closure $disconnectCallback,
        private readonly \Closure $reconnectCallback,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('POST', '/api/stations/{id}/boot', [$this, 'boot']);
        $server->registerRoute('POST', '/api/stations/{id}/disconnect', [$this, 'disconnect']);
        $server->registerRoute('POST', '/api/stations/{id}/reconnect', [$this, 'reconnect']);
    }

    /** @return array<string, mixed> */
    public function boot(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        ($this->bootCallback)($station);

        return ['action' => 'boot', 'stationId' => $station->getStationId(), 'status' => 'initiated'];
    }

    /** @return array<string, mixed> */
    public function disconnect(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        ($this->disconnectCallback)($station);

        return ['action' => 'disconnect', 'stationId' => $station->getStationId(), 'status' => 'initiated'];
    }

    /** @return array<string, mixed> */
    public function reconnect(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        ($this->reconnectCallback)($station);

        return ['action' => 'reconnect', 'stationId' => $station->getStationId(), 'status' => 'initiated'];
    }

    private function findStation(string $id): SimulatedStation
    {
        $station = $this->stations[$id] ?? null;

        if ($station === null) {
            throw new \InvalidArgumentException("Station not found: {$id}");
        }

        return $station;
    }
}
