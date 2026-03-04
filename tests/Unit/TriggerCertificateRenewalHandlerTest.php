<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Handlers\TriggerCertificateRenewalHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\SignCertificateService;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Ospp\Protocol\Enums\MessageType;
use Ospp\Protocol\Envelope\MessageEnvelope;
use Ospp\Protocol\ValueObjects\MessageId;
use Ospp\Protocol\ValueObjects\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class TriggerCertificateRenewalHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private ResponseDecider&MockInterface $decider;
    private DelaySimulator&MockInterface $delay;
    private SignCertificateService&MockInterface $signService;
    private ColoredConsoleOutput&MockInterface $output;
    private TriggerCertificateRenewalHandler $handler;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->decider = Mockery::mock(ResponseDecider::class);
        $this->delay = Mockery::mock(DelaySimulator::class);
        $this->signService = Mockery::mock(SignCertificateService::class);
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->handler = new TriggerCertificateRenewalHandler(
            $this->sender,
            $this->decider,
            $this->delay,
            $this->signService,
            $this->output,
        );
    }

    public function test_rejects_invalid_certificate_type(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['certificateType' => 'InvalidType']);

        $this->sender->shouldReceive('sendResponse')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['status'] === 'Rejected' && $p['errorCode'] === 5001)
            ->andReturn($envelope);

        ($this->handler)($station, $envelope);
    }

    public function test_accepted_triggers_sign_certificate(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['certificateType' => 'StationCertificate']);

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

        $this->signService->shouldReceive('requestSigning')
            ->once()
            ->with($station, 'StationCertificate');

        ($this->handler)($station, $envelope);
    }

    public function test_rejected_does_not_trigger_sign_certificate(): void
    {
        $station = $this->makeStation();
        $envelope = $this->makeEnvelope(['certificateType' => 'MQTTClientCertificate']);

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

        $this->signService->shouldNotReceive('requestSigning');

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

    /** @param array<string, mixed> $payload */
    private function makeEnvelope(array $payload): MessageEnvelope
    {
        return new MessageEnvelope(
            messageId: MessageId::generate('cmd_'),
            messageType: MessageType::REQUEST,
            action: 'TriggerCertificateRenewal',
            timestamp: new \DateTimeImmutable(),
            source: 'Server',
            protocolVersion: ProtocolVersion::default(),
            payload: $payload,
        );
    }
}
