<?php

declare(strict_types=1);

namespace App\AutoResponder;

final class AutoResponderConfig
{
    public function __construct(
        public readonly string $action,
        public float $acceptRate = 1.0,
        /** @var array{int, int} */
        public array $responseDelayMs = [50, 200],
        public ?int $rejectErrorCode = null,
        public ?string $rejectErrorText = null,
        // Action-specific fields
        public float $rebootRequiredRate = 0.0,
        public float $notSupportedRate = 0.0,
        public float $failureRate = 0.0,
        public ?string $failureAtStage = null,
        public float $alreadyEndedRate = 0.0,
        public float $unknownKeyRate = 0.0,
        /** @var array{int, int} */
        public array $rebootDurationMs = [3000, 8000],
        /** @var array{int, int} */
        public array $downloadDurationMs = [5000, 15000],
        /** @var array{int, int} */
        public array $installDurationMs = [3000, 10000],
        /** @var array{int, int} */
        public array $collectionDurationMs = [2000, 5000],
        /** @var array{int, int} */
        public array $uploadDurationMs = [1000, 3000],
        public int $progressNotificationIntervalMs = 2000,
        public ?int $alreadyEndedErrorCode = null,
        public ?string $alreadyEndedErrorText = null,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(string $action, array $config): self
    {
        return new self(
            action: $action,
            acceptRate: (float) ($config['accept_rate'] ?? 1.0),
            responseDelayMs: $config['response_delay_ms'] ?? [50, 200],
            rejectErrorCode: isset($config['reject_error_code']) ? (int) $config['reject_error_code'] : null,
            rejectErrorText: $config['reject_error_text'] ?? null,
            rebootRequiredRate: (float) ($config['reboot_required_rate'] ?? 0.0),
            notSupportedRate: (float) ($config['not_supported_rate'] ?? 0.0),
            failureRate: (float) ($config['failure_rate'] ?? 0.0),
            failureAtStage: $config['failure_at_stage'] ?? null,
            alreadyEndedRate: (float) ($config['already_ended_rate'] ?? 0.0),
            unknownKeyRate: (float) ($config['unknown_key_rate'] ?? 0.0),
            rebootDurationMs: $config['reboot_duration_ms'] ?? [3000, 8000],
            downloadDurationMs: $config['download_duration_ms'] ?? [5000, 15000],
            installDurationMs: $config['install_duration_ms'] ?? [3000, 10000],
            collectionDurationMs: $config['collection_duration_ms'] ?? [2000, 5000],
            uploadDurationMs: $config['upload_duration_ms'] ?? [1000, 3000],
            progressNotificationIntervalMs: (int) ($config['progress_notification_interval_ms'] ?? 2000),
            alreadyEndedErrorCode: isset($config['already_ended_error_code']) ? (int) $config['already_ended_error_code'] : null,
            alreadyEndedErrorText: $config['already_ended_error_text'] ?? null,
        );
    }
}
