<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\MessageLogger;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Enums\MessageType;
use OneStopPay\OsppProtocol\Enums\SigningMode;
use OneStopPay\OsppProtocol\Envelope\MessageEnvelope;
use OneStopPay\OsppProtocol\ValueObjects\MessageId;
use OneStopPay\OsppProtocol\ValueObjects\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class MessageSenderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MqttConnectionManager&MockInterface $mqtt;
    private MessageLogger $logger;
    private MessageSender $sender;

    protected function setUp(): void
    {
        $this->mqtt = Mockery::mock(MqttConnectionManager::class);
        $this->logger = new MessageLogger();
        $this->sender = new MessageSender($this->mqtt, $this->logger);
    }

    public function test_send_request_builds_envelope_and_publishes(): void
    {
        $station = $this->makeStation();

        $this->mqtt->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $stationId, string $json) {
                $data = json_decode($json, true);

                return $stationId === 'SIM-001'
                    && $data['action'] === OsppAction::BOOT_NOTIFICATION
                    && $data['messageType'] === 'REQUEST'
                    && $data['source'] === 'station'
                    && isset($data['messageId'])
                    && isset($data['protocolVersion'])
                    && isset($data['timestamp'])
                    && $data['payload']['stationId'] === 'SIM-001';
            });

        $envelope = $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, [
            'stationId' => 'SIM-001',
        ]);

        $this->assertSame(OsppAction::BOOT_NOTIFICATION, $envelope->action);
        $this->assertSame(MessageType::REQUEST, $envelope->messageType);
    }

    public function test_send_event_builds_event_envelope(): void
    {
        $station = $this->makeStation();

        $this->mqtt->shouldReceive('publish')->once();

        $envelope = $this->sender->sendEvent($station, OsppAction::STATUS_NOTIFICATION, [
            'stationId' => 'SIM-001',
            'bayId' => 'bay_1',
            'status' => 'available',
        ]);

        $this->assertSame(MessageType::EVENT, $envelope->messageType);
        $this->assertSame(OsppAction::STATUS_NOTIFICATION, $envelope->action);
    }

    public function test_send_response_correlates_to_request(): void
    {
        $station = $this->makeStation();

        $requestEnvelope = new MessageEnvelope(
            messageId: MessageId::generate('cmd_'),
            messageType: MessageType::REQUEST,
            action: OsppAction::START_SERVICE,
            timestamp: new \DateTimeImmutable(),
            source: 'csms',
            protocolVersion: ProtocolVersion::default(),
            payload: ['bayId' => 'bay_1'],
        );

        $this->mqtt->shouldReceive('publish')->once();

        $responseEnvelope = $this->sender->sendResponse($station, OsppAction::START_SERVICE, [
            'status' => 'Accepted',
        ], $requestEnvelope);

        $this->assertSame(MessageType::RESPONSE, $responseEnvelope->messageType);
        // Correlated: same messageId as the request
        $this->assertTrue($responseEnvelope->messageId->equals($requestEnvelope->messageId));
    }

    public function test_hmac_signing_applied_when_session_key_present_and_mode_all(): void
    {
        $station = $this->makeStation();
        $station->state->sessionKey = base64_encode(random_bytes(32));

        $this->sender->setSigningMode(SigningMode::ALL);

        $this->mqtt->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $stationId, string $json) {
                $data = json_decode($json, true);

                return isset($data['mac']) && $data['mac'] !== null;
            });

        $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, [
            'stationId' => 'SIM-001',
        ]);
    }

    public function test_no_hmac_when_signing_mode_none(): void
    {
        $station = $this->makeStation();
        $station->state->sessionKey = base64_encode(random_bytes(32));

        $this->sender->setSigningMode(SigningMode::NONE);

        $this->mqtt->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $stationId, string $json) {
                $data = json_decode($json, true);

                return !isset($data['mac']);
            });

        $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, [
            'stationId' => 'SIM-001',
        ]);
    }

    public function test_no_hmac_when_no_session_key(): void
    {
        $station = $this->makeStation();
        $station->state->sessionKey = null;

        $this->sender->setSigningMode(SigningMode::ALL);

        $this->mqtt->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $stationId, string $json) {
                $data = json_decode($json, true);

                return !isset($data['mac']);
            });

        $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, [
            'stationId' => 'SIM-001',
        ]);
    }

    public function test_send_emits_message_sent_event(): void
    {
        $station = $this->makeStation();
        $emitted = null;

        $station->on('message.sent', function (array $data) use (&$emitted): void {
            $emitted = $data;
        });

        $this->mqtt->shouldReceive('publish')->once();

        $this->sender->sendRequest($station, OsppAction::HEARTBEAT, []);

        $this->assertNotNull($emitted);
        $this->assertSame(OsppAction::HEARTBEAT, $emitted['action']);
        $this->assertSame('REQUEST', $emitted['messageType']);
        $this->assertArrayHasKey('messageId', $emitted);
    }

    public function test_logger_records_outbound_message(): void
    {
        $station = $this->makeStation();

        $this->mqtt->shouldReceive('publish')->once();

        $this->sender->sendEvent($station, OsppAction::METER_VALUES, ['bayId' => 'bay_1']);

        $this->assertSame(1, $this->logger->getTotalCount());
        $entries = $this->logger->getAll();
        $this->assertSame('outbound', $entries[0]['direction']);
        $this->assertSame('SIM-001', $entries[0]['stationId']);
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
}
