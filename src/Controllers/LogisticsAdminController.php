<?php

namespace App\Controllers;

use App\Services\LogisticsAdminApiService;
use App\Services\WebhookAuthService;

final class LogisticsAdminController
{
    public function __construct(private LogisticsAdminApiService $api) {}

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
        $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;
        $sub = $segments[2] ?? null;

        try {
            if ($method === 'GET') {
                $this->handleGet($resource, $_GET);
                return;
            }
            if ($method === 'POST' && $resource === 'riders' && $id !== null && $sub === 'verification') {
                $body = $this->jsonBody();
                $ok = $this->api->updateRiderVerification($id, (string) ($body['action'] ?? ''));
                $this->json(['ok' => $ok]);
                return;
            }
            $this->json(['error' => 'Not found'], 404);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('LogisticsAdminController: ' . $e->getMessage());
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

        $payload = match ($resource) {
            'overview' => $this->api->overview(),
            'customers' => $this->api->customers($limit, $offset, $search),
            'riders' => $this->api->riders($limit, $offset, $search),
            'orders' => $this->api->orders($limit, $offset, $search),
            'analytics' => $this->api->analytics($limit, $offset, $search),
            default => null,
        };

        if ($payload === null) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $this->json($payload);
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
        return trim((string) ($_ENV['LOGISTICS_ADMIN_PASSWORD'] ?? getenv('LOGISTICS_ADMIN_PASSWORD') ?: ''));
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
