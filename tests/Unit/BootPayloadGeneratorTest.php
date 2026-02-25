<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Generators\BootPayloadGenerator;
use App\Station\BootReason;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use PHPUnit\Framework\TestCase;

final class BootPayloadGeneratorTest extends TestCase
{
    private BootPayloadGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BootPayloadGenerator();
    }

    public function test_payload_contains_all_required_fields(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $this->assertArrayHasKey('stationId', $payload);
        $this->assertArrayHasKey('firmwareVersion', $payload);
        $this->assertArrayHasKey('stationModel', $payload);
        $this->assertArrayHasKey('stationVendor', $payload);
        $this->assertArrayHasKey('uptimeSeconds', $payload);
        $this->assertArrayHasKey('pendingOfflineTransactions', $payload);
        $this->assertArrayHasKey('serialNumber', $payload);
        $this->assertArrayHasKey('bayCount', $payload);
        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertArrayHasKey('networkInfo', $payload);
        $this->assertArrayHasKey('timezone', $payload);
        $this->assertArrayHasKey('bootReason', $payload);
        $this->assertArrayHasKey('bays', $payload);
    }

    public function test_station_identity_fields(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $this->assertSame('SIM-001', $payload['stationId']);
        $this->assertSame('1.2.0', $payload['firmwareVersion']);
        $this->assertSame('OSP-4000', $payload['stationModel']);
        $this->assertSame('OneStopPay', $payload['stationVendor']);
        $this->assertSame('SN-001', $payload['serialNumber']);
    }

    public function test_bay_count_matches_config(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $this->assertSame(2, $payload['bayCount']);
        $this->assertCount(2, $payload['bays']);
    }

    public function test_capabilities_structure(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $caps = $payload['capabilities'];
        $this->assertArrayHasKey('bleSupported', $caps);
        $this->assertArrayHasKey('offlineModeSupported', $caps);
        $this->assertArrayHasKey('meterValuesSupported', $caps);
        $this->assertArrayHasKey('deviceManagementSupported', $caps);

        $this->assertFalse($caps['bleSupported']);
        $this->assertTrue($caps['offlineModeSupported']);
        $this->assertTrue($caps['meterValuesSupported']);
        $this->assertTrue($caps['deviceManagementSupported']);
    }

    public function test_network_info_structure(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $net = $payload['networkInfo'];
        $this->assertArrayHasKey('connectionType', $net);
        $this->assertArrayHasKey('signalStrength', $net);
        $this->assertSame('ethernet', $net['connectionType']);
        $this->assertNull($net['signalStrength']);
    }

    public function test_timezone_from_config(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $this->assertSame('Europe/Bucharest', $payload['timezone']);
    }

    public function test_boot_reason_from_state(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $this->assertSame(BootReason::POWER_ON, $payload['bootReason']);
    }

    public function test_bays_contain_required_fields(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $bay = $payload['bays'][0];
        $this->assertArrayHasKey('bayId', $bay);
        $this->assertArrayHasKey('bayNumber', $bay);
        $this->assertArrayHasKey('status', $bay);
        $this->assertArrayHasKey('services', $bay);

        $this->assertSame('Available', $bay['status']);
    }

    public function test_bay_services_included(): void
    {
        $station = $this->makeStation();
        $payload = $this->generator->generate($station);

        $bay = $payload['bays'][0];
        $this->assertCount(2, $bay['services']);

        $svc = $bay['services'][0];
        $this->assertArrayHasKey('serviceId', $svc);
        $this->assertArrayHasKey('serviceName', $svc);
        $this->assertArrayHasKey('pricingType', $svc);
        $this->assertArrayHasKey('priceCreditsFixed', $svc);
        $this->assertArrayHasKey('priceCreditsPerMinute', $svc);
        $this->assertArrayHasKey('available', $svc);

        $this->assertSame('wash_basic', $svc['serviceId']);
        $this->assertSame('Basic Wash', $svc['serviceName']);
    }

    public function test_custom_boot_reason(): void
    {
        $station = $this->makeStation();
        $station->state->bootReason = BootReason::FIRMWARE_UPDATE;

        $payload = $this->generator->generate($station);

        $this->assertSame(BootReason::FIRMWARE_UPDATE, $payload['bootReason']);
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
            'capabilities' => ['bay_count' => 2, 'ble_supported' => false, 'offline_mode_supported' => true, 'meter_values_supported' => true, 'device_management_supported' => true],
            'network' => ['connection_type' => 'ethernet', 'signal_strength' => null],
            'timezone' => 'Europe/Bucharest',
            'configuration' => ['HeartbeatIntervalSeconds' => 30],
            'services' => [
                ['service_id' => 'wash_basic', 'service_name' => 'Basic Wash', 'pricing_type' => 'fixed', 'price_credits_fixed' => 50, 'price_credits_per_minute' => null, 'available' => true],
                ['service_id' => 'vacuum', 'service_name' => 'Vacuum', 'pricing_type' => 'per_minute', 'price_credits_fixed' => null, 'price_credits_per_minute' => 5, 'available' => true],
            ],
            'behavior' => [],
            'meter_values' => ['interval_seconds' => 10, 'jitter_percent' => 15, 'profiles' => []],
            'offline' => ['pass_generation' => ['algorithm' => 'ECDSA-P256-SHA256']],
        ]);
        $identity = new StationIdentity('SIM-001', 'OSP-4000', 'OneStopPay', 'SN-001', '1.2.0');

        return new SimulatedStation($identity, $config);
    }
}
