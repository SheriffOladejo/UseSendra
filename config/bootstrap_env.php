<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (is_readable($root . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}
