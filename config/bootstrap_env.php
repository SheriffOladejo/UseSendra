<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (is_readable($root . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}

$aptosServiceRoot = $root . '/aptos-service';
if (is_readable($aptosServiceRoot . '/.env')) {
    $aptosServiceDotenv = Dotenv\Dotenv::createImmutable($aptosServiceRoot);
    $aptosServiceDotenv->safeLoad();
}

// Unify log file for webhook + CLI/cron (optional; e.g. same path as webhook.php ini for php-debug.log)
$sendraPhpLog = (string) ($_ENV['SENDRA_PHP_ERROR_LOG'] ?? getenv('SENDRA_PHP_ERROR_LOG') ?: '');
if ($sendraPhpLog !== '') {
    @ini_set('log_errors', '1');
    @ini_set('error_log', $sendraPhpLog);
}
