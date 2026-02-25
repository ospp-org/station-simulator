<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\StateMachines\SimulatedSessionFSM;
use OneStopPay\OsppProtocol\Enums\SessionStatus;
use PHPUnit\Framework\TestCase;

final class SimulatedSessionFSMTest extends TestCase
{
    private SimulatedSessionFSM $fsm;

    protected function setUp(): void
    {
        $this->fsm = new SimulatedSessionFSM();
    }

    public function test_start_session_sets_pending_state(): void
    {
        $this->fsm->startSession('bay_1');

        $this->assertSame(SessionStatus::PENDING, $this->fsm->getStatus('bay_1'));
    }

    public function test_get_status_returns_null_for_unknown_bay(): void
    {
        $this->assertNull($this->fsm->getStatus('bay_unknown'));
    }

    // --- Valid transitions ---

    public function test_pending_to_authorized(): void
    {
        $this->fsm->startSession('bay_1');

        $this->assertTrue($this->fsm->canTransition('bay_1', SessionStatus::AUTHORIZED));
        $this->assertTrue($this->fsm->transition('bay_1', SessionStatus::AUTHORIZED));
        $this->assertSame(SessionStatus::AUTHORIZED, $this->fsm->getStatus('bay_1'));
    }

    public function test_authorized_to_active(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);

        $this->assertTrue($this->fsm->transition('bay_1', SessionStatus::ACTIVE));
        $this->assertSame(SessionStatus::ACTIVE, $this->fsm->getStatus('bay_1'));
    }

    public function test_active_to_stopping(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->fsm->transition('bay_1', SessionStatus::ACTIVE);

        $this->assertTrue($this->fsm->transition('bay_1', SessionStatus::STOPPING));
        $this->assertSame(SessionStatus::STOPPING, $this->fsm->getStatus('bay_1'));
    }

    public function test_stopping_to_completed(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->fsm->transition('bay_1', SessionStatus::ACTIVE);
        $this->fsm->transition('bay_1', SessionStatus::STOPPING);

        $this->assertTrue($this->fsm->transition('bay_1', SessionStatus::COMPLETED));
    }

    // --- Terminal states clear session ---

    public function test_terminal_completed_clears_session(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->fsm->transition('bay_1', SessionStatus::ACTIVE);
        $this->fsm->transition('bay_1', SessionStatus::STOPPING);
        $this->fsm->transition('bay_1', SessionStatus::COMPLETED);

        $this->assertNull($this->fsm->getStatus('bay_1'));
    }

    public function test_terminal_failed_clears_session(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::FAILED);

        $this->assertNull($this->fsm->getStatus('bay_1'));
    }

    // --- Invalid transitions ---

    public function test_pending_to_active_is_invalid(): void
    {
        $this->fsm->startSession('bay_1');

        $this->assertFalse($this->fsm->canTransition('bay_1', SessionStatus::ACTIVE));
        $this->assertFalse($this->fsm->transition('bay_1', SessionStatus::ACTIVE));
        $this->assertSame(SessionStatus::PENDING, $this->fsm->getStatus('bay_1'));
    }

    public function test_transition_returns_false_for_no_session(): void
    {
        $this->assertFalse($this->fsm->transition('bay_unknown', SessionStatus::ACTIVE));
    }

    public function test_can_transition_returns_false_for_no_session(): void
    {
        $this->assertFalse($this->fsm->canTransition('bay_unknown', SessionStatus::ACTIVE));
    }

    // --- Timeout values ---

    public function test_get_timeout_for_pending(): void
    {
        $this->fsm->startSession('bay_1');
        $this->assertSame(30, $this->fsm->getTimeout('bay_1'));
    }

    public function test_get_timeout_for_authorized(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->assertSame(30, $this->fsm->getTimeout('bay_1'));
    }

    public function test_get_timeout_for_active(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->fsm->transition('bay_1', SessionStatus::ACTIVE);
        $this->assertSame(3600, $this->fsm->getTimeout('bay_1'));
    }

    public function test_get_timeout_for_stopping(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->transition('bay_1', SessionStatus::AUTHORIZED);
        $this->fsm->transition('bay_1', SessionStatus::ACTIVE);
        $this->fsm->transition('bay_1', SessionStatus::STOPPING);
        $this->assertSame(30, $this->fsm->getTimeout('bay_1'));
    }

    public function test_get_timeout_returns_null_for_no_session(): void
    {
        $this->assertNull($this->fsm->getTimeout('bay_unknown'));
    }

    // --- clearSession ---

    public function test_clear_session_removes_state(): void
    {
        $this->fsm->startSession('bay_1');
        $this->fsm->clearSession('bay_1');

        $this->assertNull($this->fsm->getStatus('bay_1'));
    }
}
