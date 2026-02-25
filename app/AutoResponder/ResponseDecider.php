<?php

declare(strict_types=1);

namespace App\AutoResponder;

final class ResponseDecider
{
    public function decide(AutoResponderConfig $config): ResponseDecision
    {
        $roll = mt_rand(0, 10000) / 10000;

        // Check not_supported first (for change_configuration)
        if ($roll < $config->notSupportedRate) {
            return ResponseDecision::NOT_SUPPORTED;
        }

        // Check reboot_required (for change_configuration)
        if ($roll < $config->notSupportedRate + $config->rebootRequiredRate) {
            return ResponseDecision::REBOOT_REQUIRED;
        }

        // Check accept/reject
        if ($roll < $config->notSupportedRate + $config->rebootRequiredRate + $config->acceptRate) {
            return ResponseDecision::ACCEPTED;
        }

        return ResponseDecision::REJECTED;
    }

    public function shouldFail(AutoResponderConfig $config): bool
    {
        return mt_rand(0, 10000) / 10000 < $config->failureRate;
    }

    public function shouldReportAlreadyEnded(AutoResponderConfig $config): bool
    {
        return mt_rand(0, 10000) / 10000 < $config->alreadyEndedRate;
    }

    public function shouldReportUnknownKey(AutoResponderConfig $config): bool
    {
        return mt_rand(0, 10000) / 10000 < $config->unknownKeyRate;
    }
}
