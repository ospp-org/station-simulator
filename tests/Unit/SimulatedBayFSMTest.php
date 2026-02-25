<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\StateMachines\SimulatedBayFSM;
use App\Station\BayState;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\Station\StationIdentity;
use Ospp\Protocol\Enums\BayStatus;
use PHPUnit\Framework\TestCase;

final class SimulatedBayFSMTest extends TestCase
{
    private SimulatedBayFSM $fsm;

    protected function setUp(): void
    {
        $this->fsm = new SimulatedBayFSM();
    }

    // --- Valid transitions via canTransition ---

    public function test_unknown_to_available_is_valid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::UNKNOWN;

        $this->assertTrue($this->fsm->canTransition($bay, BayStatus::AVAILABLE));
    }

    public function test_available_to_reserved_is_valid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::AVAILABLE;

        $this->assertTrue($this->fsm->canTransition($bay, BayStatus::RESERVED));
    }

    public function test_available_to_occupied_is_valid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::AVAILABLE;

        $this->assertTrue($this->fsm->canTransition($bay, BayStatus::OCCUPIED));
    }

    public function test_occupied_to_finishing_is_valid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::OCCUPIED;

        $this->assertTrue($this->fsm->canTransition($bay, BayStatus::FINISHING));
    }

    public function test_finishing_to_available_is_valid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::FINISHING;

        $this->assertTrue($this->fsm->canTransition($bay, BayStatus::AVAILABLE));
    }

    // --- Invalid transitions ---

    public function test_unknown_to_occupied_is_invalid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::UNKNOWN;

        $this->assertFalse($this->fsm->canTransition($bay, BayStatus::OCCUPIED));
    }

    public function test_occupied_to_available_is_invalid(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::OCCUPIED;

        $this->assertFalse($this->fsm->canTransition($bay, BayStatus::AVAILABLE));
    }

    public function test_transition_returns_false_for_invalid(): void
    {
        $station = $this->makeStation();
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::UNKNOWN;

        $result = $this->fsm->transition($station, $bay, BayStatus::OCCUPIED);

        $this->assertFalse($result);
        $this->assertSame(BayStatus::UNKNOWN, $bay->status);
    }

    // --- Transition side-effects ---

    public function test_transition_updates_bay_status(): void
    {
        $station = $this->makeStation();
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::UNKNOWN;

        $result = $this->fsm->transition($station, $bay, BayStatus::AVAILABLE);

        $this->assertTrue($result);
        $this->assertSame(BayStatus::AVAILABLE, $bay->status);
        $this->assertSame(BayStatus::UNKNOWN, $bay->previousStatus);
    }

    public function test_transition_emits_bay_status_changed_event(): void
    {
        $station = $this->makeStation();
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::AVAILABLE;

        $emittedData = null;
        $station->on('bay.statusChanged', function (array $data) use (&$emittedData): void {
            $emittedData = $data;
        });

        $this->fsm->transition($station, $bay, BayStatus::RESERVED);

        $this->assertNotNull($emittedData);
        $this->assertSame('bay_1', $emittedData['bayId']);
        $this->assertSame(1, $emittedData['bayNumber']);
        $this->assertSame('available', $emittedData['previousStatus']);
        $this->assertSame('reserved', $emittedData['newStatus']);
    }

    // --- allowedTransitions ---

    public function test_allowed_transitions_from_available(): void
    {
        $bay = new BayState('bay_1', 1);
        $bay->status = BayStatus::AVAILABLE;

        $allowed = $this->fsm->allowedTransitions($bay);

        $this->assertContains(BayStatus::RESERVED, $allowed);
        $this->assertContains(BayStatus::OCCUPIED, $allowed);
        $this->assertContains(BayStatus::FAULTED, $allowed);
        $this->assertContains(BayStatus::UNAVAILABLE, $allowed);
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
