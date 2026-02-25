<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Enums\BayStatus;
use Psr\Http\Message\ServerRequestInterface;

final class BayController
{
    /** @param array<string, SimulatedStation> $stations */
    public function __construct(
        private array &$stations,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('POST', '/api/stations/{id}/bays/{bayId}/status', [$this, 'setStatus']);
        $server->registerRoute('POST', '/api/stations/{id}/bays/{bayId}/fault', [$this, 'fault']);
        $server->registerRoute('POST', '/api/stations/{id}/bays/{bayId}/recover', [$this, 'recover']);
    }

    /** @return array<string, mixed> */
    public function setStatus(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $bay = $station->getBay($params['bayId'] ?? '');

        if ($bay === null) {
            throw new \InvalidArgumentException("Bay not found: {$params['bayId']}");
        }

        $body = $this->parseBody($request);
        $newStatus = BayStatus::tryFrom($body['status'] ?? '');

        if ($newStatus === null) {
            throw new \InvalidArgumentException("Invalid bay status: " . ($body['status'] ?? 'null'));
        }

        $bay->transitionTo($newStatus);
        $station->emit('bay.statusChanged', [
            'bayId' => $bay->bayId,
            'status' => $newStatus->value,
        ]);

        return ['bayId' => $bay->bayId, 'status' => $newStatus->value];
    }

    /** @return array<string, mixed> */
    public function fault(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $bay = $station->getBay($params['bayId'] ?? '');

        if ($bay === null) {
            throw new \InvalidArgumentException("Bay not found: {$params['bayId']}");
        }

        $body = $this->parseBody($request);
        $errorCode = (int) ($body['errorCode'] ?? 5000);
        $errorText = $body['errorText'] ?? 'Unknown fault';

        $bay->setFault($errorCode, $errorText);
        $bay->transitionTo(BayStatus::FAULTED);
        $station->emit('bay.statusChanged', [
            'bayId' => $bay->bayId,
            'status' => BayStatus::FAULTED->value,
            'errorCode' => $errorCode,
            'errorText' => $errorText,
        ]);

        return ['bayId' => $bay->bayId, 'status' => 'Faulted', 'errorCode' => $errorCode];
    }

    /** @return array<string, mixed> */
    public function recover(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $bay = $station->getBay($params['bayId'] ?? '');

        if ($bay === null) {
            throw new \InvalidArgumentException("Bay not found: {$params['bayId']}");
        }

        $bay->clearFault();
        $bay->transitionTo(BayStatus::AVAILABLE);
        $station->emit('bay.statusChanged', [
            'bayId' => $bay->bayId,
            'status' => BayStatus::AVAILABLE->value,
        ]);

        return ['bayId' => $bay->bayId, 'status' => 'Available'];
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
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
