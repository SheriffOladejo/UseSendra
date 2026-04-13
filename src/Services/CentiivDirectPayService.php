<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Centiiv Payment API — Direct Pay links.
 *
 * @see https://c-vmch.readme.io/reference/directpaycontroller_create
 */
final class CentiivDirectPayService
{
    private string $jwt;

    private string $baseUrl;

    public function __construct(?string $jwt = null, ?string $baseUrl = null)
    {
        if ($jwt !== null) {
            $this->jwt = trim($jwt);
        } else {
            $raw = $_ENV['CENTIIV_JWT'] ?? getenv('CENTIIV_JWT');
            if ($raw === false || $raw === null || $raw === '') {
                $raw = $_ENV['CENTIV_JWT'] ?? getenv('CENTIV_JWT');
            }
            if ($raw === false || $raw === null || $raw === '') {
                $raw = $_ENV['CENTIIV_API_KEY'] ?? getenv('CENTIIV_API_KEY');
            }
            $this->jwt = trim((string) ($raw ?: ''));
        }
        $this->baseUrl = rtrim(
            $baseUrl ?? (string) ($_ENV['CENTIIV_API_BASE'] ?? getenv('CENTIIV_API_BASE') ?: 'https://api.centiiv.com/api/v1'),
            '/'
        );
    }

    public function isConfigured(): bool
    {
        return $this->jwt !== '';
    }

    /**
     * Create a shareable payment link.
     *
     * @param array{request_id:int,driver_id:int,type:string} $metadata Stored in note for webhook correlation
     * @return array{url:string,link_id:?string,raw:array<string,mixed>}|null
     */
    public function createPaymentLink(
        float $amountNgn,
        string $ourReference,
        array $metadata,
        ?string $successUrl = null,
        ?string $failureUrl = null
    ): ?array {
        if (!$this->isConfigured()) {
            return null;
        }

        $note = $this->encodeLogisticsNote($metadata, $ourReference);

        $payload = [
            'amount' => $amountNgn,
            'note' => $note,
            'currency' => 'NGN',
            'allowedPaymentMethods' => 'NGN',
            // Echoed on payment.successful as data.metadata (Centiiv / Svix)
            'metadata' => [
                'resourceId' => $ourReference,
                'resourceType' => 'sendra_logistics_driver_acceptance',
                'trackingId' => $ourReference,
                'requestId' => (string) ($metadata['request_id'] ?? ''),
                'driverId' => (string) ($metadata['driver_id'] ?? ''),
            ],
        ];

        if ($successUrl !== null && $successUrl !== '') {
            $payload['successUrl'] = $successUrl;
            $payload['success_url'] = $successUrl;
        }
        if ($failureUrl !== null && $failureUrl !== '') {
            $payload['failureUrl'] = $failureUrl;
            $payload['failure_url'] = $failureUrl;
        }

        $raw = $this->postJson('/direct-pay', $payload);
        if ($raw === null) {
            return null;
        }

        $url = $this->extractPaymentUrl($raw);
        if ($url === null || $url === '') {
            error_log('Centiiv direct-pay: no URL in response: ' . json_encode($raw));

            return null;
        }

        $data = $raw['data'] ?? null;
        $linkId = is_array($data) && isset($data['id']) ? (string) $data['id'] : null;

        return ['url' => $url, 'link_id' => $linkId, 'raw' => $raw];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPaymentLink(string $id): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->getJson('/direct-pay/' . rawurlencode($id));
    }

