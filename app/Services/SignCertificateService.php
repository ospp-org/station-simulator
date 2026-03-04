<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;

final class SignCertificateService
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function requestSigning(SimulatedStation $station, string $certificateType): void
    {
        $stationId = $station->getStationId();

        $csrPem = $this->generateCsr($stationId);

        $this->sender->sendRequest($station, OsppAction::SIGN_CERTIFICATE, [
            'certificateType' => $certificateType,
            'csr' => $csrPem,
        ]);

        $this->output->info("SignCertificate request sent for {$stationId}: type={$certificateType}");
    }

    private function generateCsr(string $stationId): string
    {
        $privKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privKey === false) {
            // Fallback to placeholder if OpenSSL is unavailable
            return "-----BEGIN CERTIFICATE REQUEST-----\n"
                . base64_encode(random_bytes(256)) . "\n"
                . "-----END CERTIFICATE REQUEST-----";
        }

        $dn = ['CN' => $stationId, 'O' => 'OSPP Station Simulator'];
        $csr = openssl_csr_new($dn, $privKey);

        if ($csr === false) {
            return "-----BEGIN CERTIFICATE REQUEST-----\n"
                . base64_encode(random_bytes(256)) . "\n"
                . "-----END CERTIFICATE REQUEST-----";
        }

        openssl_csr_export($csr, $csrPem);

        return $csrPem;
    }
}
