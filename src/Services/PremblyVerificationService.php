<?php

declare(strict_types=1);

namespace App\Services;

final class PremblyVerificationService
{
    private string $appId;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $appId = null, ?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->appId = trim((string) ($appId ?? ($_ENV['PREMBLY_APP_ID'] ?? getenv('PREMBLY_APP_ID') ?: '')));
        $this->apiKey = trim((string) ($apiKey ?? ($_ENV['PREMBLY_API_KEY'] ?? getenv('PREMBLY_API_KEY') ?: '')));
        $rawBase = trim((string) ($baseUrl ?? ($_ENV['PREMBLY_BASE_URL'] ?? getenv('PREMBLY_BASE_URL') ?: 'https://api.prembly.com')));
        $this->baseUrl = rtrim($rawBase !== '' ? $rawBase : 'https://api.prembly.com', '/');
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->apiKey !== '';
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function verifyBvnBasic(string $bvn): array
    {
        return $this->postJson('/verification/bvn_validation', ['number' => $bvn]);
    }

    /**
     * NIN/vNIN lookup. For raw 11-digit NIN use number_nin.
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function verifyNin(string $ninOrVnin): array
    {
        $s = trim($ninOrVnin);
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        $body = [];
        if (strlen($digits) === 11) {
            $body = ['number_nin' => $digits];
        } else {
            $body = ['number' => $s];
        }
        return $this->postJson('/verification/vnin', $body);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function verifyVehiclePlate(string $vehicleNumber): array
    {
        $plate = strtoupper(str_replace(' ', '', trim($vehicleNumber)));
        return $this->postJson('/verification/vehicle', ['vehicle_number' => $plate]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function postJson(string $path, array $body): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => [],
                'raw' => null,
                'error' => 'Prembly not configured (set PREMBLY_APP_ID and PREMBLY_API_KEY)',
            ];
        }

        $url = $this->baseUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'app-id: ' . $this->appId,
            'x-api-key: ' . $this->apiKey,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $rawStr = is_string($response) ? $response : null;
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'http_code' => $code ?: 0,
                'data' => [],
                'raw' => $rawStr,
                'error' => $err !== '' ? $err : 'Invalid or empty JSON response',
            ];
        }

        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
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
        foreach (['detail', 'message', 'error'] as $k) {
            if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                return $decoded[$k];
            }
        }
        return null;
    }
}

