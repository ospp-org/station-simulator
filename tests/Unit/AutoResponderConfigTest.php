<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\AutoResponderConfig;
use PHPUnit\Framework\TestCase;

final class AutoResponderConfigTest extends TestCase
{
    public function test_from_array_parses_all_fields(): void
    {
        $config = AutoResponderConfig::fromArray('start_service', [
            'accept_rate' => 0.85,
            'response_delay_ms' => [100, 500],
            'reject_error_code' => 3001,
            'reject_error_text' => 'Bay already in use',
            'reboot_required_rate' => 0.10,
            'not_supported_rate' => 0.05,
            'failure_rate' => 0.02,
            'failure_at_stage' => 'downloading',
            'already_ended_rate' => 0.01,
            'unknown_key_rate' => 0.03,
            'reboot_duration_ms' => [2000, 6000],
            'download_duration_ms' => [4000, 12000],
            'install_duration_ms' => [2000, 8000],
            'collection_duration_ms' => [1000, 4000],
            'upload_duration_ms' => [500, 2000],
            'progress_notification_interval_ms' => 3000,
            'already_ended_error_code' => 3006,
            'already_ended_error_text' => 'No active session',
        ]);

        $this->assertSame('start_service', $config->action);
        $this->assertSame(0.85, $config->acceptRate);
        $this->assertSame([100, 500], $config->responseDelayMs);
        $this->assertSame(3001, $config->rejectErrorCode);
        $this->assertSame('Bay already in use', $config->rejectErrorText);
        $this->assertSame(0.10, $config->rebootRequiredRate);
        $this->assertSame(0.05, $config->notSupportedRate);
        $this->assertSame(0.02, $config->failureRate);
        $this->assertSame('downloading', $config->failureAtStage);
        $this->assertSame(0.01, $config->alreadyEndedRate);
        $this->assertSame(0.03, $config->unknownKeyRate);
        $this->assertSame([2000, 6000], $config->rebootDurationMs);
        $this->assertSame([4000, 12000], $config->downloadDurationMs);
        $this->assertSame([2000, 8000], $config->installDurationMs);
        $this->assertSame([1000, 4000], $config->collectionDurationMs);
        $this->assertSame([500, 2000], $config->uploadDurationMs);
        $this->assertSame(3000, $config->progressNotificationIntervalMs);
        $this->assertSame(3006, $config->alreadyEndedErrorCode);
        $this->assertSame('No active session', $config->alreadyEndedErrorText);
    }

    public function test_from_array_uses_defaults_when_keys_missing(): void
    {
        $config = AutoResponderConfig::fromArray('stop_service', []);

        $this->assertSame('stop_service', $config->action);
        $this->assertSame(1.0, $config->acceptRate);
        $this->assertSame([50, 200], $config->responseDelayMs);
        $this->assertNull($config->rejectErrorCode);
        $this->assertNull($config->rejectErrorText);
        $this->assertSame(0.0, $config->rebootRequiredRate);
        $this->assertSame(0.0, $config->notSupportedRate);
        $this->assertSame(0.0, $config->failureRate);
        $this->assertNull($config->failureAtStage);
        $this->assertSame(0.0, $config->alreadyEndedRate);
        $this->assertSame(0.0, $config->unknownKeyRate);
        $this->assertSame([3000, 8000], $config->rebootDurationMs);
        $this->assertSame([5000, 15000], $config->downloadDurationMs);
        $this->assertSame([3000, 10000], $config->installDurationMs);
        $this->assertSame([2000, 5000], $config->collectionDurationMs);
        $this->assertSame([1000, 3000], $config->uploadDurationMs);
        $this->assertSame(2000, $config->progressNotificationIntervalMs);
        $this->assertNull($config->alreadyEndedErrorCode);
        $this->assertNull($config->alreadyEndedErrorText);
    }

    public function test_constructor_with_direct_values(): void
    {
        $config = new AutoResponderConfig(
            action: 'reset',
            acceptRate: 0.5,
            rebootRequiredRate: 0.25,
        );

        $this->assertSame('reset', $config->action);
        $this->assertSame(0.5, $config->acceptRate);
        $this->assertSame(0.25, $config->rebootRequiredRate);
        $this->assertSame(0.0, $config->failureRate);
    }
}
