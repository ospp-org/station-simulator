<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\ColoredConsoleOutput;
use App\Logging\MessageLogger;
use App\Mqtt\ConnectionMonitor;
use App\Mqtt\MessageReceiver;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\StateMachines\StationLifecycle;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Timers\TimerManager;
use App\Generators\BootPayloadGenerator;
use Ospp\Protocol\ValueObjects\ProtocolVersion;
use React\EventLoop\LoopInterface;

final class SimulatorOrchestrator
{
    /** @var array<string, SimulatedStation> */
    private array $stations = [];

    private readonly TimerManager $timers;
    private readonly StationLifecycle $lifecycle;
    private readonly MqttConnectionManager $mqtt;
    private readonly MessageSender $sender;
    private readonly MessageReceiver $receiver;
    private readonly ConnectionMonitor $monitor;
    private readonly MessageLogger $messageLogger;
    private readonly BootService $bootService;
    private readonly HeartbeatService $heartbeatService;
    private readonly StatusNotificationService $statusService;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ColoredConsoleOutput $output,
        string $mqttHost,
        int $mqttPort,
        bool $tlsEnabled = false,
        string $clientIdPrefix = 'sim',
        string $connectionMode = 'shared',
        int $qos = 1,
        int $keepAlive = 60,
        int $pollIntervalMs = 50,
        ?array $reconnectConfig = null,
        string $protocolVersion = '0.1.0',
    ) {
        ProtocolVersion::setDefaultResolver(fn () => $protocolVersion);

        $this->messageLogger = new MessageLogger();
        $this->timers = new TimerManager($this->loop);
        $this->lifecycle = new StationLifecycle();

        $this->mqtt = new MqttConnectionManager(
            loop: $this->loop,
            output: $this->output,
            host: $mqttHost,
            port: $mqttPort,
            tlsEnabled: $tlsEnabled,
            clientIdPrefix: $clientIdPrefix,
            connectionMode: $connectionMode,
            qos: $qos,
            keepAlive: $keepAlive,
        );

        $this->sender = new MessageSender($this->mqtt, $this->messageLogger);
        $this->receiver = new MessageReceiver($this->messageLogger, $this->output);
        $this->statusService = new StatusNotificationService($this->sender);
        $this->heartbeatService = new HeartbeatService($this->sender, $this->timers, $this->output);
        $this->bootService = new BootService(
            $this->lifecycle,
            new BootPayloadGenerator(),
            $this->sender,
            $this->timers,
            $this->heartbeatService,
            $this->statusService,
            $this->output,
        );

        $reconnectConfig = $reconnectConfig ?? [];
        $this->monitor = new ConnectionMonitor(
            loop: $this->loop,
            mqtt: $this->mqtt,
            output: $this->output,
            initialDelayMs: $reconnectConfig['initial_delay_ms'] ?? 1000,
            maxDelayMs: $reconnectConfig['max_delay_ms'] ?? 30000,
            multiplier: $reconnectConfig['multiplier'] ?? 2.0,
            jitterPercent: $reconnectConfig['jitter_percent'] ?? 30,
        );

        // Set up command router
        $router = CommandRouterFactory::create(
            $this->sender, $this->timers, $this->lifecycle,
            $this->bootService, $this->heartbeatService, $this->output,
        );
        $this->receiver->setCommandRouter(fn (SimulatedStation $station, $envelope) => $router->route($station, $envelope));

        // Set up MQTT polling
        $this->loop->addPeriodicTimer($pollIntervalMs / 1000, function (): void {
            $this->mqtt->pollOnce();
        });
    }

    public function createStations(StationConfig $config, int $count, ?string $stationIdOverride = null): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $stationId = ($stationIdOverride !== null && $count === 1) ? $stationIdOverride : null;
            $station = SimulatedStation::create($config, $i, $stationId);
            $this->stations[$station->getStationId()] = $station;
            $this->output->boot("Created station {$station->getStationId()} ({$config->getBayCount()} bays)");
        }

        $this->receiver->setStations($this->stations);
    }

    public function connect(): void
    {
        $this->mqtt->connect(
            $this->stations,
            fn (string $stationId, string $message) => $this->receiver->handleMessage($stationId, $message),
        );

        foreach ($this->stations as $station) {
            $this->monitor->markConnected($station->getStationId());
        }
    }

    public function bootAllStations(): void
    {
        foreach ($this->stations as $station) {
            $this->bootService->boot($station);
        }
    }

    public function bootStation(SimulatedStation $station, string $bootReason = \App\Station\BootReason::POWER_ON): bool
    {
        return $this->bootService->boot($station, $bootReason);
    }

    public function shutdown(): void
    {
        $this->output->info("Shutting down...");
        $this->timers->cancelAll();
        $this->mqtt->disconnect();
        $this->loop->stop();
        $this->output->info("Shutdown complete.");
    }

    /** @return array<string, SimulatedStation> */
    public function getStations(): array
    {
        return $this->stations;
    }

    public function getStation(string $stationId): ?SimulatedStation
    {
        return $this->stations[$stationId] ?? null;
    }

    public function getSender(): MessageSender
    {
        return $this->sender;
    }

    public function getMqtt(): MqttConnectionManager
    {
        return $this->mqtt;
    }

    public function getTimers(): TimerManager
    {
        return $this->timers;
    }

    public function getMessageLogger(): MessageLogger
    {
        return $this->messageLogger;
    }

    public function getReceiver(): MessageReceiver
    {
        return $this->receiver;
    }

    public function getBootService(): BootService
    {
        return $this->bootService;
    }

    public function getHeartbeatService(): HeartbeatService
    {
        return $this->heartbeatService;
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}
