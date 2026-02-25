<?php

declare(strict_types=1);

namespace App\Generators;

use Ospp\Protocol\Crypto\CanonicalJsonSerializer;
use Ospp\Protocol\Crypto\EcdsaService;

final class ReceiptGenerator
{
    private readonly EcdsaService $ecdsaService;

    private ?string $privateKeyPem = null;
    private ?string $publicKeyPem = null;

    public function __construct()
    {
        $this->ecdsaService = new EcdsaService(new CanonicalJsonSerializer());
    }

    public function loadKeys(string $privateKeyPath, string $publicKeyPath): void
    {
        if (file_exists($privateKeyPath)) {
            $this->privateKeyPem = file_get_contents($privateKeyPath) ?: null;
        }

        if (file_exists($publicKeyPath)) {
            $this->publicKeyPem = file_get_contents($publicKeyPath) ?: null;
        }
    }

    public function generateKeys(): void
    {
        $keyPair = $this->ecdsaService->generateKeyPair();
        $this->privateKeyPem = $keyPair['privateKey'];
        $this->publicKeyPem = $keyPair['publicKey'];
    }

    /**
     * @param array<string, mixed> $receiptData
     * @return array<string, mixed>
     */
    public function signReceipt(array $receiptData): array
    {
        if ($this->privateKeyPem === null) {
            $this->generateKeys();
        }

        $signature = $this->ecdsaService->signOfflinePass($receiptData, $this->privateKeyPem);

        $receiptData['signature'] = $signature;
        $receiptData['signatureAlgorithm'] = 'ECDSA-P256-SHA256';

        return $receiptData;
    }

    /** @param array<string, mixed> $receiptData */
    public function verifyReceipt(array $receiptData): bool
    {
        if ($this->publicKeyPem === null) {
            return false;
        }

        return $this->ecdsaService->verifyOfflinePass($receiptData, $this->publicKeyPem);
    }

    public function getPublicKeyPem(): ?string
    {
        return $this->publicKeyPem;
    }
}
