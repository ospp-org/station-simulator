<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Services\SignCertificateService;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\CertificateType;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class TriggerCertificateRenewalHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly SignCertificateService $signCertificateService,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $certificateType = $payload['certificateType'] ?? '';

        $this->output->info("TriggerCertificateRenewal for {$stationId}: type={$certificateType}");

        if (CertificateType::tryFrom($certificateType) === null) {
            $this->sender->sendResponse($station, OsppAction::TRIGGER_CERTIFICATE_RENEWAL, [
                'status' => 'Rejected',
                'errorCode' => 5001,
                'errorText' => 'Invalid certificate type',
            ], $envelope);

            return;
        }

        $behaviorConfig = $station->config->getBehaviorFor('trigger_certificate_renewal') ?? [];
        $config = AutoResponderConfig::fromArray('trigger_certificate_renewal', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [50, 150];

        $this->delay->afterDelay("trigger-cert-renewal:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $certificateType): void {
            $decision = $this->decider->decide($config);

            if ($decision === ResponseDecision::ACCEPTED) {
                $this->sender->sendResponse($station, OsppAction::TRIGGER_CERTIFICATE_RENEWAL, [
                    'status' => 'Accepted',
                ], $envelope);

                $this->signCertificateService->requestSigning($station, $certificateType);
            } else {
                $this->sender->sendResponse($station, OsppAction::TRIGGER_CERTIFICATE_RENEWAL, [
                    'status' => 'Rejected',
                    'errorCode' => 5001,
                    'errorText' => 'Renewal rejected by auto-responder',
                ], $envelope);
            }
        });
    }
}
