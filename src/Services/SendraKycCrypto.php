<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Encrypts KYC address payloads at rest (AES-256-GCM).
 */
final class SendraKycCrypto
{
    private const VERSION = 'v1';

    public function encrypt(string $plaintext): string
    {
        $key = $this->deriveKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Failed to encrypt KYC payload');
        }

        return self::VERSION . ':'
            . base64_encode($iv) . ':'
            . base64_encode($tag) . ':'
            . base64_encode($cipher);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Invalid encrypted KYC payload');
        }

        $iv = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $cipher = base64_decode($parts[3], true);
        if ($iv === false || $tag === false || $cipher === false) {
            throw new \RuntimeException('Invalid encrypted KYC payload encoding');
        }

        $key = $this->deriveKey();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt KYC payload');
        }

        return $plain;
    }

    private function deriveKey(): string
    {
        $raw = trim((string) ($_ENV['KYC_DATA_ENCRYPTION_KEY'] ?? getenv('KYC_DATA_ENCRYPTION_KEY') ?: ''));
        if ($raw === '') {
            $raw = PinConfirmTokenService::secret();
        }
        if ($raw === '') {
            throw new \RuntimeException('KYC encryption key not configured');
        }

        if (ctype_xdigit($raw) && strlen($raw) === 64) {
            $bin = hex2bin($raw);
            if ($bin !== false) {
                return $bin;
            }
        }

        return hash('sha256', $raw, true);
    }
}
