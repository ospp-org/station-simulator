<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;
use Ospp\Protocol\Actions\OsppAction;

final class SendStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $action = $config['action'] ?? '';
        $payload = $config['payload'] ?? [];
        $stationIndex = (int) ($config['station'] ?? 1);
        $messageType = $config['message_type'] ?? 'request';

        if (! OsppAction::isValid($action)) {
            $this->lastMessage = "Invalid action: {$action}";

            return false;
        }

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";

            return false;
        }

        // Resolve template variables
        $payload = $this->resolveTemplates($payload, $station->getStationId(), $station->identity->serialNumber, $context);

        if ($messageType === 'event') {
            $envelope = $context->sender->sendEvent($station, $action, $payload);
        } else {
            $envelope = $context->sender->sendRequest($station, $action, $payload);
        }

        $context->lastSentMessage = $envelope;
        $this->lastMessage = "Sent {$action} ({$envelope->messageType->value})";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveTemplates(array $payload, string $stationId, string $serialNumber, ScenarioContext $context): array
    {
        $resolved = [];
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $value = str_replace('{{stationId}}', $stationId, $value);
                $value = str_replace('{{serialNumber}}', $serialNumber, $value);
                // Resolve {{bayId_N}} patterns
                if (str_contains($value, '{{bayId_')) {
                    $value = (string) preg_replace_callback('/\{\{bayId_(\d+)\}\}/', fn ($m) => 'bay_' . substr(md5($stationId . '_' . $m[1]), 0, 12), $value);
                }
                // Resolve {{serviceId_N}} patterns (bay N's wash service)
                if (str_contains($value, '{{serviceId_')) {
                    $value = (string) preg_replace_callback('/\{\{serviceId_(\d+)\}\}/', fn ($m) => 'svc_' . substr(md5($stationId . '_' . $m[1]), 0, 12) . '_wash', $value);
                }
                // Resolve {{captured.VAR}} — inject scalar as string, array as whole object
                if (str_contains($value, '{{captured.')) {
                    // Check for exact match (entire value is a single captured reference)
                    if (preg_match('/^\{\{captured\.(\w+)\}\}$/', $value, $m)) {
                        $capturedValue = $context->captured[$m[1]] ?? null;
                        if (is_array($capturedValue)) {
                            $resolved[$key] = $capturedValue;

                            continue;
                        }
                        $value = (string) ($capturedValue ?? '');
                    } else {
                        // Inline replacement for string interpolation
                        $value = (string) preg_replace_callback('/\{\{captured\.(\w+)\}\}/', function ($m) use ($context) {
                            $v = $context->captured[$m[1]] ?? '';

                            return is_scalar($v) ? (string) $v : json_encode($v);
                        }, $value);
                    }
                }
            } elseif (is_array($value)) {
                $value = $this->resolveTemplates($value, $stationId, $serialNumber, $context);
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }
}
