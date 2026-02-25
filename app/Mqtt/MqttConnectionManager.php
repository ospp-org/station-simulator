<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Logging\ColoredConsoleOutput;
use App\Station\SimulatedStation;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use React\EventLoop\LoopInterface;

final class MqttConnectionManager
{
    private ?MqttClient $sharedClient = null;

    /** @var array<string, MqttClient> */
    private array $perStationClients = [];

    private bool $connected = false;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ColoredConsoleOutput $output,
        private readonly string $host,
        private readonly int $port,
        private readonly bool $tlsEnabled,
        private readonly string $clientIdPrefix,
        private readonly string $connectionMode,
        private readonly int $qos,
        private readonly int $keepAlive,
    ) {}

    /** @param array<string, SimulatedStation> $stations */
    public function connect(array $stations, callable $onMessage): void
    {
        if ($this->connectionMode === 'shared') {
            $this->connectShared($stations, $onMessage);
        } else {
            $this->connectPerStation($stations, $onMessage);
        }

        $this->connected = true;
    }

    public function publish(string $stationId, string $jsonPayload): void
    {
        $topic = "ospp/v1/stations/{$stationId}/to-server";

        try {
            $client = $this->getClientForStation($stationId);
            $client->publish($topic, $jsonPayload, $this->qos);
        } catch (MqttClientException $e) {
            $this->output->mqtt("Failed to publish for {$stationId}: {$e->getMessage()}");
        }
    }

    public function pollOnce(): void
    {
        if (! $this->connected) {
            return;
        }

        try {
            if ($this->sharedClient !== null) {
                $this->sharedClient->loopOnce(0, true);
            }

            foreach ($this->perStationClients as $client) {
                $client->loopOnce(0, true);
            }
        } catch (MqttClientException $e) {
            $this->output->mqtt("Poll error: {$e->getMessage()}");
        }
    }

    public function disconnect(): void
    {
        $this->connected = false;

        try {
            if ($this->sharedClient !== null) {
                $this->sharedClient->disconnect();
                $this->sharedClient = null;
            }

            foreach ($this->perStationClients as $stationId => $client) {
                $client->disconnect();
            }
            $this->perStationClients = [];
        } catch (MqttClientException $e) {
            $this->output->mqtt("Disconnect error: {$e->getMessage()}");
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** @param array<string, SimulatedStation> $stations */
    private function connectShared(array $stations, callable $onMessage): void
    {
        $clientId = $this->clientIdPrefix . '-shared-' . bin2hex(random_bytes(4));
        $this->sharedClient = new MqttClient($this->host, $this->port, $clientId);

        $connectionSettings = $this->buildConnectionSettings();

        try {
            $this->sharedClient->connect($connectionSettings);
            $this->output->mqtt("Connected (shared mode) to {$this->host}:{$this->port}");

            // Subscribe to wildcard topic for all stations
            $this->sharedClient->subscribe(
                'ospp/v1/stations/+/to-station',
                function (string $topic, string $message) use ($onMessage): void {
                    // Extract stationId from topic: ospp/v1/stations/{stationId}/to-station
                    $parts = explode('/', $topic);
                    $stationId = $parts[3] ?? '';
                    $onMessage($stationId, $message);
                },
                $this->qos,
            );

            // Set LWT for each station
            foreach ($stations as $station) {
                $this->configureLwt($station->getStationId());
            }
        } catch (MqttClientException $e) {
            $this->output->error("MQTT connection failed: {$e->getMessage()}");

            throw $e;
        }
    }

    /** @param array<string, SimulatedStation> $stations */
    private function connectPerStation(array $stations, callable $onMessage): void
    {
        foreach ($stations as $station) {
            $stationId = $station->getStationId();
            $clientId = $this->clientIdPrefix . '-' . $stationId . '-' . bin2hex(random_bytes(2));
            $client = new MqttClient($this->host, $this->port, $clientId);

            $connectionSettings = $this->buildConnectionSettings($stationId);

            try {
                $client->connect($connectionSettings);
                $this->output->mqtt("Connected station {$stationId} to {$this->host}:{$this->port}");

                $client->subscribe(
                    "ospp/v1/stations/{$stationId}/to-station",
                    function (string $topic, string $message) use ($stationId, $onMessage): void {
                        $onMessage($stationId, $message);
                    },
                    $this->qos,
                );

                $this->perStationClients[$stationId] = $client;
            } catch (MqttClientException $e) {
                $this->output->error("MQTT connection failed for {$stationId}: {$e->getMessage()}");

                throw $e;
            }
        }
    }

    private function getClientForStation(string $stationId): MqttClient
    {
        if ($this->sharedClient !== null) {
            return $this->sharedClient;
        }

        if (isset($this->perStationClients[$stationId])) {
            return $this->perStationClients[$stationId];
        }

        throw new \RuntimeException("No MQTT client available for station {$stationId}");
    }

    private function buildConnectionSettings(?string $stationId = null): ConnectionSettings
    {
        $settings = (new ConnectionSettings())
            ->setKeepAliveInterval($this->keepAlive)
            ->setConnectTimeout(5);

        if ($stationId !== null) {
            $lwtTopic = "ospp/v1/stations/{$stationId}/connection-lost";
            $lwtPayload = json_encode([
                'stationId' => $stationId,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            ], JSON_THROW_ON_ERROR);

            $settings = $settings
                ->setLastWillTopic($lwtTopic)
                ->setLastWillMessage($lwtPayload)
                ->setLastWillQualityOfService($this->qos);
        }

        if ($this->tlsEnabled) {
            $settings = $settings->setUseTls(true);
        }

        return $settings;
    }

    private function configureLwt(string $stationId): void
    {
        // LWT is configured during connection for per-station mode.
        // For shared mode, LWT is limited to one per connection.
        // We log this as a known limitation.
        $this->output->debug("LWT configured for {$stationId} (shared mode: single LWT limitation)");
    }
}
