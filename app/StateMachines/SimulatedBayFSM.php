<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Station\BayState;
use App\Station\SimulatedStation;
use Ospp\Protocol\Enums\BayStatus;
use Ospp\Protocol\StateMachines\BayTransitions;

final class SimulatedBayFSM
{
    private readonly BayTransitions $transitions;

    public function __construct()
    {
        $this->transitions = new BayTransitions();
    }

    public function canTransition(BayState $bay, BayStatus $to): bool
    {
        return $this->transitions->canTransition($bay->status, $to);
    }

    public function transition(SimulatedStation $station, BayState $bay, BayStatus $to): bool
    {
        if (! $this->transitions->canTransition($bay->status, $to)) {
            return false;
        }

        $previousStatus = $bay->status;
        $bay->transitionTo($to);

        $station->emit('bay.statusChanged', [
            'bayId' => $bay->bayId,
            'bayNumber' => $bay->bayNumber,
            'previousStatus' => $previousStatus->value,
            'newStatus' => $to->value,
        ]);

        return true;
    }

    /** @return list<BayStatus> */
    public function allowedTransitions(BayState $bay): array
    {
        return $this->transitions->allowedTransitions($bay->status);
    }
}
