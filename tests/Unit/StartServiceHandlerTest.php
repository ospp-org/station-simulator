<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Generators\MeterValueGenerator;
use App\Handlers\StartServiceHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\StateMachines\SimulatedSessionFSM;
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

final class StartServiceHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private SimulatedBayFSM $bayFSM;
    private SimulatedSessionFSM $sessionFSM;
    private ResponseDecider&MockInterface $decider;
    private DelaySimulator&MockInterface $delay;
    private TimerManager&MockInterface $timers;
    private MeterValueGenerator $meterGenerator;
    private ColoredConsoleOutput&MockInterface $output;
    private StartServiceHandler $handler;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->bayFSM = new SimulatedBayFSM();
        $this->sessionFSM = new SimulatedSessionFSM();
        $this->decider = Mockery::mock(ResponseDecider::class);
        $this->delay = Mockery::mock(DelaySimulator::class);
        $this->timers = Mockery::mock(TimerManager::class);
        $this->meterGenerator = new MeterValueGenerator();
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->handler = new StartServiceHandler(
            $this->sender,
            $this->bayFSM,
            $this->sessionFSM,
            $this->decider,
            $this->delay,
            $this->timers,
            $this->meterGenerator,
            $this->output,
        );
    }

    public function test_rejects_with_3005_when_bay_not_found(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['bayId' => 'nonexistent_bay', 'sessionId' => 'sess_1', 'serviceId' => 'wash_basic']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(function (SimulatedStation $s, string $action, array $payload) {
                return $payload['errorCode'] === 3005
                    && $payload['status'] === 'Rejected';
            })
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3001_when_bay_occupied(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::OCCUPIED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_1', 'serviceId' => 'wash_basic']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3001)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3002_when_bay_faulted(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::FAULTED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_1', 'serviceId' => 'wash_basic']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3002)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3011_when_bay_unavailable(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::UNAVAILABLE;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_1', 'serviceId' => 'wash_basic']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3011)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3014_when_reserved_but_reservation_mismatch(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::RESERVED;
        $bay->setReservation('res_correct');

        $envelope = $this->makeEnvelope([
            'bayId' => $bay->bayId,
            'sessionId' => 'sess_1',
            'serviceId' => 'wash_basic',
            'reservationId' => 'res_wrong',
        ]);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3014)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
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
            'configuration' => ['HeartbeatIntervalSeconds' => 30, 'MaxSessionDurationSeconds' => 3600],
            'services' => [
                ['service_id' => 'wash_basic', 'service_name' => 'Basic Wash', 'pricing_type' => 'fixed', 'price_credits_fixed' => 50, 'price_credits_per_minute' => null, 'available' => true],
            ],
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
            action: 'StartService',
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
