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
        {type : Event type: StatusNotification, SecurityEvent, DiagnosticsNotification, FirmwareStatusNotification, DataTransfer, AuthorizeOfflinePass, ConnectionLost, TransactionEvent}
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
        'DataTransfer' => OsppAction::DATA_TRANSFER,
        'AuthorizeOfflinePass' => OsppAction::AUTHORIZE_OFFLINE_PASS,
        'ConnectionLost' => OsppAction::CONNECTION_LOST,
        'TransactionEvent' => OsppAction::TRANSACTION_EVENT,
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
        $bays = $station->getBays();
        $firstBay = reset($bays);
        $bayId = $this->option('bay-id') ?? ($firstBay ? $firstBay->bayId : 'bay_00000001');

        match ($type) {
            'StatusNotification' => $this->sendStatusNotification($station, $sender, $bayId),
            'SecurityEvent' => $sender->sendEvent($station, OsppAction::SECURITY_EVENT, [
                'eventId' => 'sec_' . bin2hex(random_bytes(8)),
                'type' => 'HardwareFault',
                'severity' => 'Info',
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
                'details' => (object) ['source' => 'ManualTrigger', 'stationId' => $station->getStationId()],
            ]),
            'DiagnosticsNotification' => $sender->sendEvent($station, OsppAction::DIAGNOSTICS_NOTIFICATION, [
                'status' => 'Uploaded',
                'fileName' => "diag_{$station->getStationId()}_" . date('Ymd_His') . '.tar.gz',
            ]),
            'FirmwareStatusNotification' => $sender->sendEvent($station, OsppAction::FIRMWARE_STATUS_NOTIFICATION, [
                'status' => 'Installed',
                'firmwareVersion' => $station->identity->firmwareVersion,
            ]),
            'DataTransfer' => $sender->sendRequest($station, OsppAction::DATA_TRANSFER, [
                'vendorId' => $station->identity->vendor,
                'dataId' => 'diagnostics.summary',
                'data' => (object) ['stationId' => $station->getStationId(), 'uptime' => random_int(3600, 86400)],
            ]),
            'AuthorizeOfflinePass' => $this->sendAuthorizeOfflinePass($station, $sender, $bayId),
            'ConnectionLost' => $sender->sendEvent($station, OsppAction::CONNECTION_LOST, [
                'stationId' => $station->getStationId(),
                'reason' => 'UnexpectedDisconnect',
            ]),
            'TransactionEvent' => $this->sendTransactionEvent($station, $sender, $bayId),
            default => null,
        };
    }

    private function sendTransactionEvent(
        \App\Station\SimulatedStation $station,
        \App\Mqtt\MessageSender $sender,
        string $bayId,
    ): void {
        $bay = $station->getBay($bayId);
        $serviceId = 'svc_wash_basic';
        if ($bay !== null && ! empty($bay->services)) {
            $svc = $bay->services[0];
            $serviceId = $svc['service_id'] ?? $svc['serviceId'] ?? $serviceId;
        }

        $now = new \DateTimeImmutable();
        $durationSeconds = random_int(60, 600);
        $startedAt = $now->modify("-{$durationSeconds} seconds");
        $receiptData = base64_encode(json_encode([
            'txId' => 'otx_' . bin2hex(random_bytes(8)),
            'amount' => 50,
            'ts' => $now->format('Y-m-d\TH:i:s.v\Z'),
        ]));

        $sender->sendRequest($station, OsppAction::TRANSACTION_EVENT, [
            'offlineTxId' => 'otx_' . bin2hex(random_bytes(8)),
            'offlinePassId' => 'opass_' . bin2hex(random_bytes(8)),
            'userId' => 'sub_' . bin2hex(random_bytes(6)),
            'bayId' => $bay?->bayId ?? $bayId,
            'serviceId' => $serviceId,
            'startedAt' => $startedAt->format('Y-m-d\TH:i:s.v\Z'),
            'endedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'durationSeconds' => $durationSeconds,
            'creditsCharged' => random_int(20, 100),
            'receipt' => [
                'data' => $receiptData,
                'signature' => base64_encode(random_bytes(64)),
                'signatureAlgorithm' => 'ECDSA-P256-SHA256',
            ],
            'txCounter' => 1,
        ]);
    }

    private function sendAuthorizeOfflinePass(
        \App\Station\SimulatedStation $station,
        \App\Mqtt\MessageSender $sender,
        string $bayId,
    ): void {
        $bay = $station->getBay($bayId);
        $serviceId = 'svc_wash_basic';
        if ($bay !== null && ! empty($bay->services)) {
            $svc = $bay->services[0];
            $serviceId = $svc['service_id'] ?? $svc['serviceId'] ?? $serviceId;
        }

        $passId = 'opass_' . bin2hex(random_bytes(8));
        $now = new \DateTimeImmutable();

        $sender->sendRequest($station, OsppAction::AUTHORIZE_OFFLINE_PASS, [
            'offlinePassId' => $passId,
            'offlinePass' => [
                'passId' => $passId,
                'sub' => 'sub_' . bin2hex(random_bytes(6)),
                'deviceId' => 'dev_' . bin2hex(random_bytes(6)),
                'issuedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'expiresAt' => $now->modify('+24 hours')->format('Y-m-d\TH:i:s.v\Z'),
                'policyVersion' => 1,
                'revocationEpoch' => 0,
                'offlineAllowance' => [
                    'maxTotalCredits' => 500,
                    'maxUses' => 5,
                    'maxCreditsPerTx' => 100,
                    'allowedServiceTypes' => [$serviceId],
                ],
                'constraints' => [
                    'minIntervalSec' => 60,
                    'stationOfflineWindowHours' => 24,
                    'stationMaxOfflineTx' => 10,
                ],
                'signatureAlgorithm' => 'ECDSA-P256-SHA256',
                'signature' => base64_encode(random_bytes(64)),
            ],
            'deviceId' => 'dev_' . bin2hex(random_bytes(6)),
            'counter' => 1,
            'bayId' => $bay?->bayId ?? $bayId,
            'serviceId' => $serviceId,
        ]);
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
