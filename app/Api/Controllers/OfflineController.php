<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use Psr\Http\Message\ServerRequestInterface;

final class OfflineController
{
    /** @param array<string, SimulatedStation> $stations */
    public function __construct(
        private array &$stations,
        private readonly MessageSender $sender,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('POST', '/api/stations/{id}/offline/authorize', [$this, 'authorize']);
        $server->registerRoute('POST', '/api/stations/{id}/offline/transaction', [$this, 'transaction']);
    }

    /** @return array<string, mixed> */
    public function authorize(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $body = $this->parseBody($request);

        $this->sender->sendRequest($station, OsppAction::AUTHORIZE_OFFLINE_PASS, $body);

        return ['action' => 'authorize_offline_pass', 'stationId' => $station->getStationId(), 'status' => 'sent'];
    }

    /** @return array<string, mixed> */
    public function transaction(ServerRequestInterface $request, array $params = []): array
    {
        $station = $this->findStation($params['id'] ?? '');
        $body = $this->parseBody($request);

        $this->sender->sendRequest($station, OsppAction::TRANSACTION_EVENT, $body);

        return ['action' => 'transaction_event', 'stationId' => $station->getStationId(), 'status' => 'sent'];
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
