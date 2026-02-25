<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Handlers\ReserveBayHandler;
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

final class ReserveBayHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private SimulatedBayFSM $bayFSM;
    private ResponseDecider&MockInterface $decider;
    private DelaySimulator&MockInterface $delay;
    private TimerManager&MockInterface $timers;
    private ColoredConsoleOutput&MockInterface $output;
    private ReserveBayHandler $handler;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->bayFSM = new SimulatedBayFSM();
        $this->decider = Mockery::mock(ResponseDecider::class);
        $this->delay = Mockery::mock(DelaySimulator::class);
        $this->timers = Mockery::mock(TimerManager::class);
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->handler = new ReserveBayHandler(
            $this->sender,
            $this->bayFSM,
            $this->decider,
            $this->delay,
            $this->timers,
            $this->output,
        );
    }

    public function test_rejects_with_3005_when_bay_not_found(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['bayId' => 'nonexistent', 'reservationId' => 'res_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3005 && $p['status'] === 'Rejected');

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_with_3014_when_bay_not_available_for_reservation(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::OCCUPIED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'reservationId' => 'res_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3014);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_when_bay_reserved(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::RESERVED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'reservationId' => 'res_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3014);

        ($this->handler)($station, $envelope);
    }

    public function test_rejects_when_bay_faulted(): void
    {
        $station = $this->makeStation();
        $bay = $this->getFirstBay($station);
        $bay->status = BayStatus::FAULTED;

        $envelope = $this->makeEnvelope(['bayId' => $bay->bayId, 'reservationId' => 'res_1']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['errorCode'] === 3014);

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
            action: 'ReserveBay',
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
