<?php

declare(strict_types=1);

namespace App\Commands;

use App\Scenarios\ScenarioParser;
use LaravelZero\Framework\Commands\Command;

final class ListScenariosCommand extends Command
{
    protected $signature = 'scenarios:list
        {--tag= : Filter scenarios by tag}
        {--dir= : Scenarios directory (default: scenarios/)}';

    protected $description = 'List all available test scenarios';

    public function handle(): int
    {
        $dir = $this->option('dir') ?? base_path('scenarios');
        $tag = $this->option('tag');

        if (! is_dir($dir)) {
            $this->error("Scenarios directory not found: {$dir}");

            return 1;
        }

        $parser = new ScenarioParser();
        $scenarios = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'yaml' && $file->getExtension() !== 'yml') {
                continue;
            }

            try {
                $scenario = $parser->parseFile($file->getPathname());

                // Apply tag filter
                if ($tag !== null && ! in_array($tag, $scenario->tags, true)) {
                    continue;
                }

                $relativePath = str_replace(
                    [$dir . DIRECTORY_SEPARATOR, $dir . '/'],
                    '',
                    $file->getPathname(),
                );

                $scenarios[] = [
                    $relativePath,
                    $scenario->name,
                    $scenario->description,
                    implode(', ', $scenario->tags),
                    $scenario->getStepCount(),
                    $scenario->timeoutSeconds . 's',
                ];
            } catch (\Throwable $e) {
                $this->warn("Skipping {$file->getPathname()}: {$e->getMessage()}");
            }
        }

        if (count($scenarios) === 0) {
            $this->info($tag ? "No scenarios found with tag \"{$tag}\"" : 'No scenarios found');

            return 0;
        }

        // Sort by path
        usort($scenarios, fn ($a, $b) => $a[0] <=> $b[0]);

        $this->table(
            ['File', 'Name', 'Description', 'Tags', 'Steps', 'Timeout'],
            $scenarios,
        );

        $this->info(count($scenarios) . ' scenario(s) found');

        return 0;
    }
}
