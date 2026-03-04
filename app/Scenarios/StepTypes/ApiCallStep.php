<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

final class ApiCallStep implements StepInterface
{
    private string $lastMessage = '';
    private ?Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $method = strtoupper($config['method'] ?? 'POST');
        $path = $config['path'] ?? '';
        $body = $config['body'] ?? [];
        $expectStatus = (int) ($config['expect_status'] ?? 200);
        $capture = $config['capture'] ?? [];
        $retryMaxAttempts = (int) ($config['retry_max_attempts'] ?? 1);
        $retryIntervalMs = (int) ($config['retry_interval_ms'] ?? 2000);

        $baseUrl = $context->csmsBaseUrl;
        if ($baseUrl === '') {
            $this->lastMessage = 'No csms_url configured in scenario config';

            return false;
        }

        if ($context->csmsJwtToken === '') {
            $this->lastMessage = 'No JWT token available (set csms_auth in scenario config)';

            return false;
        }

        // Resolve template variables in path and body
        $stationId = $context->getStation(1)?->getStationId() ?? '';
        $path = $this->resolveTemplates($path, $stationId, $context);
        $body = $this->resolveTemplatesRecursive($body, $stationId, $context);

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        for ($attempt = 1; $attempt <= $retryMaxAttempts; $attempt++) {
            $idempotencyKey = bin2hex(random_bytes(16));
            $result = $this->doRequest($method, $url, $body, $expectStatus, $capture, $context, $idempotencyKey);

            if ($result === true) {
                return true;
            }

            if ($attempt < $retryMaxAttempts) {
                usleep($retryIntervalMs * 1000);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $capture
     */
    private function doRequest(
        string $method,
        string $url,
        array $body,
        int $expectStatus,
        array $capture,
        ScenarioContext $context,
        string $idempotencyKey,
    ): bool {
        try {
            $client = $this->getClient();
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $context->csmsJwtToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Idempotency-Key' => $idempotencyKey,
                ],
                'http_errors' => false,
                'timeout' => 30,
            ];

            if ($method !== 'GET' && $method !== 'DELETE' && $body !== []) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            /** @var array<string, mixed> $responseData */
            $responseData = json_decode($responseBody, true) ?? [];

            // Capture values from response
            foreach ($capture as $varName => $jsonPath) {
                $value = $this->extractJsonPath($responseData, $jsonPath);
                $context->captured[$varName] = $value;
                if ($value !== null) {
                    $displayValue = is_scalar($value) ? (string) $value : json_encode($value);
                    fwrite(STDERR, "    [capture] {$varName} = {$displayValue}\n");
                } else {
                    $topKeys = implode(', ', array_keys($responseData));
                    fwrite(STDERR, "    [capture] {$varName} = NULL (path={$jsonPath}, keys=[{$topKeys}])\n");
                }
            }

            if ($statusCode !== $expectStatus) {
                $errorDetail = $responseData['message'] ?? $responseData['error'] ?? $responseBody;
                if (is_array($errorDetail)) {
                    $errorDetail = json_encode($errorDetail);
                }
                $this->lastMessage = "Expected HTTP {$expectStatus}, got {$statusCode}: {$errorDetail}";

                return false;
            }

            $this->lastMessage = "API {$method} → {$statusCode}";

            return true;
        } catch (RequestException $e) {
            $this->lastMessage = "HTTP request failed: {$e->getMessage()}";

            return false;
        } catch (\Throwable $e) {
            $this->lastMessage = "API call error: {$e->getMessage()}";

            return false;
        }
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }

    private function getClient(): Client
    {
        return $this->client ??= new Client(['verify' => false]);
    }

    private function resolveTemplates(string $value, string $stationId, ScenarioContext $context): string
    {
        $value = str_replace('{{stationId}}', $stationId, $value);

        // Resolve {{bayId_N}} patterns (uses same hash as BayState::create)
        if (preg_match_all('/\{\{bayId_(\d+)\}\}/', $value, $matches)) {
            foreach ($matches[1] as $i => $bayNum) {
                $bayId = 'bay_' . substr(md5($stationId . '_' . $bayNum), 0, 12);
                $value = str_replace($matches[0][$i], $bayId, $value);
            }
        }

        // Resolve {{serviceId_N}} patterns (bay N's wash service)
        if (preg_match_all('/\{\{serviceId_(\d+)\}\}/', $value, $matches)) {
            foreach ($matches[1] as $i => $bayNum) {
                $bayHash = substr(md5($stationId . '_' . $bayNum), 0, 12);
                $serviceId = 'svc_' . $bayHash . '_wash';
                $value = str_replace($matches[0][$i], $serviceId, $value);
            }
        }

        // Resolve {{captured.VAR}} patterns
        if (preg_match_all('/\{\{captured\.(\w+)\}\}/', $value, $matches)) {
            foreach ($matches[1] as $i => $varName) {
                $capturedValue = $context->captured[$varName] ?? '';
                $value = str_replace($matches[0][$i], (string) $capturedValue, $value);
            }
        }

        return $value;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function resolveTemplatesRecursive(mixed $data, string $stationId, ScenarioContext $context): mixed
    {
        if (is_string($data)) {
            return $this->resolveTemplates($data, $stationId, $context);
        }

        if (is_array($data)) {
            $resolved = [];
            foreach ($data as $key => $value) {
                $resolvedKey = is_string($key) ? $this->resolveTemplates($key, $stationId, $context) : $key;
                $resolved[$resolvedKey] = $this->resolveTemplatesRecursive($value, $stationId, $context);
            }

            return $resolved;
        }

        return $data;
    }

    private function extractJsonPath(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
