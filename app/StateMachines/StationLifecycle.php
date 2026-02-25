<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Station\StationState;

final class StationLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        StationState::LIFECYCLE_OFFLINE => [StationState::LIFECYCLE_BOOTING],
        StationState::LIFECYCLE_BOOTING => [StationState::LIFECYCLE_ONLINE, StationState::LIFECYCLE_OFFLINE],
        StationState::LIFECYCLE_ONLINE => [StationState::LIFECYCLE_RESETTING, StationState::LIFECYCLE_OFFLINE],
        StationState::LIFECYCLE_RESETTING => [StationState::LIFECYCLE_BOOTING, StationState::LIFECYCLE_OFFLINE],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public function transition(StationState $state, string $to): bool
    {
        if (! $this->canTransition($state->lifecycle, $to)) {
            return false;
        }

        $state->setLifecycle($to);

        if ($to === StationState::LIFECYCLE_BOOTING) {
            $state->uptimeStart = new \DateTimeImmutable();
        }

        if ($to === StationState::LIFECYCLE_OFFLINE) {
            $state->sessionKey = null;
            $state->uptimeStart = null;
        }

        return true;
    }
}
