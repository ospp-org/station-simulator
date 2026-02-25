<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Scenarios\Results\ResultStore;
use App\Scenarios\ScenarioParser;
use Psr\Http\Message\ServerRequestInterface;

final class ScenarioController
{
    public function __construct(
        private readonly ResultStore $resultStore,
        private readonly ?ScenarioParser $parser = null,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('GET', '/api/scenarios', [$this, 'list']);
        $server->registerRoute('GET', '/api/scenarios/results', [$this, 'results']);
        $server->registerRoute('GET', '/api/scenarios/results/{runId}', [$this, 'resultDetail']);
    }

    /** @return array<string, mixed> */
    public function list(ServerRequestInterface $request, array $params = []): array
    {
        $parser = $this->parser ?? new ScenarioParser();
        $scenariosDir = base_path('scenarios');
        $scenarios = [];

        if (! is_dir($scenariosDir)) {
            return ['scenarios' => [], 'count' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scenariosDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'yaml' && $file->getExtension() !== 'yml') {
                continue;
            }

            try {
                $scenario = $parser->parseFile($file->getPathname());
                $relativePath = str_replace(
                    [$scenariosDir . DIRECTORY_SEPARATOR, $scenariosDir . '/'],
                    '',
                    $file->getPathname(),
                );

                $scenarios[] = [
                    'file' => $relativePath,
                    'name' => $scenario->name,
                    'description' => $scenario->description,
                    'tags' => $scenario->tags,
                    'stepCount' => $scenario->getStepCount(),
                    'timeoutSeconds' => $scenario->timeoutSeconds,
                ];
            } catch (\Throwable) {
                // skip invalid scenarios
            }
        }

        return ['scenarios' => $scenarios, 'count' => count($scenarios)];
    }

    /** @return array<string, mixed> */
    public function results(ServerRequestInterface $request, array $params = []): array
    {
        $queryParams = $request->getQueryParams();
        $limit = min((int) ($queryParams['limit'] ?? 50), 100);

        $results = $this->resultStore->getAll($limit);
        $serialized = array_map(fn ($r) => [
            'runId' => $r->runId,
            'name' => $r->name,
            'passed' => $r->passed(),
            'stepCount' => $r->getStepCount(),
            'passedCount' => $r->getPassedCount(),
            'failedCount' => $r->getFailedCount(),
            'durationMs' => $r->getDurationMs(),
            'startedAt' => $r->startedAt->format('Y-m-d\TH:i:s.v\Z'),
            'finishedAt' => $r->finishedAt?->format('Y-m-d\TH:i:s.v\Z'),
        ], $results);

        return ['results' => $serialized, 'count' => count($serialized)];
    }

    /** @return array<string, mixed> */
    public function resultDetail(ServerRequestInterface $request, array $params = []): array
    {
        $runId = $params['runId'] ?? '';
        $result = $this->resultStore->getByRunId($runId);

        if ($result === null) {
            throw new \InvalidArgumentException("Result not found: {$runId}");
        }

        $steps = array_map(fn ($s) => [
            'name' => $s->name,
            'status' => $s->status,
            'durationMs' => $s->durationMs,
            'details' => $s->details,
        ], $result->steps);

        return [
            'runId' => $result->runId,
            'name' => $result->name,
            'passed' => $result->passed(),
            'steps' => $steps,
            'durationMs' => $result->getDurationMs(),
            'startedAt' => $result->startedAt->format('Y-m-d\TH:i:s.v\Z'),
            'finishedAt' => $result->finishedAt?->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
