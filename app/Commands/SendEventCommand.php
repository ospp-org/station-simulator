<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\BootstrapsMqtt;
use App\Logging\ColoredConsoleOutput;
use App\Services\StatusNotificationService;
use Ospp\Protocol\Actions\OsppAction;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\Loop;

final class SendEventCommand extends Command
{
    use BootstrapsMqtt;

    protected $signature = 'send-event
        {type : Event type: StatusNotification, SecurityEvent, DiagnosticsNotification, FirmwareStatusNotification}
        {--mqtt-host= : MQTT broker host}
        {--mqtt-port= : MQTT broker port}
        {--config= : Station config file name}
        {--station-id= : Station ID override}
        {--bay-id= : Bay ID for bay-specific events}';

    protected $description = 'Boot a station and send a specific event';

    private const SUPPORTED_EVENTS = [
        'StatusNotification' => OsppAction::STATUS_NOTIFICATION,
        'SecurityEvent' => OsppAction::SECURITY_EVENT,
        'DiagnosticsNotification' => OsppAction::DIAGNOSTICS_NOTIFICATION,
        'FirmwareStatusNotification' => OsppAction::FIRMWARE_STATUS_NOTIFICATION,
    ];

    public function handle(): int
    {
        $type = $this->argument('type');

        if (! isset(self::SUPPORTED_EVENTS[$type])) {
            $this->error("Unknown event type: {$type}");
            $this->line('Supported: ' . implode(', ', array_keys(self::SUPPORTED_EVENTS)));

            return 1;
        }

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
        $eventSent = false;

        $station->on('station.stateChanged', function (array $data) use (&$booted, &$eventSent, $station, $orchestrator, $output, $type): void {
            if ($data['lifecycle'] === 'ONLINE' && ! $eventSent) {
                $booted = true;
                $eventSent = true;
                $orchestrator->getHeartbeatService()->stop($station);

                $this->sendEvent($station, $orchestrator, $output, $type);
                $output->info("Event {$type} sent for {$station->getStationId()}");
            }
        });

        $orchestrator->bootStation($station);

        $timeout = $loop->addTimer(10.0, function () use ($loop, &$booted, $output): void {
            if (! $booted) {
                $output->error("Boot timeout: no response within 10s");
            }
            $loop->stop();
        });

        $loop->addPeriodicTimer(0.1, function () use ($loop, &$eventSent, $timeout): void {
            if ($eventSent) {
                $loop->cancelTimer($timeout);
                $loop->stop();
            }
        });

        $loop->run();
        $orchestrator->shutdown();

        return 0;
    }

    private function sendEvent(
        \App\Station\SimulatedStation $station,
        \App\Services\SimulatorOrchestrator $orchestrator,
        ColoredConsoleOutput $output,
        string $type,
    ): void {
        $sender = $orchestrator->getSender();
        $bayId = $this->option('bay-id') ?? 'bay_1';

        match ($type) {
            'StatusNotification' => $this->sendStatusNotification($station, $sender, $bayId),
            'SecurityEvent' => $sender->sendEvent($station, OsppAction::SECURITY_EVENT, [
                'eventId' => 'sec_' . bin2hex(random_bytes(8)),
                'type' => 'HardwareFault',
                'severity' => 'Info',
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
                'details' => ['source' => 'ManualTrigger', 'stationId' => $station->getStationId()],
            ]),
            'DiagnosticsNotification' => $sender->sendEvent($station, OsppAction::DIAGNOSTICS_NOTIFICATION, [
                'stationId' => $station->getStationId(),
                'status' => 'Uploaded',
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            ]),
            'FirmwareStatusNotification' => $sender->sendEvent($station, OsppAction::FIRMWARE_STATUS_NOTIFICATION, [
                'stationId' => $station->getStationId(),
                'status' => 'Installed',
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            ]),
            default => null,
        };
    }

    private function sendStatusNotification(
        \App\Station\SimulatedStation $station,
        \App\Mqtt\MessageSender $sender,
        string $bayId,
    ): void {
        $statusService = new StatusNotificationService($sender);
        $bay = $station->getBay($bayId);

        if ($bay !== null) {
            $statusService->sendForBay($station, $bay);
        } else {
            $statusService->sendAllBays($station, \Ospp\Protocol\Enums\BayStatus::AVAILABLE);
        }
    }
}
