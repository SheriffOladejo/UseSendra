<?php

/**
 * Probe Centiiv, 1Click (tokens + Stellar quote), and NEAR status page; write JSON cache for sell gating.
 *
 * Crontab (every 2 minutes), e.g.:
 *   0,2,4,6,8,10,12,14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48,50,52,54,56,58 * * * * php /var/www/html/coinectra/src/scripts/cron_ramp_health.php >> /var/log/sendra_ramp_health.log 2>&1
 *
 * Optional: SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS=0x... (valid Aptos hex) so the Stellar dry quote probe runs.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

$path = \App\Services\SendraRampHealthService::defaultCachePath();
$out = \App\Services\SendraRampHealthService::runProbesAndWriteCache($path);

echo gmdate('c') . ' wrote ' . $path . ' overall=' . (($out['ok'] ?? false) ? 'ok' : 'degraded') . ' summary=' . ($out['summary'] ?? '') . PHP_EOL;

exit(0);
