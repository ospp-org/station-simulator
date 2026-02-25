<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Logging\ColoredConsoleOutput;
use App\Scenarios\Results\ScenarioResult;

final class ScenarioRunner
{
    public function __construct(
        private readonly StepExecutor $executor,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function run(
        ScenarioDefinition $scenario,
        ScenarioContext $context,
        bool $failFast = false,
    ): ScenarioResult {
        $result = new ScenarioResult($scenario->name);

        $this->output->scenario("Running scenario: {$scenario->name} ({$scenario->getStepCount()} steps)");

        foreach ($scenario->steps as $i => $stepConfig) {
            $stepName = $stepConfig['name'] ?? "step-{$i}";
            $this->output->scenario("  Step {$i}: {$stepName} ({$stepConfig['type']})");

            $stepResult = $this->executor->execute($stepConfig, $context);
            $result->addStep($stepResult);

            if ($stepResult->passed()) {
                $this->output->scenario("    PASS ({$stepResult->durationMs}ms)");
            } else {
                $this->output->error("    FAIL: {$stepResult->details}");

                if ($failFast) {
                    $this->output->scenario("  Fail-fast: aborting remaining steps");

                    break;
                }
            }
        }

        $result->finish();

        $status = $result->passed() ? 'PASSED' : 'FAILED';
        $this->output->scenario("Scenario {$scenario->name}: {$status} ({$result->getPassedCount()}/{$result->getStepCount()} steps)");

        return $result;
    }
}
