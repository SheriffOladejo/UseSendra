<?php

namespace App\Controllers;

use App\Services\AptosAdminApiService;
use App\Services\SendraRampHealthService;
use App\Services\SendraTxMonitorService;
use App\Services\WebhookAuthService;

final class AptosAdminController
{
    public function __construct(private AptosAdminApiService $api) {}

    public function handle(string $method, string $path): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $this->requireAuth();

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $resource = $segments[0] ?? '';

        try {
            if ($method === 'GET') {
                $this->handleGet($resource, $_GET);
                return;
            }
            if ($method === 'POST') {
                $this->handlePost($resource, $segments);
                return;
            }
            $this->json(['error' => 'Not found'], 404);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('AptosAdminController: ' . $e->getMessage());
            $this->json(['error' => 'Internal server error'], 500);
        }
    }

    private function handleGet(string $resource, array $query): void
    {
        $limit = min(200, max(1, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $search = isset($query['search']) ? trim((string) $query['search']) : null;
        if ($search === '') {
            $search = null;
        }
        $kyc = isset($query['kyc']) ? trim((string) $query['kyc']) : null;
        if ($kyc === '' || $kyc === 'all') {
            $kyc = null;
        }
        $type = isset($query['type']) ? trim((string) $query['type']) : null;
        if ($type === '' || $type === 'all') {
            $type = null;
        }
        $status = isset($query['status']) ? trim((string) $query['status']) : null;
        if ($status === '' || $status === 'all') {
            $status = null;
        }
        $sort = isset($query['sort']) ? trim((string) $query['sort']) : 'last_added';

        $qSearch = isset($query['q']) ? trim((string) $query['q']) : ($search ?? '');

        if ($resource === 'customer-kyc-proof') {
            $this->api->streamSourceOfWealthProof((int) ($query['id'] ?? 0));
            return;
        }

        $payload = match ($resource) {
            'overview' => $this->api->overview(),
            'customers' => $this->api->customers($limit, $offset, $search, $kyc, $sort),
            'customer-kyc' => $this->api->customerKyc((int) ($query['id'] ?? 0)),
            'transactions' => $this->api->transactions($limit, $offset, $search, $type, $status),
            'analytics' => $this->api->analytics(),
            'support-tickets' => $this->api->supportTickets($limit, $offset, $search),
            'inspect', 'lookup' => $this->api->inspectTransaction($qSearch),
            'system-status' => $this->systemStatus(),
            'monitoring' => $this->monitor()->list($query),
            default => null,
        };

        if ($payload === null) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $this->json($payload);
    }

    /**
     * @param list<string> $segments
     */
    private function handlePost(string $resource, array $segments): void
    {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            $body = [];
        }

        if ($resource === 'monitoring' && ($segments[1] ?? '') === 'recipients') {
            $emails = $body['emails'] ?? [];
            if (!is_array($emails)) {
                throw new \InvalidArgumentException('emails must be a list');
            }
            $this->json(['recipients' => $this->monitor()->setRecipients($emails)]);
            return;
        }

        if ($resource === 'monitoring' && ($segments[1] ?? '') === 'reported') {
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                throw new \InvalidArgumentException('id is required');
            }
            $ok = $this->monitor()->markReported($id);
            $this->json(['ok' => $ok], $ok ? 200 : 404);
            return;
        }

        $this->json(['error' => 'Not found'], 404);
    }

    private function monitor(): SendraTxMonitorService
    {
        $ref = new \ReflectionClass($this->api);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        /** @var \PDO $db */
        $db = $prop->getValue($this->api);

        return new SendraTxMonitorService($db);
    }

    private function requireAuth(): void
    {
        if (WebhookAuthService::skipInternalAuth()) {
            return;
        }

        $password = $this->adminPassword();
        if ($password === '') {
            $this->json(['error' => 'Admin password not configured'], 503);
            exit;
        }

        $provided = trim((string) ($_SERVER['HTTP_X_ADMIN_SECRET'] ?? ''));
        if ($provided === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
            if (str_starts_with(strtolower($auth), 'bearer ')) {
                $provided = trim(substr($auth, 7));
            }
        }

        if ($provided === '' || !hash_equals($password, $provided)) {
            $this->json(['error' => 'Unauthorized'], 401);
            exit;
        }
    }

    private function adminPassword(): string
    {
        return trim((string) ($_ENV['APTOS_ADMIN_PASSWORD'] ?? getenv('APTOS_ADMIN_PASSWORD') ?: ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function systemStatus(): array
    {
        $cached = SendraRampHealthService::readCacheFile();
        $now    = time();

        if ($cached === null) {
            return [
                'ok'         => false,
                'checked_at' => null,
                'age_seconds' => null,
                'stale'      => true,
                'gate_enabled' => SendraRampHealthService::isHealthGateEnabled(),
                'services'   => [
                    ['key' => 'centiiv',         'label' => 'Centiiv (Bank Payouts)', 'ok' => null, 'skipped' => false, 'ms' => null, 'http' => null, 'error' => 'No cache — run cron_ramp_health.php'],
                    ['key' => 'oneclick_tokens', 'label' => '1Click Tokens API',      'ok' => null, 'skipped' => false, 'ms' => null, 'http' => null, 'error' => 'No cache'],
                    ['key' => 'oneclick_stellar','label' => '1Click Stellar Route',   'ok' => null, 'skipped' => true,  'ms' => null, 'http' => null, 'error' => null],
                    ['key' => 'near_status',     'label' => 'NEAR / SmoothSend',      'ok' => null, 'skipped' => false, 'ms' => null, 'http' => null, 'error' => 'No cache'],
                ],
                'summary' => 'Cache missing — health status unavailable',
            ];
        }

        $checkedAt  = $cached['checked_at'] ?? null;
        $ageSeconds = $checkedAt ? ($now - (int) strtotime((string) $checkedAt)) : null;
        $stale      = !SendraRampHealthService::cacheIsFresh($cached);

        $build = static function (string $key, string $label, ?array $data): array {
            if ($data === null) {
                return ['key' => $key, 'label' => $label, 'ok' => null, 'skipped' => false, 'ms' => null, 'http' => null, 'error' => 'Missing'];
            }
            return [
                'key'          => $key,
                'label'        => $label,
                'ok'           => isset($data['ok']) ? (bool) $data['ok'] : null,
                'skipped'      => !empty($data['skipped']),
                'ms'           => isset($data['ms']) ? (float) $data['ms'] : null,
                'http'         => isset($data['http']) ? (int) $data['http'] : null,
                'error'        => isset($data['error']) ? (string) $data['error'] : null,
                'note'         => isset($data['note'])  ? (string) $data['note']  : null,
                'informational'=> !empty($data['informational']),
                'correlationId'=> isset($data['correlationId']) ? (string) $data['correlationId'] : null,
            ];
        };

        return [
            'ok'           => (bool) ($cached['ok'] ?? false),
            'checked_at'   => $checkedAt,
            'age_seconds'  => $ageSeconds,
            'stale'        => $stale,
            'gate_enabled' => SendraRampHealthService::isHealthGateEnabled(),
            'services'     => [
                $build('centiiv',          'Centiiv (Bank Payouts)', $cached['centiiv']          ?? null),
                $build('oneclick_tokens',  '1Click Tokens API',      $cached['oneclick_tokens']  ?? null),
                $build('oneclick_stellar', '1Click Stellar Route',   $cached['oneclick_stellar'] ?? null),
                $build('near_status',      'NEAR / SmoothSend',      $cached['near_status']      ?? null),
            ],
            'summary'      => (string) ($cached['summary'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
