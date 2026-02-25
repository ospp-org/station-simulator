<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Scenarios\Results\StepResult;
use App\Scenarios\StepTypes\AssertStep;
use App\Scenarios\StepTypes\DelayStep;
use App\Scenarios\StepTypes\DisconnectStep;
use App\Scenarios\StepTypes\FaultStep;
use App\Scenarios\StepTypes\ParallelStep;
use App\Scenarios\StepTypes\RepeatStep;
use App\Scenarios\StepTypes\SendStep;
use App\Scenarios\StepTypes\SetBehaviorStep;
use App\Scenarios\StepTypes\StepInterface;
use App\Scenarios\StepTypes\WaitForStep;

final class StepExecutor
{
    /** @var array<string, StepInterface> */
    private array $stepTypes = [];

    public function __construct(
        SendStep $send,
        WaitForStep $waitFor,
        AssertStep $assert,
        DelayStep $delay,
        ParallelStep $parallel,
        RepeatStep $repeat,
        SetBehaviorStep $setBehavior,
        DisconnectStep $disconnect,
        FaultStep $fault,
    ) {
        $this->stepTypes = [
            'send' => $send,
            'wait_for' => $waitFor,
            'assert' => $assert,
            'delay' => $delay,
            'parallel' => $parallel,
            'repeat' => $repeat,
            'set_behavior' => $setBehavior,
            'disconnect' => $disconnect,
            'fault' => $fault,
        ];
    }

    /** @param array<string, mixed> $stepConfig */
    public function execute(array $stepConfig, ScenarioContext $context): StepResult
    {
        $type = $stepConfig['type'] ?? '';
        $name = $stepConfig['name'] ?? $type;

        $stepHandler = $this->stepTypes[$type] ?? null;
        if ($stepHandler === null) {
            return new StepResult($name, 'fail', 0.0, "Unknown step type: {$type}");
        }

        $startTime = microtime(true);

        try {
            $result = $stepHandler->execute($stepConfig, $context);
            $durationMs = (microtime(true) - $startTime) * 1000;

            return new StepResult($name, $result ? 'pass' : 'fail', $durationMs, $stepHandler->getLastMessage());
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            return new StepResult($name, 'fail', $durationMs, $e->getMessage());
        }
    }
}
