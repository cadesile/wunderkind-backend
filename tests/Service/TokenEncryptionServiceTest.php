<?php

namespace App\Tests\Service;

use App\Service\TokenEncryptionService;
use PHPUnit\Framework\TestCase;

class TokenEncryptionServiceTest extends TestCase
{
    private function makeKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $service = new TokenEncryptionService($this->makeKey());

        $plaintext = 'EAABsbCS1234567890abcdefSuperSecretAccessToken';
        $encrypted = $service->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted, 'Ciphertext must not equal plaintext');
        $this->assertSame($plaintext, $service->decrypt($encrypted));
    }

    public function testEncryptingTheSamePlaintextTwiceProducesDifferentCiphertext(): void
    {
        $service = new TokenEncryptionService($this->makeKey());

        $a = $service->encrypt('same-token-value');
        $b = $service->encrypt('same-token-value');

        $this->assertNotSame($a, $b, 'Each encryption must use a fresh nonce');
        $this->assertSame('same-token-value', $service->decrypt($a));
        $this->assertSame('same-token-value', $service->decrypt($b));
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $encrypted = (new TokenEncryptionService($this->makeKey()))->encrypt('a-token');
        $wrongKeyService = new TokenEncryptionService($this->makeKey());

        $this->expectException(\RuntimeException::class);
        $wrongKeyService->decrypt($encrypted);
    }

    public function testDecryptTamperedCiphertextThrows(): void
    {
        $service   = new TokenEncryptionService($this->makeKey());
        $encrypted = $service->encrypt('a-token');

        // Flip a byte in the ciphertext to simulate tampering.
        $raw          = base64_decode($encrypted, true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered     = base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        $service->decrypt($tampered);
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOCIAL_TOKEN_ENCRYPTION_KEY is not set');
        new TokenEncryptionService('');
    }

    public function testWrongLengthKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        new TokenEncryptionService(base64_encode('too-short'));
    }

    public function testKeyWithTrailingNewlineIsAccepted(): void
    {
        // A common artifact of copying a generated key into a secrets
        // manager or shell heredoc — must not be treated as invalid.
        $service = new TokenEncryptionService($this->makeKey() . "\n");

        $encrypted = $service->encrypt('a-token');
        $this->assertSame('a-token', $service->decrypt($encrypted));
    }

    public function testKeyWithSurroundingWhitespaceIsAccepted(): void
    {
        $service = new TokenEncryptionService("  " . $this->makeKey() . "  \n");

        $encrypted = $service->encrypt('a-token');
        $this->assertSame('a-token', $service->decrypt($encrypted));
    }
}
