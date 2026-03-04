<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Logging\ColoredConsoleOutput;
use App\Scenarios\Results\ScenarioResult;
use GuzzleHttp\Client;

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

        // Apply scenario config to context
        $this->applyConfig($scenario->config, $context);

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

    /** @param array<string, mixed> $config */
    private function applyConfig(array $config, ScenarioContext $context): void
    {
        if ($config === []) {
            return;
        }

        $context->csmsBaseUrl = (string) ($config['csms_url'] ?? '');

        // Auto-login if csms_auth is configured
        $auth = $config['csms_auth'] ?? null;
        if (is_array($auth) && $context->csmsJwtToken === '') {
            $this->autoLogin($context, $auth);
        }
    }

    /** @param array<string, mixed> $auth */
    private function autoLogin(ScenarioContext $context, array $auth): void
    {
        $email = (string) ($auth['email'] ?? '');
        $password = (string) ($auth['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->output->error('csms_auth requires email and password');

            return;
        }

        $loginUrl = rtrim($context->csmsBaseUrl, '/') . '/api/v1/auth/login';

        try {
            $client = new Client(['verify' => false, 'timeout' => 30]);
            $response = $client->post($loginUrl, [
                'json' => ['email' => $email, 'password' => $password],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            /** @var array<string, mixed> $data */
            $data = json_decode((string) $response->getBody(), true) ?? [];

            $token = $data['token']
                ?? $data['data']['access_token']
                ?? $data['data']['token']
                ?? $data['access_token']
                ?? null;

            if ($status === 200 && $token !== null) {
                $context->csmsJwtToken = (string) $token;
                $this->output->scenario("CSMS auto-login OK ({$email})");
            } else {
                $error = $data['message'] ?? $data['error'] ?? "HTTP {$status}";
                $this->output->error("CSMS auto-login failed: {$error}");
            }
        } catch (\Throwable $e) {
            $this->output->error("CSMS auto-login error: {$e->getMessage()}");
        }
    }
}
