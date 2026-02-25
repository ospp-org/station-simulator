<?php

declare(strict_types=1);

namespace App\Scenarios\Results;

final class JsonExporter
{
    public function export(ScenarioResult $result): string
    {
        return json_encode($this->toArray($result), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function toArray(ScenarioResult $result): array
    {
        return [
            'runId' => $result->runId,
            'name' => $result->name,
            'passed' => $result->passed(),
            'steps' => array_map(fn (StepResult $s): array => [
                'name' => $s->name,
                'status' => $s->status,
                'durationMs' => round($s->durationMs, 2),
                'details' => $s->details,
            ], $result->steps),
            'totalSteps' => $result->getStepCount(),
            'passedSteps' => $result->getPassedCount(),
            'failedSteps' => $result->getFailedCount(),
            'durationMs' => round($result->getDurationMs(), 2),
            'startedAt' => $result->startedAt?->format('Y-m-d\TH:i:s.v\Z'),
            'finishedAt' => $result->finishedAt?->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    public function exportToFile(ScenarioResult $result, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->export($result));
    }
}
