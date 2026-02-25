<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use Psr\Http\Message\ServerRequestInterface;

final class StationController
{
    /** @param array<string, SimulatedStation> $stations */
    public function __construct(
        private array &$stations,
        private readonly StationConfig $stationConfig,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('GET', '/api/stations', [$this, 'list']);
        $server->registerRoute('GET', '/api/stations/{id}', [$this, 'show']);
        $server->registerRoute('POST', '/api/stations', [$this, 'create']);
        $server->registerRoute('DELETE', '/api/stations/{id}', [$this, 'delete']);
    }

    /** @return array<string, mixed> */
    public function list(ServerRequestInterface $request, array $params = []): array
    {
        $stations = [];

        foreach ($this->stations as $station) {
            $stations[] = $this->serializeStation($station);
        }

        return ['stations' => $stations, 'count' => count($stations)];
    }

    /** @return array<string, mixed> */
    public function show(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');

        return $this->serializeStationDetailed($station);
    }

    /** @return array<string, mixed> */
    public function create(ServerRequestInterface $request, array $params = []): array
    {
        $nextIndex = count($this->stations) + 1;
        $station = SimulatedStation::create($this->stationConfig, $nextIndex);
        $this->stations[$station->getStationId()] = $station;

        return [
            'created' => true,
            'station' => $this->serializeStation($station),
        ];
    }

    /** @return array<string, mixed> */
    public function delete(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $stationId = $station->getStationId();

        $station->emit('station.removed', []);
        unset($this->stations[$stationId]);

        return ['deleted' => true, 'stationId' => $stationId];
    }

    private function findStation(string $id): SimulatedStation
    {
        $station = $this->stations[$id] ?? null;

        if ($station === null) {
            throw new \InvalidArgumentException("Station not found: {$id}");
        }

        return $station;
    }

    /** @return array<string, mixed> */
    private function serializeStation(SimulatedStation $station): array
    {
        return [
            'stationId' => $station->getStationId(),
            'model' => $station->identity->model,
            'vendor' => $station->identity->vendor,
            'lifecycle' => $station->state->lifecycle,
            'bayCount' => count($station->getBays()),
            'firmwareVersion' => $station->identity->firmwareVersion,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeStationDetailed(SimulatedStation $station): array
    {
        $bays = [];

        foreach ($station->getBays() as $bay) {
            $bays[] = [
                'bayId' => $bay->bayId,
                'bayNumber' => $bay->bayNumber,
                'status' => $bay->status->value,
                'currentSessionId' => $bay->currentSessionId,
                'currentReservationId' => $bay->currentReservationId,
                'errorCode' => $bay->errorCode,
                'errorText' => $bay->errorText,
            ];
        }

        return [
            'stationId' => $station->getStationId(),
            'model' => $station->identity->model,
            'vendor' => $station->identity->vendor,
            'serial' => $station->identity->serialNumber,
            'firmwareVersion' => $station->identity->firmwareVersion,
            'lifecycle' => $station->state->lifecycle,
            'sessionKey' => $station->state->sessionKey !== null ? '***' : null,
            'heartbeatInterval' => $station->state->heartbeatInterval,
            'uptimeStart' => $station->state->uptimeStart?->format('Y-m-d\TH:i:s.v\Z'),
            'lastHeartbeat' => $station->state->lastHeartbeat?->format('Y-m-d\TH:i:s.v\Z'),
            'bays' => $bays,
        ];
    }
}
