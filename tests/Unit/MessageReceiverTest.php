<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\ColoredConsoleOutput;
use App\Logging\MessageLogger;
use App\Mqtt\MessageReceiver;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Enums\MessageType;
use OneStopPay\OsppProtocol\ValueObjects\MessageId;
use OneStopPay\OsppProtocol\ValueObjects\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class MessageReceiverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageLogger $logger;
    private ColoredConsoleOutput&MockInterface $output;
    private MessageReceiver $receiver;

    protected function setUp(): void
    {
        $this->logger = new MessageLogger();
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();
        $this->receiver = new MessageReceiver($this->logger, $this->output);
    }

    public function test_parses_valid_json_and_routes(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $routed = false;
        $this->receiver->setCommandRouter(function ($s, $e) use (&$routed): void {
            $routed = true;
        });

        $json = $this->makeValidJson();
        $this->receiver->handleMessage('SIM-001', $json);

        $this->assertTrue($routed);
    }

    public function test_invalid_json_logs_error(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $this->output->shouldReceive('error')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Invalid JSON'));

        $this->receiver->handleMessage('SIM-001', '{invalid json!!}');
    }

    public function test_unknown_station_logs_warning(): void
    {
        $this->receiver->setStations([]);

        $this->output->shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'unknown station'));

        $this->receiver->handleMessage('UNKNOWN-001', $this->makeValidJson());
    }

    public function test_qos1_dedup_skips_duplicate_message_id(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $routeCount = 0;
        $this->receiver->setCommandRouter(function () use (&$routeCount): void {
            $routeCount++;
        });

        $messageId = MessageId::generate('cmd_');
        $json = $this->makeJsonWithMessageId((string) $messageId);

        $this->receiver->handleMessage('SIM-001', $json);
        $this->receiver->handleMessage('SIM-001', $json);

        $this->assertSame(1, $routeCount);
    }

    public function test_dedup_allows_different_message_ids(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $routeCount = 0;
        $this->receiver->setCommandRouter(function () use (&$routeCount): void {
            $routeCount++;
        });

        $json1 = $this->makeJsonWithMessageId((string) MessageId::generate('cmd_'));
        $json2 = $this->makeJsonWithMessageId((string) MessageId::generate('cmd_'));

        $this->receiver->handleMessage('SIM-001', $json1);
        $this->receiver->handleMessage('SIM-001', $json2);

        $this->assertSame(2, $routeCount);
    }

    public function test_duplicate_message_logs_warning(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);
        $this->receiver->setCommandRouter(function (): void {});

        $messageId = MessageId::generate('cmd_');
        $json = $this->makeJsonWithMessageId((string) $messageId);

        $this->output->shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Duplicate'));

        $this->receiver->handleMessage('SIM-001', $json);
        $this->receiver->handleMessage('SIM-001', $json);
    }

    public function test_emits_message_received_event(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $emitted = null;
        $station->on('message.received', function (array $data) use (&$emitted): void {
            $emitted = $data;
        });

        $this->receiver->handleMessage('SIM-001', $this->makeValidJson());

        $this->assertNotNull($emitted);
        $this->assertSame(OsppAction::START_SERVICE, $emitted['action']);
        $this->assertSame('REQUEST', $emitted['messageType']);
    }

    public function test_logger_records_inbound(): void
    {
        $station = $this->makeStation();
        $this->receiver->setStations(['SIM-001' => $station]);

        $this->receiver->handleMessage('SIM-001', $this->makeValidJson());

        $this->assertSame(1, $this->logger->getTotalCount());
        $entries = $this->logger->getAll();
        $this->assertSame('inbound', $entries[0]['direction']);
    }

    private function makeStation(): SimulatedStation
    {
        $config = new StationConfig([
            'identity' => [
                'station_id_prefix' => 'SIM',
                'station_model' => 'OSP-4000',
                'station_vendor' => 'OneStopPay',
                'serial_number_prefix' => 'SN',
                'firmware_version' => '1.2.0',
            ],
            'capabilities' => ['bay_count' => 1, 'ble_supported' => false, 'offline_mode_supported' => true, 'meter_values_supported' => true, 'device_management_supported' => true],
            'network' => ['connection_type' => 'ethernet', 'signal_strength' => null],
            'timezone' => 'Europe/Bucharest',
            'configuration' => ['HeartbeatIntervalSeconds' => 30],
            'services' => [],
            'behavior' => [],
            'meter_values' => ['interval_seconds' => 10, 'jitter_percent' => 15, 'profiles' => []],
            'offline' => ['pass_generation' => ['algorithm' => 'ECDSA-P256-SHA256']],
        ]);
        $identity = new StationIdentity('SIM-001', 'OSP-4000', 'OneStopPay', 'SN-001', '1.2.0');

        return new SimulatedStation($identity, $config);
    }

    private function makeValidJson(): string
    {
        return $this->makeJsonWithMessageId((string) MessageId::generate('cmd_'));
    }

    private function makeJsonWithMessageId(string $messageId): string
    {
        return json_encode([
            'messageId' => $messageId,
            'messageType' => MessageType::REQUEST->value,
            'action' => OsppAction::START_SERVICE,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'source' => 'csms',
            'protocolVersion' => '1.0.0',
            'payload' => ['bayId' => 'bay_1', 'sessionId' => 'sess_1'],
        ], JSON_THROW_ON_ERROR);
    }
}