    /**
     * Webhooks send metadata.resourceId as Centiiv payment-link id (e.g. DPL-6fda07), not our DRV_ACCEPT ref.
     * Load the link and read the note we set at creation.
     *
     * @return array{request_id:int,driver_id:int,type:string,reference:string}|null
     */
    /**
     * Find Centiiv payment-link id (e.g. DPL-dd4ac2) anywhere in an API JSON payload or URL string.
     */
    public static function extractDplIdFromPayload(mixed $decoded): ?string
    {
        if (is_string($decoded) && $decoded !== '') {
            if (preg_match('/DPL-[A-Za-z0-9]+/', $decoded, $m)) {
                return $m[0];
            }

            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && preg_match('/DPL-[A-Za-z0-9]+/', $json, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * After createPaymentLink: resolve DPL-* from create response and/or GET by numeric link id.
     */
    public function resolveDplIdAfterCreate(array $created): ?string
    {
        $dpl = self::extractDplIdFromPayload($created['raw'] ?? []);
        if ($dpl !== null) {
            return $dpl;
        }
        $url = $created['url'] ?? '';
        if (is_string($url)) {
            $dpl = self::extractDplIdFromPayload($url);
            if ($dpl !== null) {
                return $dpl;
            }
        }
        $linkId = $created['link_id'] ?? null;
        if ($linkId !== null && (string) $linkId !== '' && $this->isConfigured()) {
            foreach ($this->paymentLinkIdVariants((string) $linkId) as $tryId) {
                $got = $this->getPaymentLink($tryId);
                if (is_array($got)) {
                    $dpl = self::extractDplIdFromPayload($got);
                    if ($dpl !== null) {
                        return $dpl;
                    }
                }
            }
        }

        return null;
    }

    public function resolveSendraFromPaymentLinkId(string $linkId): ?array
    {
        if ($linkId === '') {
            return null;
        }

        foreach ($this->paymentLinkIdVariants($linkId) as $tryId) {
            $decoded = $this->getJson('/direct-pay/' . rawurlencode($tryId));
            if ($decoded === null) {
                continue;
            }

            $data = $decoded['data'] ?? $decoded;
            if (!is_array($data)) {
                continue;
            }

            $note = $data['note'] ?? $data['description'] ?? null;
            if (!is_string($note) || $note === '') {
                continue;
            }

            $parsed = self::parseLogisticsNote($note);
            if ($parsed !== null && ($parsed['type'] ?? '') === 'driver_acceptance_fee') {
                return $parsed;
            }
        }

        error_log('Centiiv GET direct-pay: could not resolve note for link id(s) tried from ' . $linkId);

        return null;
    }

    /**
     * Webhooks send DPL-xxxx; some API builds expect the suffix only.
     *
     * @return list<string>
     */
    private function paymentLinkIdVariants(string $linkId): array
    {
        $linkId = trim($linkId);
        if ($linkId === '') {
            return [];
        }
        $out = [$linkId];
        if (preg_match('/^DPL-(.+)$/i', $linkId, $m) && $m[1] !== '') {
            $out[] = $m[1];
        }

        return array_values(array_unique($out));
    }

    private function encodeLogisticsNote(array $metadata, string $ourReference): string
    {
        $rid = (int) ($metadata['request_id'] ?? 0);
        $did = (int) ($metadata['driver_id'] ?? 0);
        $type = (string) ($metadata['type'] ?? 'driver_acceptance_fee');

        return "sendra|{$type}|{$rid}|{$did}|{$ourReference}";
    }

    /**
     * @return array{request_id:int,driver_id:int,type:string,reference:string}|null
     */
    public static function parseLogisticsNote(?string $note): ?array
    {
        if ($note === null || $note === '') {
            return null;
        }
        if (!str_starts_with($note, 'sendra|')) {
            return null;
        }
        $parts = explode('|', $note, 6);
        if (count($parts) < 5) {
            return null;
        }

        return [
            'type' => $parts[1],
            'request_id' => (int) $parts[2],
            'driver_id' => (int) $parts[3],
            'reference' => $parts[4],
        ];
    }

    /**
     * @param array<string, mixed> $decoded Top-level or wrapped JSON
     */
    private function extractPaymentUrl(array $decoded): ?string
    {
        $candidates = [
            $decoded['url'] ?? null,
            $decoded['paymentUrl'] ?? null,
            $decoded['payment_url'] ?? null,
            $decoded['link'] ?? null,
        ];
        $data = $decoded['data'] ?? null;
        if (is_array($data)) {
            $candidates[] = $data['url'] ?? null;
            $candidates[] = $data['paymentUrl'] ?? null;
            $candidates[] = $data['payment_url'] ?? null;
            $candidates[] = $data['link'] ?? null;
            $candidates[] = $data['checkoutUrl'] ?? null;
        }

        foreach ($candidates as $u) {
            if (is_string($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                return $u;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function postJson(string $path, array $body): ?array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->jwt,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            error_log("Centiiv POST {$path} HTTP {$code} raw: " . substr((string) $response, 0, 500));

            return null;
        }
        if ($code < 200 || $code >= 300) {
            error_log("Centiiv POST {$path} HTTP {$code}: " . json_encode($decoded));

            return null;
        }

        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            error_log("Centiiv POST {$path} success=false: " . json_encode($decoded));

            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $path): ?array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->jwt,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            error_log("Centiiv GET {$path} HTTP {$code} raw: " . substr((string) $response, 0, 500));

            return null;
        }
        if ($code < 200 || $code >= 300) {
            error_log("Centiiv GET {$path} HTTP {$code}: " . json_encode($decoded));

            return null;
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            error_log('Centiiv GET success=false: ' . json_encode($decoded));

            return null;
        }

        return $decoded;
    }
}
