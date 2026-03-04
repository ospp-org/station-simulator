<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Generators\MeterValueGenerator;
use App\Handlers\StopServiceHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\StatusNotificationService;
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

final class StopServiceHandlerTest extends TestCase
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
    private StopServiceHandler $handler;

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
        $statusService = Mockery::mock(StatusNotificationService::class)->shouldIgnoreMissing();

        $this->handler = new StopServiceHandler(
            $this->sender,
            $this->bayFSM,
            $this->sessionFSM,
            $this->decider,
            $this->delay,
            $this->timers,
            $this->meterGenerator,
            $statusService,
            $this->output,
        );
    }

    public function test_rejects_with_3005_when_bay_not_found(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['bayId' => 'nonexistent_bay', 'sessionId' => 'sess_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3005 && $p['status'] === 'Rejected')
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3006_when_bay_not_occupied(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::AVAILABLE;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3006)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3007_when_session_mismatch(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::OCCUPIED;
        $bay->startSession('sess_correct', 'wash_basic');

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_wrong']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3007)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3006_when_bay_reserved(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::RESERVED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'sessionId' => 'sess_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3006)
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
            action: 'StopService',
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
