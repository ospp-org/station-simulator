<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\BootstrapsMqtt;
use App\Logging\ColoredConsoleOutput;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\Loop;

final class BootCommand extends Command
{
    use BootstrapsMqtt;

    protected $signature = 'boot
        {--mqtt-host= : MQTT broker host}
        {--mqtt-port= : MQTT broker port}
        {--config= : Station config file name}
        {--station-id= : Station ID override}';

    protected $description = 'Connect and boot a single station, print boot response';

    public function handle(): int
    {
        $loop = Loop::get();
        $output = new ColoredConsoleOutput($this->output);
        $orchestrator = $this->createOrchestrator($loop, $output);

        $stationConfig = $this->loadStationConfig();
        $orchestrator->createStations($stationConfig, 1, $this->option('station-id'));
        $station = $this->resolveStation($orchestrator);

        try {
            $orchestrator->connect();
        } catch (\Throwable $e) {
            $output->error("MQTT connection failed: {$e->getMessage()}");

            return 1;
        }
        $responded = false;
        $exitCode = 0;

        $station->on('station.stateChanged', function (array $data) use (&$responded, $station, $output): void {
            if ($data['lifecycle'] === 'ONLINE') {
                $responded = true;
                $output->info("Boot successful for {$station->getStationId()}");
                $output->info("  Session key: " . ($station->state->sessionKey ? 'present' : 'none'));
                $output->info("  Heartbeat interval: {$station->state->heartbeatInterval}s");
            }
        });

        $orchestrator->bootStation($station);

        // Run loop with 10s timeout
        $timeout = $loop->addTimer(10.0, function () use ($loop, &$responded, &$exitCode, $output): void {
            if (! $responded) {
                $output->error("Boot timeout: no response within 10s");
                $exitCode = 1;
            }
            $loop->stop();
        });

        $loop->addPeriodicTimer(0.1, function () use ($loop, &$responded, $timeout): void {
            if ($responded) {
                $loop->cancelTimer($timeout);
                $loop->stop();
            }
        });

        $loop->run();
        $orchestrator->shutdown();

        return $exitCode;
    }
}
