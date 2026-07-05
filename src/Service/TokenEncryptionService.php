<?php

namespace App\Service;

/**
 * Encrypts/decrypts OAuth tokens at rest using sodium_crypto_secretbox
 * (XSalsa20-Poly1305 authenticated encryption). The key is a 32-byte
 * secret provided via SOCIAL_TOKEN_ENCRYPTION_KEY, base64-encoded.
 *
 * Generate a key with:
 *   php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
 */
class TokenEncryptionService
{
    private string $key;

    public function __construct(string $socialTokenEncryptionKey)
    {
        // Trim whitespace/newlines — a common artifact of pasting a generated
        // key into a secrets manager or shell heredoc. Never legitimately
        // part of a base64 key, so trimming can't weaken it.
        $socialTokenEncryptionKey = trim($socialTokenEncryptionKey);

        if ($socialTokenEncryptionKey === '') {
            throw new \RuntimeException(
                'SOCIAL_TOKEN_ENCRYPTION_KEY is not set. Generate one with: '
                . 'php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;" '
                . 'and set it in .env.local.'
            );
        }

        $decoded = base64_decode($socialTokenEncryptionKey, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'SOCIAL_TOKEN_ENCRYPTION_KEY must be a base64-encoded ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . '-byte key.'
            );
        }

        $this->key = $decoded;
    }

    /** @return string Base64-encoded (nonce || ciphertext) — safe to store in a text column. */
    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed ciphertext.');
        }

        $nonce      = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt token — wrong key or tampered ciphertext.');
        }

        return $plaintext;
    }
}
