<?php

declare(strict_types=1);

namespace App\Commands;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Generators\BootPayloadGenerator;
use App\Generators\MeterValueGenerator;
use App\Handlers\CancelReservationHandler;
use App\Handlers\ChangeConfigurationHandler;
use App\Handlers\GetConfigurationHandler;
use App\Handlers\GetDiagnosticsHandler;
use App\Handlers\IncomingCommandRouter;
use App\Handlers\ResetHandler;
use App\Handlers\ReserveBayHandler;
use App\Handlers\SetMaintenanceModeHandler;
use App\Handlers\StartServiceHandler;
use App\Handlers\StopServiceHandler;
use App\Handlers\UpdateFirmwareHandler;
use App\Handlers\UpdateServiceCatalogHandler;
use App\Logging\ColoredConsoleOutput;
use App\Logging\MessageLogger;
use App\Mqtt\ConnectionMonitor;
use App\Mqtt\MessageReceiver;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\Scenarios\Results\JsonExporter;
use App\Scenarios\Results\JunitXmlExporter;
use App\Scenarios\Results\ResultStore;
use App\Scenarios\ScenarioContext;
use App\Scenarios\ScenarioParser;
use App\Scenarios\ScenarioRunner;
use App\Scenarios\StepExecutor;
use App\StateMachines\SimulatedBayFSM;
use App\StateMachines\SimulatedDiagnosticsFSM;
use App\StateMachines\SimulatedFirmwareFSM;
use App\StateMachines\SimulatedSessionFSM;
use App\StateMachines\StationLifecycle;
use App\Station\BootReason;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationState;
use App\Timers\TimerManager;
use LaravelZero\Framework\Commands\Command;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Enums\MessageType;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class SimulateCommand extends Command
{
    protected $signature = 'simulate
        {--stations= : Number of stations to simulate}
        {--auto-boot : Auto-boot stations on startup}
        {--mqtt-host= : MQTT broker host}
        {--mqtt-port= : MQTT broker port}
        {--config= : Station config file name (without .yaml)}
        {--scenario= : Run a specific scenario (e.g. core/happy-boot)}
        {--headless : Run scenario without interactive output, exit when done}
        {--fail-fast : Stop scenario on first failure}
        {--junit-output= : Write JUnit XML results to file}
        {--json-output= : Write JSON results to file}
        {--timeout= : Override scenario timeout (seconds)}
        {--exit-code : Return non-zero exit code on scenario failure}
        {--verbose}';

    protected $description = 'Start the OSPP station simulator';

    /** @var array<string, SimulatedStation> */
    private array $stations = [];

    private ?LoopInterface $loop = null;
    private ?MqttConnectionManager $mqtt = null;
    private ?MessageSender $sender = null;
    private ?MessageReceiver $receiver = null;
    private ?ConnectionMonitor $monitor = null;
    private ?TimerManager $timers = null;
    private ?MessageLogger $messageLogger = null;
    private ?ColoredConsoleOutput $console = null;
    private ?StationLifecycle $lifecycle = null;
    private ?BootPayloadGenerator $bootGenerator = null;
    private ?IncomingCommandRouter $router = null;
    private ?ResultStore $resultStore = null;

    public function handle(): int
    {
        // Resolve configuration
        $stationCount = (int) ($this->option('stations') ?? config('simulator.stations', 1));
        $autoBoot = $this->option('auto-boot') || config('simulator.auto_boot', true);
        $mqttHost = $this->option('mqtt-host') ?? config('mqtt.host', 'localhost');
        $mqttPort = (int) ($this->option('mqtt-port') ?? config('mqtt.port', 1883));
        $configName = $this->option('config') ?? config('simulator.station_config', 'default');
        $pollIntervalMs = (int) config('simulator.mqtt_poll_interval_ms', 50);

        // Create ReactPHP event loop (single instance for the process)
        $this->loop = Loop::get();

        // Create logging
        $this->console = new ColoredConsoleOutput($this->output);
        $this->console->setLogLevel(config('simulator.log_level', 'info'));
        $this->messageLogger = new MessageLogger();

        $this->console->info("OSPP Station Simulator v0.1.0");
        $this->console->info("Stations: {$stationCount} | MQTT: {$mqttHost}:{$mqttPort} | Auto-boot: " . ($autoBoot ? 'yes' : 'no'));

        // Load station config
        $configPath = base_path("config/stations/{$configName}.yaml");
        if (! file_exists($configPath)) {
            $this->console->error("Station config not found: {$configPath}");

            return 1;
        }
        $stationConfig = StationConfig::fromYamlFile($configPath);

        // Create core services (manual DI wiring)
        $this->timers = new TimerManager($this->loop);
        $this->lifecycle = new StationLifecycle();
        $this->bootGenerator = new BootPayloadGenerator();

        // Create stations
        for ($i = 1; $i <= $stationCount; $i++) {
            $station = SimulatedStation::create($stationConfig, $i);
            $this->stations[$station->getStationId()] = $station;
            $this->console->boot("Created station {$station->getStationId()} ({$stationConfig->getBayCount()} bays)");
        }

        // Create MQTT connection manager
        $this->mqtt = new MqttConnectionManager(
            loop: $this->loop,
            output: $this->console,
            host: $mqttHost,
            port: $mqttPort,
            tlsEnabled: (bool) config('mqtt.tls_enabled', false),
            clientIdPrefix: config('mqtt.client_id_prefix', 'sim'),
            connectionMode: config('mqtt.connection_mode', 'shared'),
            qos: (int) config('mqtt.qos', 1),
            keepAlive: (int) config('mqtt.keep_alive', 60),
        );

        // Create message sender/receiver
        $this->sender = new MessageSender($this->mqtt, $this->messageLogger);
        $this->receiver = new MessageReceiver($this->messageLogger, $this->console);
        $this->receiver->setStations($this->stations);

        // Create connection monitor
        $reconnectConfig = config('mqtt.reconnect', []);
        $this->monitor = new ConnectionMonitor(
            loop: $this->loop,
            mqtt: $this->mqtt,
            output: $this->console,
            initialDelayMs: $reconnectConfig['initial_delay_ms'] ?? 1000,
            maxDelayMs: $reconnectConfig['max_delay_ms'] ?? 30000,
            multiplier: $reconnectConfig['multiplier'] ?? 2.0,
            jitterPercent: $reconnectConfig['jitter_percent'] ?? 30,
        );

        // Create and register command handlers
        $this->setupCommandRouter();

        // Connect to MQTT
        try {
            $this->mqtt->connect(
                $this->stations,
                fn (string $stationId, string $message) => $this->receiver->handleMessage($stationId, $message),
            );

            foreach ($this->stations as $station) {
                $this->monitor->markConnected($station->getStationId());
            }
        } catch (\Throwable $e) {
            $this->console->error("Failed to connect to MQTT: {$e->getMessage()}");

            return 1;
        }

        // Set up MQTT polling timer (loopOnce integration)
        $this->loop->addPeriodicTimer($pollIntervalMs / 1000, function (): void {
            $this->mqtt?->pollOnce();
        });

        // Auto-boot stations
        if ($autoBoot) {
            foreach ($this->stations as $station) {
                $this->bootStation($station);
            }
        }

        // Scenario mode
        $scenarioName = $this->option('scenario');
        $scenarioExitCode = 0;

        if ($scenarioName !== null) {
            $scenarioExitCode = $this->runScenario(
                scenarioName: $scenarioName,
                failFast: (bool) $this->option('fail-fast'),
                timeoutOverride: $this->option('timeout') ? (int) $this->option('timeout') : null,
            );

            if ($this->option('headless')) {
                $this->shutdown();

                return $this->option('exit-code') ? $scenarioExitCode : 0;
            }
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            $shutdown = function (): void {
                $this->shutdown();
            };
            $this->loop->addSignal(SIGTERM, $shutdown);
            $this->loop->addSignal(SIGINT, $shutdown);
        }

        $this->console->info("Event loop started. Press Ctrl+C to stop.");

        // Run the event loop (blocks until stopped)
        $this->loop->run();

        return $this->option('exit-code') ? $scenarioExitCode : 0;
    }

    private function runScenario(string $scenarioName, bool $failFast, ?int $timeoutOverride): int
    {
        $scenarioPath = base_path("scenarios/{$scenarioName}.yaml");

        if (! file_exists($scenarioPath)) {
            $this->console->error("Scenario not found: {$scenarioPath}");

            return 1;
        }

        $parser = new ScenarioParser();
        $scenario = $parser->parseFile($scenarioPath);

        if ($timeoutOverride !== null) {
            $scenario = new \App\Scenarios\ScenarioDefinition(
                name: $scenario->name,
                description: $scenario->description,
                stationCount: $scenario->stationCount,
                steps: $scenario->steps,
                tags: $scenario->tags,
                timeoutSeconds: $timeoutOverride,
            );
        }

        // Build context
        $context = new ScenarioContext(
            sender: $this->sender,
            mqtt: $this->mqtt,
            timers: $this->timers,
            logger: $this->messageLogger,
        );
        $context->stations = $this->stations;

        // Run
        $assertionRunner = new \App\Scenarios\Assertions\AssertionRunner();
        $sendStep = new \App\Scenarios\StepTypes\SendStep();
        $waitForStep = new \App\Scenarios\StepTypes\WaitForStep();
        $assertStep = new \App\Scenarios\StepTypes\AssertStep($assertionRunner);
        $delayStep = new \App\Scenarios\StepTypes\DelayStep();
        $parallelStep = new \App\Scenarios\StepTypes\ParallelStep();
        $repeatStep = new \App\Scenarios\StepTypes\RepeatStep();
        $setBehaviorStep = new \App\Scenarios\StepTypes\SetBehaviorStep();
        $disconnectStep = new \App\Scenarios\StepTypes\DisconnectStep();
        $faultStep = new \App\Scenarios\StepTypes\FaultStep();

        $executor = new StepExecutor(
            $sendStep, $waitForStep, $assertStep, $delayStep,
            $parallelStep, $repeatStep, $setBehaviorStep, $disconnectStep, $faultStep,
        );

        // Wire sub-step executor for compound steps
        $subExecutor = fn (array $stepConfig, ScenarioContext $ctx): bool =>
            $executor->execute($stepConfig, $ctx)->passed();
        $parallelStep->setExecutor($subExecutor);
        $repeatStep->setExecutor($subExecutor);

        $runner = new ScenarioRunner($executor, $this->console);
        $result = $runner->run($scenario, $context, $failFast);

        // Store result
        $this->resultStore = $this->resultStore ?? new ResultStore();
        $this->resultStore->store($result);

        // Export results
        $junitPath = $this->option('junit-output');
        if ($junitPath !== null) {
            $xml = (new JunitXmlExporter())->export($result);
            file_put_contents($junitPath, $xml);
            $this->console->info("JUnit XML written to: {$junitPath}");
        }

        $jsonPath = $this->option('json-output');
        if ($jsonPath !== null) {
            $json = (new JsonExporter())->export($result);
            file_put_contents($jsonPath, $json);
            $this->console->info("JSON results written to: {$jsonPath}");
        }

        return $result->passed() ? 0 : 1;
    }

    private function bootStation(SimulatedStation $station): void
    {
        $stationId = $station->getStationId();

        // Transition OFFLINE → BOOTING
        if (! $this->lifecycle->transition($station->state, StationState::LIFECYCLE_BOOTING)) {
            $this->console->error("Cannot boot station {$stationId} from state {$station->state->lifecycle}");

            return;
        }

        $station->state->bootReason = BootReason::POWER_ON;
        $this->console->boot("Booting station {$stationId}...");

        // Send BootNotification REQUEST
        $payload = $this->bootGenerator->generate($station);
        $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

        // Set boot response timeout (5s) — retry with backoff if no response
        $this->timers->addTimer("boot-timeout:{$stationId}", 5.0, function () use ($station): void {
            $this->handleBootTimeout($station, 0);
        });

        $station->emit('station.stateChanged', [
            'lifecycle' => $station->state->lifecycle,
        ]);
    }

    private function handleBootResponse(SimulatedStation $station, array $payload): void
    {
        $stationId = $station->getStationId();
        $this->timers->cancelTimer("boot-timeout:{$stationId}");
        $this->timers->cancelTimer("boot-retry:{$stationId}");

        $status = $payload['status'] ?? '';

        if ($status === 'Accepted') {
            // Extract session key and heartbeat interval
            $station->state->sessionKey = $payload['sessionKey'] ?? null;
            $station->state->heartbeatInterval = (int) ($payload['heartbeatInterval'] ?? 30);

            // Transition BOOTING → ONLINE
            $this->lifecycle->transition($station->state, StationState::LIFECYCLE_ONLINE);
            $this->console->boot("Station {$stationId} ONLINE (heartbeat: {$station->state->heartbeatInterval}s)");

            // Send StatusNotification for each bay (UNKNOWN → Available)
            foreach ($station->getBays() as $bay) {
                $bay->transitionTo(BayStatus::AVAILABLE);
                $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
                    'stationId' => $stationId,
                    'bayId' => $bay->bayId,
                    'bayNumber' => $bay->bayNumber,
                    'status' => BayStatus::AVAILABLE->toOspp(),
                    'services' => $bay->services,
                    'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
                ]);
            }

            // Start heartbeat timer
            $this->startHeartbeat($station);

            $station->emit('station.stateChanged', [
                'lifecycle' => StationState::LIFECYCLE_ONLINE,
            ]);
        } elseif ($status === 'Rejected') {
            $retryInterval = (int) ($payload['retryInterval'] ?? 60);
            $this->console->boot("Station {$stationId} boot REJECTED. Retry in {$retryInterval}s");

            $this->timers->addTimer("boot-retry:{$stationId}", (float) $retryInterval, function () use ($station): void {
                // Re-send BootNotification
                $payload = $this->bootGenerator->generate($station);
                $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

                $this->timers->addTimer("boot-timeout:{$station->getStationId()}", 5.0, function () use ($station): void {
                    $this->handleBootTimeout($station, 0);
                });
            });
        }
    }

    private function handleBootTimeout(SimulatedStation $station, int $attempt): void
    {
        $stationId = $station->getStationId();
        $delay = min(1000 * (2 ** $attempt), 30000);
        $jitter = (int) ($delay * 0.3);
        $delay += random_int(-$jitter, $jitter);
        $delay = max(1000, $delay);

        $this->console->boot("Boot timeout for {$stationId}. Retry in {$delay}ms (attempt #{$attempt})");

        $this->timers->addTimer("boot-retry:{$stationId}", $delay / 1000, function () use ($station, $attempt): void {
            $payload = $this->bootGenerator->generate($station);
            $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

            $this->timers->addTimer("boot-timeout:{$station->getStationId()}", 5.0, function () use ($station, $attempt): void {
                $this->handleBootTimeout($station, $attempt + 1);
            });
        });
    }

    private function startHeartbeat(SimulatedStation $station): void
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

    private function handleHeartbeatResponse(SimulatedStation $station, array $payload): void
    {
        $serverTime = $payload['currentTime'] ?? null;
        if ($serverTime !== null) {
            $this->console->heartbeat("Heartbeat ACK for {$station->getStationId()} (server: {$serverTime})");
        }
    }

    private function setupCommandRouter(): void
    {
        $bayFSM = new SimulatedBayFSM();
        $sessionFSM = new SimulatedSessionFSM();
        $decider = new ResponseDecider();
        $delay = new DelaySimulator($this->loop);
        $meterGenerator = new MeterValueGenerator();

        $this->router = new IncomingCommandRouter($this->console);

        // Register handlers for server→station commands
        $this->router->registerHandler(
            OsppAction::START_SERVICE,
            new StartServiceHandler(
                $this->sender, $bayFSM, $sessionFSM, $decider, $delay,
                $this->timers, $meterGenerator, $this->console,
            ),
        );

        $this->router->registerHandler(
            OsppAction::STOP_SERVICE,
            new StopServiceHandler(
                $this->sender, $bayFSM, $sessionFSM, $decider, $delay,
                $this->timers, $meterGenerator, $this->console,
            ),
        );

        $this->router->registerHandler(
            OsppAction::RESERVE_BAY,
            new ReserveBayHandler(
                $this->sender, $bayFSM, $decider, $delay,
                $this->timers, $this->console,
            ),
        );

        $this->router->registerHandler(
            OsppAction::CANCEL_RESERVATION,
            new CancelReservationHandler(
                $this->sender, $bayFSM, $delay,
                $this->timers, $this->console,
            ),
        );

        // Phase 5: Device management handlers
        $firmwareFSM = new SimulatedFirmwareFSM($this->sender, $this->timers, $this->console);
        $diagnosticsFSM = new SimulatedDiagnosticsFSM($this->sender, $this->timers, $this->console);

        $this->router->registerHandler(
            OsppAction::GET_CONFIGURATION,
            new GetConfigurationHandler($this->sender, $decider, $delay, $this->console),
        );

        $this->router->registerHandler(
            OsppAction::CHANGE_CONFIGURATION,
            new ChangeConfigurationHandler($this->sender, $decider, $delay, $this->console),
        );

        $this->router->registerHandler(
            OsppAction::UPDATE_FIRMWARE,
            new UpdateFirmwareHandler($this->sender, $firmwareFSM, $decider, $delay, $this->console),
        );

        $this->router->registerHandler(
            OsppAction::GET_DIAGNOSTICS,
            new GetDiagnosticsHandler($this->sender, $diagnosticsFSM, $decider, $delay, $this->console),
        );

        $this->router->registerHandler(
            OsppAction::RESET,
            new ResetHandler(
                $this->sender, $this->lifecycle, $decider, $delay,
                $this->timers, $this->console,
                fn (SimulatedStation $station) => $this->bootStation($station),
            ),
        );

        $this->router->registerHandler(
            OsppAction::SET_MAINTENANCE_MODE,
            new SetMaintenanceModeHandler($this->sender, $decider, $delay, $this->console),
        );

        $this->router->registerHandler(
            OsppAction::UPDATE_SERVICE_CATALOG,
            new UpdateServiceCatalogHandler($this->sender, $decider, $delay, $this->console),
        );

        // Handle boot and heartbeat responses inline (they're responses, not commands)
        $this->router->registerHandler(OsppAction::BOOT_NOTIFICATION, function (SimulatedStation $station, $envelope): void {
            if ($envelope->messageType === MessageType::RESPONSE) {
                $this->handleBootResponse($station, $envelope->payload);
            }
        });

        $this->router->registerHandler(OsppAction::HEARTBEAT, function (SimulatedStation $station, $envelope): void {
            if ($envelope->messageType === MessageType::RESPONSE) {
                $this->handleHeartbeatResponse($station, $envelope->payload);
            }
        });

        // Set command router on receiver
        $this->receiver->setCommandRouter(fn (SimulatedStation $station, $envelope) => $this->router->route($station, $envelope));
    }

    private function shutdown(): void
    {
        $this->console?->info("Shutting down...");

        // 1. Cancel all timers
        $this->timers?->cancelAll();

        // 2. Disconnect MQTT
        $this->mqtt?->disconnect();

        // 3. Stop event loop
        $this->loop?->stop();

        $this->console?->info("Shutdown complete.");
    }
}
