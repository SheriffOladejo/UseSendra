<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

/**
 * Spawn background worker for off-ramp execution after web PIN confirm.
 */
final class OfframpAfterPinWorker
{
    public static function spawn(string $pendingId, string $phone): void
    {
        $root = dirname(__DIR__, 4);
        $script = $root . '/src/scripts/run_offramp_after_pin.php';
        if (!is_readable($script)) {
            error_log('Sendra offramp worker script missing: ' . $script);

            return;
        }

        $php = self::resolveCliPhpBinary();
        $logDir = $root . '/var/log';
        $log = $logDir . '/offramp_after_pin.log';
        if (!is_dir($logDir) || !is_writable($logDir)) {
            $log = rtrim(sys_get_temp_dir(), '/') . '/sendra_offramp_after_pin.log';
        }

        $cmd = sprintf(
            '%s %s %s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($pendingId),
            escapeshellarg($phone),
            escapeshellarg($log)
        );
        error_log("Sendra v2 offramp worker spawn pending={$pendingId} phone={$phone} log={$log}");
        exec($cmd);
    }

    private static function resolveCliPhpBinary(): string
    {
        $envPhp = trim((string) ($_ENV['SENDRA_CLI_PHP'] ?? getenv('SENDRA_CLI_PHP') ?: ''));
        if ($envPhp !== '' && is_executable($envPhp) && stripos(basename($envPhp), 'fpm') === false) {
            return $envPhp;
        }

        foreach (['/usr/bin/php', '/usr/bin/php8.2', '/usr/bin/php8.1', '/usr/local/bin/php'] as $candidate) {
            if (is_executable($candidate) && stripos(basename($candidate), 'fpm') === false) {
                return $candidate;
            }
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(basename(PHP_BINARY), 'fpm') === false) {
            return PHP_BINARY;
        }

        return 'php';
    }
}
