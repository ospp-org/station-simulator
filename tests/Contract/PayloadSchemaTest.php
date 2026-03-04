<?php

declare(strict_types=1);

namespace Tests\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Ospp\Protocol\SchemaPath;
use PHPUnit\Framework\TestCase;

final class PayloadSchemaTest extends TestCase
{
    private Validator $validator;
    private string $schemaDir;

    protected function setUp(): void
    {
        $this->schemaDir = SchemaPath::directory();
        $this->validator = new Validator();

        // Register schema prefix resolver so $ref works
        $this->validator->resolver()->registerPrefix(
            'https://ospp-standard.org/schemas/v1/',
            $this->schemaDir . '/',
        );
    }

    public function test_heartbeat_request_payload(): void
    {
        $payload = (object) [];

        $this->assertValidPayload($payload, 'mqtt/heartbeat-request.schema.json');
    }

    public function test_certificate_install_response_accepted(): void
    {
        $payload = (object) [
            'status' => 'Accepted',
            'certificateSerialNumber' => 'SN-a1b2c3d4e5f6a7b8',
        ];

        $this->assertValidPayload($payload, 'mqtt/certificate-install-response.schema.json');
    }

    public function test_certificate_install_response_rejected(): void
    {
        $payload = (object) [
            'status' => 'Rejected',
            'errorCode' => 5001,
            'errorText' => 'Invalid certificate type',
        ];

        $this->assertValidPayload($payload, 'mqtt/certificate-install-response.schema.json');
    }

    public function test_trigger_certificate_renewal_response_accepted(): void
    {
        $payload = (object) [
            'status' => 'Accepted',
        ];

        $this->assertValidPayload($payload, 'mqtt/trigger-certificate-renewal-response.schema.json');
    }

    public function test_trigger_certificate_renewal_response_rejected(): void
    {
        $payload = (object) [
            'status' => 'Rejected',
            'errorCode' => 5001,
            'errorText' => 'Renewal rejected by auto-responder',
        ];

        $this->assertValidPayload($payload, 'mqtt/trigger-certificate-renewal-response.schema.json');
    }

    public function test_trigger_message_response_accepted(): void
    {
        $payload = (object) ['status' => 'Accepted'];

        $this->assertValidPayload($payload, 'mqtt/trigger-message-response.schema.json');
    }

    public function test_trigger_message_response_not_implemented(): void
    {
        $payload = (object) ['status' => 'NotImplemented'];

        $this->assertValidPayload($payload, 'mqtt/trigger-message-response.schema.json');
    }

    public function test_trigger_message_response_rejected(): void
    {
        $payload = (object) ['status' => 'Rejected'];

        $this->assertValidPayload($payload, 'mqtt/trigger-message-response.schema.json');
    }

    public function test_data_transfer_response_accepted(): void
    {
        $payload = (object) ['status' => 'Accepted'];

        $this->assertValidPayload($payload, 'mqtt/data-transfer-response.schema.json');
    }

    public function test_data_transfer_response_rejected(): void
    {
        $payload = (object) ['status' => 'Rejected'];

        $this->assertValidPayload($payload, 'mqtt/data-transfer-response.schema.json');
    }

    public function test_sign_certificate_request_payload(): void
    {
        $payload = (object) [
            'certificateType' => 'StationCertificate',
            'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nMIIBxx==\n-----END CERTIFICATE REQUEST-----",
        ];

        $this->assertValidPayload($payload, 'mqtt/sign-certificate-request.schema.json');
    }

    public function test_connection_lost_lwt_payload(): void
    {
        $payload = (object) [
            'stationId' => 'stn_a1b2c3d4e5f6',
            'reason' => 'UnexpectedDisconnect',
        ];

        $this->assertValidPayload($payload, 'mqtt/connection-lost.schema.json');
    }

    public function test_status_notification_payload(): void
    {
        $payload = (object) [
            'bayId' => 'bay_a1b2c3d4e5f6',
            'bayNumber' => 1,
            'status' => 'Available',
            'services' => [
                (object) [
                    'serviceId' => 'svc_wash_basic',
                    'available' => true,
                ],
            ],
        ];

        $this->assertValidPayload($payload, 'mqtt/status-notification.schema.json');
    }

    public function test_security_event_payload(): void
    {
        $payload = (object) [
            'eventId' => 'sec_a1b2c3d4e5f6a7b8',
            'type' => 'HardwareFault',
            'severity' => 'Info',
            'timestamp' => '2026-03-04T12:00:00.000Z',
            'details' => (object) ['source' => 'TriggerMessage'],
        ];

        $this->assertValidPayload($payload, 'mqtt/security-event.schema.json');
    }

    public function test_certificate_install_request_payload(): void
    {
        $payload = (object) [
            'certificateType' => 'StationCertificate',
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIBxx==\n-----END CERTIFICATE-----",
        ];

        $this->assertValidPayload($payload, 'mqtt/certificate-install-request.schema.json');
    }

    public function test_trigger_certificate_renewal_request_payload(): void
    {
        $payload = (object) [
            'certificateType' => 'MQTTClientCertificate',
        ];

        $this->assertValidPayload($payload, 'mqtt/trigger-certificate-renewal-request.schema.json');
    }

    public function test_trigger_message_request_payload(): void
    {
        $payload = (object) [
            'requestedMessage' => 'StatusNotification',
        ];

        $this->assertValidPayload($payload, 'mqtt/trigger-message-request.schema.json');
    }

    public function test_data_transfer_request_payload(): void
    {
        $payload = (object) [
            'vendorId' => 'AcmeCorp',
            'dataId' => 'diagnostics.full',
            'data' => (object) ['level' => 'verbose'],
        ];

        $this->assertValidPayload($payload, 'mqtt/data-transfer-request.schema.json');
    }

    private function assertValidPayload(object $payload, string $schemaRelPath): void
    {
        $schemaUri = 'https://ospp-standard.org/schemas/v1/' . $schemaRelPath;

        $result = $this->validator->validate($payload, $schemaUri);

        if (! $result->isValid()) {
            $formatter = new ErrorFormatter();
            $errors = $formatter->format($result->error());
            $errorMsg = json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $this->fail("Payload does not match schema {$schemaRelPath}:\n{$errorMsg}");
        }

        $this->assertTrue($result->isValid());
    }
}
