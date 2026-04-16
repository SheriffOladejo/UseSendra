<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Centiiv liquidity aggregator — conversion orders (on-ramp / off-ramp).
 *
 * Used by Sendra Aptos (WhatsApp). Do not reuse logistics Direct Pay
 * ({@see CentiivDirectPayService}); credentials and base URL differ.
 *
 * @see https://c-vmch.readme.io/reference/createorder
 */
final class CentiivAggregatorService
{
    private string $token;

    private string $baseUrl;

    private ?string $platformHeader;

    public function __construct(?string $token = null, ?string $baseUrl = null, ?string $platformHeader = null)
    {
        if ($token !== null) {
            $this->token = trim($token);
        } else {
            $raw = $_ENV['CENTIIV_AGGREGATOR_JWT'] ?? getenv('CENTIIV_AGGREGATOR_JWT');
            if ($raw === false || $raw === null || $raw === '') {
                $raw = $_ENV['CENTIIV_AGGREGATOR_API_KEY'] ?? getenv('CENTIIV_AGGREGATOR_API_KEY');
            }
            $this->token = trim((string) ($raw ?: ''));
        }

        $this->baseUrl = rtrim(
            $baseUrl ?? (string) ($_ENV['CENTIIV_AGGREGATOR_BASE_URL'] ?? getenv('CENTIIV_AGGREGATOR_BASE_URL') ?: 'https://api.centiiv.io'),
            '/'
        );

        if ($platformHeader !== null) {
            $this->platformHeader = $platformHeader !== '' ? $platformHeader : null;
        } else {
            $p = $_ENV['CENTIIV_AGGREGATOR_PLATFORM'] ?? getenv('CENTIIV_AGGREGATOR_PLATFORM');
            $this->platformHeader = is_string($p) && $p !== '' ? $p : null;
        }
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Create a conversion order (PENDING until user funds deposit address / virtual account).
     *
     * Body must match Centiiv docs: fromAsset, toAsset, amount, and branch-specific fields
     * (beneficiary for off-ramp, destinationAddress for on-ramp, webhookUrl, metadata, etc.).
     *
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function createConversionOrder(array $body): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => [],
                'raw' => null,
                'error' => 'Centiiv aggregator not configured (set CENTIIV_AGGREGATOR_JWT or CENTIIV_AGGREGATOR_API_KEY)',
            ];
        }

        return $this->postJson('/public/order', $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function postJson(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->platformHeader !== null) {
            $headers[] = 'X-Platform: ' . $this->platformHeader;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawStr = is_string($response) ? $response : null;
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            error_log("Centiiv aggregator POST {$path} HTTP {$code} raw: " . substr((string) $response, 0, 500));

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => $rawStr,
                'error' => 'Invalid or empty JSON response',
            ];
        }

        if ($code < 200 || $code >= 300) {
            error_log("Centiiv aggregator POST {$path} HTTP {$code}: " . json_encode($decoded));

            return [
                'success' => false,
                'http_code' => $code,
                'data' => $decoded,
                'raw' => $rawStr,
                'error' => $this->extractErrorMessage($decoded),
            ];
        }

        return [
            'success' => true,
            'http_code' => $code,
            'data' => $decoded,
            'raw' => $rawStr,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): ?string
    {
        foreach (['message', 'error', 'detail'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && $decoded[$k] !== '') {
                return $decoded[$k];
            }
        }

        return null;
    }
}
