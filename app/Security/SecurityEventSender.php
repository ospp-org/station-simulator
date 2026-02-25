<?php

declare(strict_types=1);

namespace App\Security;

use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;

final class SecurityEventSender
{
    public const TYPE_MAC_VERIFICATION_FAILURE = 'MacVerificationFailure';
    public const TYPE_CERTIFICATE_ERROR = 'CertificateError';
    public const TYPE_UNAUTHORIZED_ACCESS = 'UnauthorizedAccess';
    public const TYPE_OFFLINE_PASS_REJECTED = 'OfflinePassRejected';
    public const TYPE_TAMPER_DETECTED = 'TamperDetected';
    public const TYPE_BRUTE_FORCE_ATTEMPT = 'BruteForceAttempt';
    public const TYPE_FIRMWARE_INTEGRITY_FAILURE = 'FirmwareIntegrityFailure';

    private const SEVERITY_MAP = [
        self::TYPE_MAC_VERIFICATION_FAILURE => 'Warning',
        self::TYPE_CERTIFICATE_ERROR => 'Warning',
        self::TYPE_UNAUTHORIZED_ACCESS => 'Info',
        self::TYPE_OFFLINE_PASS_REJECTED => 'Info',
        self::TYPE_TAMPER_DETECTED => 'Critical',
        self::TYPE_BRUTE_FORCE_ATTEMPT => 'Warning',
        self::TYPE_FIRMWARE_INTEGRITY_FAILURE => 'Critical',
    ];

    public function __construct(
        private readonly MessageSender $sender,
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public function send(
        SimulatedStation $station,
        string $eventType,
        array $details = [],
    ): void {
        $payload = [
            'type' => $eventType,
            'severity' => self::SEVERITY_MAP[$eventType] ?? 'Info',
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'details' => $details,
        ];

        $this->sender->sendEvent($station, OsppAction::SECURITY_EVENT, $payload);

        $station->emit('security.event', [
            'eventType' => $eventType,
            'severity' => $payload['severity'],
        ]);
    }

    /** @return list<string> */
    public static function getEventTypes(): array
    {
        return [
            self::TYPE_MAC_VERIFICATION_FAILURE,
            self::TYPE_CERTIFICATE_ERROR,
            self::TYPE_UNAUTHORIZED_ACCESS,
            self::TYPE_OFFLINE_PASS_REJECTED,
            self::TYPE_TAMPER_DETECTED,
            self::TYPE_BRUTE_FORCE_ATTEMPT,
            self::TYPE_FIRMWARE_INTEGRITY_FAILURE,
        ];
    }
}
