<?php

declare(strict_types=1);

namespace App\Scenarios\Results;

final class ResultStore
{
    private const MAX_RESULTS = 100;

    /** @var list<ScenarioResult> */
    private array $results = [];

    public function store(ScenarioResult $result): void
    {
        $this->results[] = $result;

        if (count($this->results) > self::MAX_RESULTS) {
            $this->results = array_slice($this->results, -self::MAX_RESULTS);
        }
    }

    public function getByRunId(string $runId): ?ScenarioResult
    {
        foreach ($this->results as $result) {
            if ($result->runId === $runId) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<ScenarioResult> */
    public function getAll(int $limit = 50): array
    {
        return array_reverse(array_slice($this->results, -$limit));
    }

    public function clear(): void
    {
        $this->results = [];
    }

    public function getCount(): int
    {
        return count($this->results);
    }
}
