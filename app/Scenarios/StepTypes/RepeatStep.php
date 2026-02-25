<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

final class RepeatStep implements StepInterface
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
        $times = (int) ($config['count'] ?? $config['times'] ?? 1);
        $subSteps = $config['steps'] ?? [];

        $allPassed = true;
        for ($i = 0; $i < $times; $i++) {
            foreach ($subSteps as $subStep) {
                if ($this->executor !== null) {
                    $result = ($this->executor)($subStep, $context);
                    if (!$result) {
                        $allPassed = false;
                    }
                }
            }
        }

        $count = count($subSteps);
        $this->lastMessage = "Repeated {$count} steps {$times} times";

        return $allPassed;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
