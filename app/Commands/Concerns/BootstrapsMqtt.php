<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Logging\ColoredConsoleOutput;
use App\Services\SimulatorOrchestrator;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use React\EventLoop\LoopInterface;

trait BootstrapsMqtt
{
    private function createOrchestrator(LoopInterface $loop, ColoredConsoleOutput $output): SimulatorOrchestrator
    {
        $mqttHost = $this->option('mqtt-host') ?? config('mqtt.host', 'localhost');
        $mqttPort = (int) ($this->option('mqtt-port') ?? config('mqtt.port', 1883));

        return new SimulatorOrchestrator(
            loop: $loop,
            output: $output,
            mqttHost: $mqttHost,
            mqttPort: $mqttPort,
        );
    }

    private function loadStationConfig(): StationConfig
    {
        $configName = $this->option('config') ?? config('simulator.station_config', 'default');
        $configPath = base_path("config/stations/{$configName}.yaml");

        return StationConfig::fromYamlFile($configPath);
    }

    private function resolveStation(SimulatorOrchestrator $orchestrator): SimulatedStation
    {
        $stations = $orchestrator->getStations();

        return reset($stations);
    }
}
