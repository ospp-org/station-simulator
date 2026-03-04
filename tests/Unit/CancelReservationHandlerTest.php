<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\Handlers\CancelReservationHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\Station\BayState;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use App\Timers\TimerManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\Enums\MessageType;
use Ospp\Protocol\Envelope\MessageEnvelope;
use Ospp\Protocol\ValueObjects\MessageId;
use Ospp\Protocol\ValueObjects\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class CancelReservationHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private SimulatedBayFSM $bayFSM;
    private DelaySimulator&MockInterface $delay;
    private TimerManager&MockInterface $timers;
    private ColoredConsoleOutput&MockInterface $output;
    private CancelReservationHandler $handler;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->bayFSM = new SimulatedBayFSM();
        $this->delay = Mockery::mock(DelaySimulator::class);
        $this->timers = Mockery::mock(TimerManager::class);
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->handler = new CancelReservationHandler(
            $this->sender,
            $this->bayFSM,
            $this->delay,
            $this->timers,
            $this->output,
        );
    }

    public function test_rejects_with_3012_when_reservation_not_found(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['reservationId' => 'res_nonexistent']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3012
                && $p['status'] === 'Rejected'
                && $p['errorText'] === 'Reservation not found')
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3013_when_bay_no_longer_reserved(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        // Set reservation but change status away from RESERVED
        $bay->setReservation('res_expired');
        $bay->status = BayStatus::AVAILABLE;

        $envelope = $this->makeEnvelope(['reservationId' => 'res_expired']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3013
                && $p['errorText'] === 'Reservation expired')
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_accepts_valid_reservation_cancel(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::RESERVED;
        $bay->setReservation('res_valid');

        $envelope = $this->makeEnvelope(['reservationId' => 'res_valid']);

        // The delay simulator should execute callback immediately
        $this->delay->shouldReceive('afterConfigDelay')
            ->once()
            ->andReturnUsing(function ($key, $config, callable $callback): void {
                $callback();
            });

        $this->timers->shouldReceive('cancelTimer')
            ->once()
            ->with("reservation-expire:{$bay->bayId}");

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p === ['status' => 'Accepted'])
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);

        // Verify bay is back to Available
        $this->assertSame(BayStatus::AVAILABLE, $bay->status);
        $this->assertNull($bay->currentReservationId);
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
            'capabilities' => ['bay_count' => 2, 'ble_supported' => false, 'offline_mode_supported' => true, 'meter_values_supported' => true, 'device_management_supported' => true],
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

    private function getFirstBay(SimulatedStation $station): BayState
    {
        $bays = $station->getBays();

        return reset($bays);
    }

    /** @param array<string, mixed> $payload */
    private function makeEnvelope(array $payload): MessageEnvelope
    {
        return new MessageEnvelope(
            messageId: MessageId::generate('cmd_'),
            messageType: MessageType::REQUEST,
            action: 'CancelReservation',
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
