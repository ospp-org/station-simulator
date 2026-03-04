<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Generators\MeterValueGenerator;
use App\Station\BayState;
use App\Station\StationConfig;
use PHPUnit\Framework\TestCase;

final class MeterValueGeneratorTest extends TestCase
{
    private MeterValueGenerator $generator;
    private StationConfig $config;

    protected function setUp(): void
    {
        $this->generator = new MeterValueGenerator();
        $this->config = new StationConfig([
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
            'services' => [
                ['service_id' => 'wash_basic', 'service_name' => 'Basic Wash', 'pricing_type' => 'fixed', 'price_credits_fixed' => 50, 'price_credits_per_minute' => null, 'available' => true],
                ['service_id' => 'air', 'service_name' => 'Air', 'pricing_type' => 'fixed', 'price_credits_fixed' => 10, 'price_credits_per_minute' => null, 'available' => true],
            ],
            'behavior' => [],
            'meter_values' => [
                'interval_seconds' => 10,
                'jitter_percent' => 15,
                'profiles' => [
                    'wash_basic' => [
                        'liquid_ml_per_s' => [150, 300],
                        'consumable_ml_per_s' => [10, 20],
                        'energy_wh_per_s' => [5, 15],
                    ],
                    'air' => [
                        'liquid_ml_per_s' => [0, 0],
                        'consumable_ml_per_s' => [0, 0],
                        'energy_wh_per_s' => [30, 60],
                    ],
                ],
            ],
            'offline' => ['pass_generation' => ['algorithm' => 'ECDSA-P256-SHA256']],
        ]);
    }

    public function test_tick_accumulates_monotonically(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        $bay->startSession('session_1', 'wash_basic');

        $values1 = $this->generator->tick($bay, $this->config);
        $this->assertGreaterThan(0.0, $values1['liquid_ml']);
        $this->assertGreaterThan(0.0, $values1['consumable_ml']);
        $this->assertGreaterThan(0.0, $values1['energy_wh']);

        $values2 = $this->generator->tick($bay, $this->config);
        $this->assertGreaterThanOrEqual($values1['liquid_ml'], $values2['liquid_ml']);
        $this->assertGreaterThanOrEqual($values1['consumable_ml'], $values2['consumable_ml']);
        $this->assertGreaterThanOrEqual($values1['energy_wh'], $values2['energy_wh']);

        $values3 = $this->generator->tick($bay, $this->config);
        $this->assertGreaterThanOrEqual($values2['liquid_ml'], $values3['liquid_ml']);
    }

    public function test_zero_values_for_air_service_water_and_chemical(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        $bay->startSession('session_1', 'air');

        $values = $this->generator->tick($bay, $this->config);

        $this->assertSame(0.0, $values['liquid_ml']);
        $this->assertSame(0.0, $values['consumable_ml']);
        $this->assertGreaterThan(0.0, $values['energy_wh']);
    }

    public function test_tick_returns_empty_when_no_service(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        // No session started, currentServiceId is null

        $values = $this->generator->tick($bay, $this->config);

        $this->assertSame([], $values);
    }

    public function test_tick_returns_accumulator_when_no_profile(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        $bay->startSession('session_1', 'nonexistent_service');

        $values = $this->generator->tick($bay, $this->config);

        // Returns the existing accumulator (initialized with zeros by startSession)
        $this->assertSame(0.0, $values['liquid_ml']);
        $this->assertSame(0.0, $values['consumable_ml']);
        $this->assertSame(0.0, $values['energy_wh']);
    }

    public function test_build_payload_includes_all_required_fields(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        $bay->startSession('session_1', 'wash_basic');
        $this->generator->tick($bay, $this->config);

        $payload = $this->generator->buildPayload($bay, 'SIM-001');

        $this->assertSame('bay_SIM-001_1', $payload['bayId']);
        $this->assertSame('session_1', $payload['sessionId']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('values', $payload);
        $this->assertArrayNotHasKey('stationId', $payload);
        $this->assertArrayNotHasKey('durationSeconds', $payload);

        $mv = $payload['values'];
        $this->assertArrayHasKey('liquidMl', $mv);
        $this->assertArrayHasKey('consumableMl', $mv);
        $this->assertArrayHasKey('energyWh', $mv);
    }

    public function test_build_payload_timestamp_format(): void
    {
        $bay = new BayState('bay_SIM-001_1', 1);
        $bay->startSession('session_1', 'wash_basic');

        $payload = $this->generator->buildPayload($bay, 'SIM-001');

        // Verify ISO 8601 format with milliseconds
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $payload['timestamp'],
        );
    }
}
