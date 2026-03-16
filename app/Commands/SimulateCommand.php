<?php

declare(strict_types=1);

namespace App\Commands;

use App\Logging\ColoredConsoleOutput;
use App\Scenarios\Results\JsonExporter;
use App\Scenarios\Results\JunitXmlExporter;
use App\Scenarios\Results\ResultStore;
use App\Scenarios\ScenarioContext;
use App\Scenarios\ScenarioParser;
use App\Scenarios\ScenarioRunner;
use App\Scenarios\StepExecutor;
use App\Services\SimulatorOrchestrator;
use App\Station\StationConfig;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\Loop;

final class SimulateCommand extends Command
{
    protected $signature = 'simulate
        {--stations= : Number of stations to simulate}
        {--auto-boot : Auto-boot stations on startup}
        {--mqtt-host= : MQTT broker host}
        {--mqtt-port= : MQTT broker port}
        {--config= : Station config file name (without .yaml)}
        {--station-id= : Override station ID}
        {--scenario= : Run a specific scenario (e.g. core/happy-boot)}
        {--headless : Run scenario without interactive output, exit when done}
        {--fail-fast : Stop scenario on first failure}
        {--junit-output= : Write JUnit XML results to file}
        {--json-output= : Write JSON results to file}
        {--timeout= : Override scenario timeout (seconds)}
        {--exit-code : Return non-zero exit code on scenario failure}';

    protected $description = 'Start the OSPP station simulator';

    private ?SimulatorOrchestrator $orchestrator = null;
    private ?ColoredConsoleOutput $console = null;
    private ?ResultStore $resultStore = null;
    private ?\React\EventLoop\LoopInterface $loop = null;

    public function handle(): int
    {
        $scenarioMode = $this->option('scenario') !== null;
        $stationCount = (int) ($this->option('stations') ?? ($scenarioMode ? 1 : config('simulator.stations', 1)));
        $autoBoot = $this->option('auto-boot') || (! $scenarioMode && config('simulator.auto_boot', true));
        $mqttHost = $this->option('mqtt-host') ?? config('mqtt.host', 'localhost');
        $mqttPort = (int) ($this->option('mqtt-port') ?? config('mqtt.port', 1883));
        $configName = $this->option('config') ?? config('simulator.station_config', 'default');

        $loop = $this->loop = Loop::get();

        $this->console = new ColoredConsoleOutput($this->output);
        $this->console->setLogLevel(config('simulator.log_level', 'info'));

        $this->console->info("OSPP Station Simulator v0.2.0");
        $this->console->info("Stations: {$stationCount} | MQTT: {$mqttHost}:{$mqttPort} | Auto-boot: " . ($autoBoot ? 'yes' : 'no'));

        $configPath = base_path("config/stations/{$configName}.yaml");
        if (! file_exists($configPath)) {
            $this->console->error("Station config not found: {$configPath}");

            return 1;
        }
        $stationConfig = StationConfig::fromYamlFile($configPath);

        $this->orchestrator = new SimulatorOrchestrator(
            loop: $loop,
            output: $this->console,
            mqttHost: $mqttHost,
            mqttPort: $mqttPort,
            tlsEnabled: (bool) config('mqtt.tls_enabled', false),
            clientIdPrefix: config('mqtt.client_id_prefix', 'sim'),
            connectionMode: config('mqtt.connection_mode', 'shared'),
            qos: (int) config('mqtt.qos', 1),
            keepAlive: (int) config('mqtt.keep_alive', 60),
            username: (string) config('mqtt.username', ''),
            password: (string) config('mqtt.password', ''),
            pollIntervalMs: (int) config('simulator.mqtt_poll_interval_ms', 50),
            reconnectConfig: config('mqtt.reconnect', []),
        );

        $overrideStationId = $this->option('station-id');
        $this->orchestrator->createStations($stationConfig, $stationCount, $overrideStationId);

        try {
            $this->orchestrator->connect();
        } catch (\Throwable $e) {
            $this->console->error("Failed to connect to MQTT: {$e->getMessage()}");

            return 1;
        }

        if ($autoBoot) {
            $this->orchestrator->bootAllStations();
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
                $this->orchestrator->shutdown();
                $exitCode = $this->option('exit-code') ? $scenarioExitCode : 0;

                // Force exit — ReactPHP event loop streams prevent clean shutdown
                exit($exitCode);
            }
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            $shutdown = fn () => $this->orchestrator->shutdown();
            $loop->addSignal(SIGTERM, $shutdown);
            $loop->addSignal(SIGINT, $shutdown);
        }

        $this->console->info("Event loop started. Press Ctrl+C to stop.");
        $loop->run();

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
                config: $scenario->config,
            );
        }

        $context = new ScenarioContext(
            sender: $this->orchestrator->getSender(),
            mqtt: $this->orchestrator->getMqtt(),
            timers: $this->orchestrator->getTimers(),
            logger: $this->orchestrator->getMessageLogger(),
            loop: $this->loop,
        );
        $context->stations = $this->orchestrator->getStations();

        // Wire received messages into scenario context
        $this->orchestrator->getReceiver()->setOnMessageCallback(
            fn (\Ospp\Protocol\Envelope\MessageEnvelope $envelope) => $context->addReceivedMessage($envelope),
        );

        // Wire sent (outbound) messages into scenario context so WaitForStep can find
        // station-originated events (DiagnosticsNotification, FirmwareStatusNotification, etc.)
        $this->orchestrator->getSender()->setOnSentCallback(
            fn (\Ospp\Protocol\Envelope\MessageEnvelope $envelope) => $context->addReceivedMessage($envelope),
        );

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
        $apiCallStep = new \App\Scenarios\StepTypes\ApiCallStep();

        $executor = new StepExecutor(
            $sendStep, $waitForStep, $assertStep, $delayStep,
            $parallelStep, $repeatStep, $setBehaviorStep, $disconnectStep, $faultStep,
            $apiCallStep,
        );

        $subExecutor = fn (array $stepConfig, ScenarioContext $ctx): bool =>
            $executor->execute($stepConfig, $ctx)->passed();
        $parallelStep->setExecutor($subExecutor);
        $repeatStep->setExecutor($subExecutor);

        $runner = new ScenarioRunner($executor, $this->console);
        $result = $runner->run($scenario, $context, $failFast);

        $this->resultStore = $this->resultStore ?? new ResultStore();
        $this->resultStore->store($result);

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
}
