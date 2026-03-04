<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Handlers\TriggerMessageHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\StatusNotificationService;
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

final class TriggerMessageHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private ResponseDecider&MockInterface $decider;
    private DelaySimulator&MockInterface $delay;
    private StatusNotificationService&MockInterface $statusService;
    private ColoredConsoleOutput&MockInterface $output;
    private TriggerMessageHandler $handler;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->decider = Mockery::mock(ResponseDecider::class);
        $this->delay = Mockery::mock(DelaySimulator::class);
        $this->statusService = Mockery::mock(StatusNotificationService::class);
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->handler = new TriggerMessageHandler(
            $this->sender,
            $this->decider,
            $this->delay,
            $this->statusService,
            $this->output,
        );
    }

    public function test_status_notification_accepted(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['requestedMessage' => OsppAction::STATUS_NOTIFICATION]);

        $this->delay->shouldReceive('afterDelay')
            ->once()
            ->andReturnUsing(fn ($key, $range, callable $cb) => $cb());

        $this->decider->shouldReceive('decide')
            ->once()
            ->andReturn(ResponseDecision::ACCEPTED);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'Accepted')
            ->andReturn($envelope);

        $this->statusService->shouldReceive('sendForBay')->atLeast()->once();

        ($this->handler)($station, $envelope);
    }

    public function test_heartbeat_accepted(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['requestedMessage' => OsppAction::HEARTBEAT]);

        $this->delay->shouldReceive('afterDelay')
            ->once()
            ->andReturnUsing(fn ($key, $range, callable $cb) => $cb());

        $this->decider->shouldReceive('decide')
            ->once()
            ->andReturn(ResponseDecision::ACCEPTED);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'Accepted')
            ->andReturn($envelope);

        $this->sender->shouldReceive('sendRequest')
            ->once()
            ->withArgs(fn ($s, $a) => $a === OsppAction::HEARTBEAT)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_not_implemented_for_meter_values(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['requestedMessage' => OsppAction::METER_VALUES]);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'NotImplemented')
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_rejected_for_unknown_message(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['requestedMessage' => 'UnknownMessage']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'Rejected')
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_auto_responder_rejects(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['requestedMessage' => OsppAction::STATUS_NOTIFICATION]);

        $this->delay->shouldReceive('afterDelay')
            ->once()
            ->andReturnUsing(fn ($key, $range, callable $cb) => $cb());

        $this->decider->shouldReceive('decide')
            ->once()
            ->andReturn(ResponseDecision::REJECTED);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'Rejected')
            ->andReturn($envelope);

        $this->statusService->shouldNotReceive('sendForBay');
        $this->statusService->shouldNotReceive('sendAllBays');

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

    /** @param array<string, mixed> $payload */
    private function makeEnvelope(array $payload): MessageEnvelope
    {
        return new MessageEnvelope(
            messageId: MessageId::generate('cmd_'),
            messageType: MessageType::REQUEST,
            action: 'TriggerMessage',
            timestamp: new \DateTimeImmutable(),
            source: 'Server',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
