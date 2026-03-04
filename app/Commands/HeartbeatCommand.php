<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\BootstrapsMqtt;
use App\Logging\ColoredConsoleOutput;
use Ospp\Protocol\Actions\OsppAction;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\Loop;

final class HeartbeatCommand extends Command
{
    use BootstrapsMqtt;

    protected $signature = 'heartbeat
        {--mqtt-host= : MQTT broker host}
        {--mqtt-port= : MQTT broker port}
        {--config= : Station config file name}
        {--station-id= : Station ID override}';

    protected $description = 'Boot a station, send one heartbeat, print response';

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
        $booted = false;
        $heartbeatSent = false;
        $heartbeatAck = false;
        $exitCode = 0;

        $station->on('station.stateChanged', function (array $data) use (&$booted, &$heartbeatSent, $station, $orchestrator, $output): void {
            if ($data['lifecycle'] === 'ONLINE' && ! $heartbeatSent) {
                $booted = true;
                $heartbeatSent = true;
                // Stop the auto heartbeat, send one manually
                $orchestrator->getHeartbeatService()->stop($station);
                $orchestrator->getSender()->sendRequest($station, OsppAction::HEARTBEAT, []);
                $output->info("Heartbeat sent for {$station->getStationId()}");
            }
        });

        $station->on('message.received', function (array $data) use (&$heartbeatAck, $output): void {
            if ($data['action'] === OsppAction::HEARTBEAT && $data['messageType'] === 'Response') {
                $heartbeatAck = true;
                $output->info("Heartbeat ACK received");
            }
        });

        $orchestrator->bootStation($station);

        $timeout = $loop->addTimer(15.0, function () use ($loop, &$heartbeatAck, &$exitCode, $output): void {
            if (! $heartbeatAck) {
                $output->error("Timeout: no heartbeat response within 15s");
                $exitCode = 1;
            }
            $loop->stop();
        });

        $loop->addPeriodicTimer(0.1, function () use ($loop, &$heartbeatAck, $timeout): void {
            if ($heartbeatAck) {
                $loop->cancelTimer($timeout);
                $loop->stop();
            }
        });

        $loop->run();
        $orchestrator->shutdown();

        return $exitCode;
    }
}
