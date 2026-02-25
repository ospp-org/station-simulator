<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Handlers\IncomingCommandRouter;
use App\Logging\ColoredConsoleOutput;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\MessageType;
use Ospp\Protocol\Envelope\MessageEnvelope;
use Ospp\Protocol\ValueObjects\MessageId;
use Ospp\Protocol\ValueObjects\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class IncomingCommandRouterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ColoredConsoleOutput&MockInterface $output;
    private IncomingCommandRouter $router;

    protected function setUp(): void
    {
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();
        $this->router = new IncomingCommandRouter($this->output);
    }

    public function test_dispatches_to_registered_handler(): void
    {
        $station = $this->makeStation();
        $called = false;

        $this->router->registerHandler(OsppAction::START_SERVICE, function () use (&$called): void {
            $called = true;
        });

        $envelope = $this->makeEnvelope(OsppAction::START_SERVICE);
        $this->router->route($station, $envelope);

        $this->assertTrue($called);
    }

    public function test_handler_receives_station_and_envelope(): void
    {
        $station = $this->makeStation();
        $receivedStation = null;
        $receivedEnvelope = null;

        $this->router->registerHandler(OsppAction::STOP_SERVICE, function ($s, $e) use (&$receivedStation, &$receivedEnvelope): void {
            $receivedStation = $s;
            $receivedEnvelope = $e;
        });

        $envelope = $this->makeEnvelope(OsppAction::STOP_SERVICE);
        $this->router->route($station, $envelope);

        $this->assertSame($station, $receivedStation);
        $this->assertSame($envelope, $receivedEnvelope);
    }

    public function test_unknown_action_logs_warning(): void
    {
        $station = $this->makeStation();

        $this->output->shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Unknown OSPP action'));

        $envelope = $this->makeEnvelope('TotallyInvalidAction');
        $this->router->route($station, $envelope);
    }

    public function test_no_handler_for_valid_action_logs_debug(): void
    {
        $station = $this->makeStation();

        $this->output->shouldReceive('debug')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'No handler registered'));

        $envelope = $this->makeEnvelope(OsppAction::HEARTBEAT);
        $this->router->route($station, $envelope);
    }

    public function test_handler_error_is_caught_and_logged(): void
    {
        $station = $this->makeStation();

        $this->router->registerHandler(OsppAction::RESET, function (): void {
            throw new \RuntimeException('Simulated handler failure');
        });

        $this->output->shouldReceive('error')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'Handler error')
                && str_contains($msg, 'Simulated handler failure'));

        $envelope = $this->makeEnvelope(OsppAction::RESET);
        $this->router->route($station, $envelope);
    }

    private function makeStation(): SimulatedStation
    {
        $config = new StationConfig([
            'identity' => [
                'station_id_prefix' => 'SIM',
                'station_model' => 'OSP-4000',
                'station_vendor' => 'AcmeCorp',
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
        $identity = new StationIdentity('SIM-001', 'OSP-4000', 'AcmeCorp', 'SN-001', '1.2.0');

        return new SimulatedStation($identity, $config);
    }

    private function makeEnvelope(string $action): MessageEnvelope
    {
        return new MessageEnvelope(
            messageId: MessageId::generate('cmd_'),
            messageType: MessageType::REQUEST,
            action: $action,
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: [],
        );
    }
}
