<?php

declare(strict_types=1);

namespace App\Commands;

use App\Scenarios\ScenarioParser;
use App\Scenarios\StepExecutor;
use LaravelZero\Framework\Commands\Command;

final class ValidateScenarioCommand extends Command
{
    protected $signature = 'scenarios:validate
        {path : Path to YAML scenario file or directory}';

    protected $description = 'Validate a scenario YAML file for structural correctness';

    private const VALID_STEP_TYPES = [
        'send', 'wait_for', 'assert', 'delay', 'parallel',
        'repeat', 'set_behavior', 'disconnect', 'fault',
    ];

    private const VALID_ASSERTIONS = [
        'bay_status', 'station_state', 'session_active', 'message_received',
        'message_count', 'response_status', 'response_time', 'payload_field',
        'meter_values_monotonic', 'no_errors', 'hmac_valid',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        $parser = new ScenarioParser();
        $errors = 0;
        $validated = 0;

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'yaml' && $file->getExtension() !== 'yml') {
                    continue;
                }

                $errors += $this->validateFile($parser, $file->getPathname());
                $validated++;
            }
        } elseif (file_exists($path)) {
            $errors += $this->validateFile($parser, $path);
            $validated++;
        } else {
            $this->error("Path not found: {$path}");

            return 1;
        }

        $this->newLine();

        if ($errors === 0) {
            $this->info("{$validated} scenario(s) validated successfully");

            return 0;
        }

        $this->error("{$errors} error(s) found in {$validated} scenario(s)");

        return 1;
    }

    private function validateFile(ScenarioParser $parser, string $path): int
    {
        $this->line("Validating: {$path}");
        $errors = 0;

        try {
            $scenario = $parser->parseFile($path);
        } catch (\Throwable $e) {
            $this->error("  Parse error: {$e->getMessage()}");

            return 1;
        }

        // Validate step types
        foreach ($scenario->steps as $i => $step) {
            $stepName = $step['name'] ?? "step-{$i}";
            $type = $step['type'] ?? '';

            if (! in_array($type, self::VALID_STEP_TYPES, true)) {
                $this->error("  Step \"{$stepName}\": unknown type \"{$type}\"");
                $errors++;

                continue;
            }

            // Type-specific validation
            $errors += match ($type) {
                'send' => $this->validateSendStep($step, $stepName),
                'wait_for' => $this->validateWaitForStep($step, $stepName),
                'assert' => $this->validateAssertStep($step, $stepName),
                'delay' => $this->validateDelayStep($step, $stepName),
                'repeat' => $this->validateRepeatStep($step, $stepName),
                'parallel' => $this->validateParallelStep($step, $stepName),
                default => 0,
            };
        }

        if ($errors === 0) {
            $this->info("  OK ({$scenario->getStepCount()} steps)");
        }

        return $errors;
    }

    private function validateSendStep(array $step, string $name): int
    {
        $errors = 0;

        if (! isset($step['action'])) {
            $this->error("  Step \"{$name}\": send step requires \"action\"");
            $errors++;
        }

        return $errors;
    }

    private function validateWaitForStep(array $step, string $name): int
    {
        $errors = 0;

        if (! isset($step['action'])) {
            $this->error("  Step \"{$name}\": wait_for step requires \"action\"");
            $errors++;
        }

        if (! isset($step['timeout_ms'])) {
            $this->warn("  Step \"{$name}\": wait_for step missing \"timeout_ms\" (default 5000)");
        }

        return $errors;
    }

    private function validateAssertStep(array $step, string $name): int
    {
        $errors = 0;

        if (! isset($step['assertion'])) {
            $this->error("  Step \"{$name}\": assert step requires \"assertion\"");
            $errors++;
        } elseif (! in_array($step['assertion'], self::VALID_ASSERTIONS, true)) {
            $this->error("  Step \"{$name}\": unknown assertion \"{$step['assertion']}\"");
            $errors++;
        }

        return $errors;
    }

    private function validateDelayStep(array $step, string $name): int
    {
        if (! isset($step['duration_ms'])) {
            $this->error("  Step \"{$name}\": delay step requires \"duration_ms\"");

            return 1;
        }

        return 0;
    }

    private function validateRepeatStep(array $step, string $name): int
    {
        $errors = 0;

        if (! isset($step['count'])) {
            $this->error("  Step \"{$name}\": repeat step requires \"count\"");
            $errors++;
        }

        if (! isset($step['steps']) || ! is_array($step['steps'])) {
            $this->error("  Step \"{$name}\": repeat step requires \"steps\" array");
            $errors++;
        }

        return $errors;
    }

    private function validateParallelStep(array $step, string $name): int
    {
        if (! isset($step['steps']) || ! is_array($step['steps'])) {
            $this->error("  Step \"{$name}\": parallel step requires \"steps\" array");

            return 1;
        }

        return 0;
    }
}
