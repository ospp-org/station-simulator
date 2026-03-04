<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\SignCertificateService;
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

final class SignCertificateServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MessageSender&MockInterface $sender;
    private ColoredConsoleOutput&MockInterface $output;
    private SignCertificateService $service;

    protected function setUp(): void
    {
        $this->sender = Mockery::mock(MessageSender::class);
        $this->output = Mockery::mock(ColoredConsoleOutput::class)->shouldIgnoreMissing();

        $this->service = new SignCertificateService($this->sender, $this->output);
    }

    public function test_sends_sign_certificate_request_with_csr(): void
    {
        $station = $this->makeStation();

        $dummyEnvelope = new MessageEnvelope(
            messageId: MessageId::generate('msg_'),
            messageType: MessageType::REQUEST,
            action: OsppAction::SIGN_CERTIFICATE,
            timestamp: new \DateTimeImmutable(),
            source: 'Station',
            protocolVersion: ProtocolVersion::default(),
            payload: [],
        );

        $this->sender->shouldReceive('sendRequest')
            ->once()
            ->withArgs(function ($s, $action, $payload) {
                return $action === OsppAction::SIGN_CERTIFICATE
                    && $payload['certificateType'] === 'StationCertificate'
                    && str_starts_with($payload['csr'], '-----BEGIN CERTIFICATE REQUEST-----');
            })
            ->andReturn($dummyEnvelope);

        $this->service->requestSigning($station, 'StationCertificate');
    }

    public function test_sends_with_mqtt_client_certificate_type(): void
    {
        $station = $this->makeStation();

        $dummyEnvelope = new MessageEnvelope(
            messageId: MessageId::generate('msg_'),
            messageType: MessageType::REQUEST,
            action: OsppAction::SIGN_CERTIFICATE,
            timestamp: new \DateTimeImmutable(),
            source: 'Station',
            protocolVersion: ProtocolVersion::default(),
            payload: [],
        );

        $this->sender->shouldReceive('sendRequest')
            ->once()
            ->withArgs(fn ($s, $a, $p) => $p['certificateType'] === 'MQTTClientCertificate')
            ->andReturn($dummyEnvelope);

        $this->service->requestSigning($station, 'MQTTClientCertificate');
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
}
