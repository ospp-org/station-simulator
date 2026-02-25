<?php

declare(strict_types=1);

namespace App\StateMachines;

use OneStopPay\OsppProtocol\Enums\SessionStatus;
use OneStopPay\OsppProtocol\StateMachines\SessionTransitions;

final class SimulatedSessionFSM
{
    private readonly SessionTransitions $transitions;

    /** @var array<string, SessionStatus> bayId => current session status */
    private array $sessionStates = [];

    public function __construct()
    {
        $this->transitions = new SessionTransitions();
    }

    public function startSession(string $bayId): void
    {
        $this->sessionStates[$bayId] = SessionStatus::PENDING;
    }

    public function getStatus(string $bayId): ?SessionStatus
    {
        return $this->sessionStates[$bayId] ?? null;
    }

    public function canTransition(string $bayId, SessionStatus $to): bool
    {
        $current = $this->sessionStates[$bayId] ?? null;
        if ($current === null) {
            return false;
        }

        return $this->transitions->canTransition($current, $to);
    }

    public function transition(string $bayId, SessionStatus $to): bool
    {
        $current = $this->sessionStates[$bayId] ?? null;
        if ($current === null) {
            return false;
        }

        if (! $this->transitions->canTransition($current, $to)) {
            return false;
        }

        $this->sessionStates[$bayId] = $to;

        if ($to->isTerminal()) {
            unset($this->sessionStates[$bayId]);
        }

        return true;
    }

    public function getTimeout(string $bayId): ?int
    {
        $status = $this->sessionStates[$bayId] ?? null;
        if ($status === null) {
            return null;
        }

        return $this->transitions->getTimeout($status);
    }

    public function clearSession(string $bayId): void
    {
        unset($this->sessionStates[$bayId]);
    }
}
