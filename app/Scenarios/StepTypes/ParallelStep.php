<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

final class ParallelStep implements StepInterface
{
    private string $lastMessage = '';
    /** @var callable(array<string,mixed>, ScenarioContext): bool|null */
    private $executor = null;

    public function setExecutor(callable $executor): void
    {
        $this->executor = $executor;
    }

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        // In a single-threaded PHP process, "parallel" steps run sequentially
        // but are logically grouped for scenario clarity
        $subSteps = $config['steps'] ?? [];
        $allPassed = true;

        foreach ($subSteps as $subStep) {
            if ($this->executor !== null) {
                $result = ($this->executor)($subStep, $context);
                if (!$result) {
                    $allPassed = false;
                }
            }
        }

        $count = count($subSteps);
        $this->lastMessage = "Executed {$count} parallel sub-steps";

        return $allPassed;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
