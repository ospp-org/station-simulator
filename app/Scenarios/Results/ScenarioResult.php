<?php

declare(strict_types=1);

namespace App\Scenarios\Results;

use DateTimeImmutable;

final class ScenarioResult
{
    public readonly string $runId;

    /** @var list<StepResult> */
    public array $steps = [];

    public ?DateTimeImmutable $startedAt = null;
    public ?DateTimeImmutable $finishedAt = null;

    public function __construct(
        public readonly string $name,
    ) {
        $this->runId = 'run_' . bin2hex(random_bytes(8));
        $this->startedAt = new DateTimeImmutable();
    }

    public function addStep(StepResult $step): void
    {
        $this->steps[] = $step;
    }

    public function finish(): void
    {
        $this->finishedAt = new DateTimeImmutable();
    }

    public function passed(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->failed()) {
                return false;
            }
        }

        return count($this->steps) > 0;
    }

    public function getDurationMs(): float
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return 0.0;
        }

        $diff = $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();

        return $diff * 1000.0;
    }

    public function getStepCount(): int
    {
        return count($this->steps);
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->steps, fn (StepResult $s): bool => $s->passed()));
    }

    public function getFailedCount(): int
    {
        return count(array_filter($this->steps, fn (StepResult $s): bool => $s->failed()));
    }
}
