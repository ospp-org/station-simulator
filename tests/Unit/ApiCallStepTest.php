<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\MessageLogger;
use App\Mqtt\MessageSender;
use App\Mqtt\MqttConnectionManager;
use App\Scenarios\ScenarioContext;
use App\Scenarios\StepTypes\ApiCallStep;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use App\Timers\TimerManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class ApiCallStepTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ScenarioContext $context;

    protected function setUp(): void
    {
        $mqtt = Mockery::mock(MqttConnectionManager::class);
        $sender = Mockery::mock(MessageSender::class);
        $timers = Mockery::mock(TimerManager::class);
        $logger = new MessageLogger();

        $this->context = new ScenarioContext($sender, $mqtt, $timers, $logger);
        $this->context->stations = ['stn_00000001' => $this->makeStation()];
        $this->context->csmsBaseUrl = 'http://localhost:8000';
        $this->context->csmsJwtToken = 'test-jwt-token';
    }

    public function test_successful_post_request(): void
    {
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['session_id' => 'sess_abc123'],
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $step = new ApiCallStep($client);

        $result = $step->execute([
            'method' => 'POST',
            'path' => '/api/v1/sessions/start',
            'body' => ['bay_id' => 'bay_1', 'service_id' => 'wash_basic'],
            'expect_status' => 201,
        ], $this->context);

        $this->assertTrue($result);
        $this->assertStringContains('201', $step->getLastMessage());
    }

    public function test_captures_response_values(): void
    {
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['session_id' => 'sess_xyz789', 'bay_id' => 'bay_1'],
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $step = new ApiCallStep($client);

        $step->execute([
            'method' => 'POST',
            'path' => '/api/v1/sessions/start',
            'body' => [],
            'expect_status' => 201,
            'capture' => [
                'session_id' => 'data.session_id',
                'bay_id' => 'data.bay_id',
            ],
        ], $this->context);

        $this->assertSame('sess_xyz789', $this->context->captured['session_id']);
        $this->assertSame('bay_1', $this->context->captured['bay_id']);
    }

    public function test_fails_on_unexpected_status(): void
    {
        $mock = new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Validation failed',
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $step = new ApiCallStep($client);

        $result = $step->execute([
            'method' => 'POST',
            'path' => '/api/v1/sessions/start',
            'body' => [],
            'expect_status' => 201,
        ], $this->context);

        $this->assertFalse($result);
        $this->assertStringContains('422', $step->getLastMessage());
    }

    public function test_fails_without_csms_url(): void
    {
        $step = new ApiCallStep();
        $this->context->csmsBaseUrl = '';

        $result = $step->execute([
            'method' => 'GET',
            'path' => '/api/v1/stations',
        ], $this->context);

        $this->assertFalse($result);
        $this->assertStringContains('csms_url', $step->getLastMessage());
    }

    public function test_fails_without_jwt_token(): void
    {
        $step = new ApiCallStep();
        $this->context->csmsJwtToken = '';

        $result = $step->execute([
            'method' => 'GET',
            'path' => '/api/v1/stations',
        ], $this->context);

        $this->assertFalse($result);
        $this->assertStringContains('JWT', $step->getLastMessage());
    }

    public function test_resolves_station_id_template(): void
    {
        $requestedUrl = null;
        $mock = new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['message' => 'ok'])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$requestedUrl) {
            $requestedUrl = (string) $request->getUri();

            return $request;
        }));
        $client = new Client(['handler' => $handler]);
        $step = new ApiCallStep($client);

        $step->execute([
            'method' => 'PUT',
            'path' => '/api/v1/admin/stations/{{stationId}}/config',
            'body' => ['key' => 'test', 'value' => '123'],
            'expect_status' => 202,
        ], $this->context);

        $this->assertStringContains('stn_00000001', $requestedUrl);
    }

    public function test_resolves_captured_template(): void
    {
        $this->context->captured['session_id'] = 'sess_test123';

        $requestedUrl = null;
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['message' => 'ok'])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$requestedUrl) {
            $requestedUrl = (string) $request->getUri();

            return $request;
        }));
        $client = new Client(['handler' => $handler]);
        $step = new ApiCallStep($client);

        $step->execute([
            'method' => 'POST',
            'path' => '/api/v1/sessions/{{captured.session_id}}/stop',
            'expect_status' => 200,
        ], $this->context);

        $this->assertStringContains('sess_test123', $requestedUrl);
    }

    public function test_get_request_does_not_send_body(): void
    {
        $capturedOptions = null;
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => []])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$capturedOptions) {
            $capturedOptions = (string) $request->getBody();

            return $request;
        }));
        $client = new Client(['handler' => $handler]);
        $step = new ApiCallStep($client);

        $step->execute([
            'method' => 'GET',
            'path' => '/api/v1/stations',
            'body' => ['should_not' => 'appear'],
            'expect_status' => 200,
        ], $this->context);

        $this->assertSame('', $capturedOptions);
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertStringContainsString($needle, $haystack);
    }

    private function makeStation(): SimulatedStation
    {
        $config = new StationConfig([
            'identity' => [
                'station_id_prefix' => 'stn',
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
        $identity = new StationIdentity('stn_00000001', 'OSP-4000', 'AcmeCorp', 'SN-001', '1.2.0');

        return new SimulatedStation($identity, $config);
    }
}
