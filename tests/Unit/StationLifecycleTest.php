<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\StateMachines\StationLifecycle;
use App\Station\StationConfig;
use App\Station\StationState;
use PHPUnit\Framework\TestCase;

final class StationLifecycleTest extends TestCase
{
    private StationLifecycle $fsm;

    protected function setUp(): void
    {
        $this->fsm = new StationLifecycle();
    }

    // --- Valid transitions ---

    public function test_offline_to_booting_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_OFFLINE, StationState::LIFECYCLE_BOOTING));
    }

    public function test_booting_to_online_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_BOOTING, StationState::LIFECYCLE_ONLINE));
    }

    public function test_booting_to_offline_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_BOOTING, StationState::LIFECYCLE_OFFLINE));
    }

    public function test_online_to_resetting_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_ONLINE, StationState::LIFECYCLE_RESETTING));
    }

    public function test_online_to_offline_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_ONLINE, StationState::LIFECYCLE_OFFLINE));
    }

    public function test_resetting_to_booting_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_RESETTING, StationState::LIFECYCLE_BOOTING));
    }

    public function test_resetting_to_offline_is_valid(): void
    {
        $this->assertTrue($this->fsm->canTransition(StationState::LIFECYCLE_RESETTING, StationState::LIFECYCLE_OFFLINE));
    }

    // --- Invalid transitions ---

    public function test_offline_to_online_is_invalid(): void
    {
        $this->assertFalse($this->fsm->canTransition(StationState::LIFECYCLE_OFFLINE, StationState::LIFECYCLE_ONLINE));
    }

    public function test_offline_to_resetting_is_invalid(): void
    {
        $this->assertFalse($this->fsm->canTransition(StationState::LIFECYCLE_OFFLINE, StationState::LIFECYCLE_RESETTING));
    }

    public function test_booting_to_resetting_is_invalid(): void
    {
        $this->assertFalse($this->fsm->canTransition(StationState::LIFECYCLE_BOOTING, StationState::LIFECYCLE_RESETTING));
    }

    public function test_online_to_booting_is_invalid(): void
    {
        $this->assertFalse($this->fsm->canTransition(StationState::LIFECYCLE_ONLINE, StationState::LIFECYCLE_BOOTING));
    }

    public function test_transition_returns_false_for_invalid(): void
    {
        $state = new StationState($this->makeConfig());
        $this->assertFalse($this->fsm->transition($state, StationState::LIFECYCLE_ONLINE));
        $this->assertSame(StationState::LIFECYCLE_OFFLINE, $state->lifecycle);
    }

    // --- State side-effects ---

    public function test_transition_to_booting_sets_uptime_start(): void
    {
        $state = new StationState($this->makeConfig());
        $this->assertNull($state->uptimeStart);

        $this->fsm->transition($state, StationState::LIFECYCLE_BOOTING);

        $this->assertSame(StationState::LIFECYCLE_BOOTING, $state->lifecycle);
        $this->assertNotNull($state->uptimeStart);
    }

    public function test_transition_to_offline_clears_session_key_and_uptime(): void
    {
        $state = new StationState($this->makeConfig());
        $state->setLifecycle(StationState::LIFECYCLE_BOOTING);
        $state->sessionKey = 'some-key';
        $state->uptimeStart = new \DateTimeImmutable();

        $this->fsm->transition($state, StationState::LIFECYCLE_OFFLINE);

        $this->assertSame(StationState::LIFECYCLE_OFFLINE, $state->lifecycle);
        $this->assertNull($state->sessionKey);
        $this->assertNull($state->uptimeStart);
    }

    public function test_allowed_transitions_from_offline(): void
    {
        $allowed = $this->fsm->allowedTransitions(StationState::LIFECYCLE_OFFLINE);
        $this->assertSame([StationState::LIFECYCLE_BOOTING], $allowed);
    }

    public function test_allowed_transitions_from_unknown_state_returns_empty(): void
    {
        $allowed = $this->fsm->allowedTransitions('NONEXISTENT');
        $this->assertSame([], $allowed);
    }

    private function makeConfig(): StationConfig
    {
        return new StationConfig([
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
            'configuration' => ['HeartbeatIntervalSeconds' => 30, 'MaxSessionDurationSeconds' => 3600],
            'services' => [],
            'behavior' => [],
            'meter_values' => ['interval_seconds' => 10, 'jitter_percent' => 15, 'profiles' => []],
            'offline' => ['pass_generation' => ['algorithm' => 'ECDSA-P256-SHA256']],
        ]);
    }
}
