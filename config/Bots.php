<?php

$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';

$aptosUser = $_ENV['APTOS_DB_USER'] ?? getenv('APTOS_DB_USER') ?: 'developer';
$aptosPass = $_ENV['APTOS_DB_PASS'] ?? getenv('APTOS_DB_PASS') ?: '';
$aptosHost = $_ENV['APTOS_DB_HOST'] ?? getenv('APTOS_DB_HOST') ?: 'localhost';
$aptosName = $_ENV['APTOS_DB_NAME'] ?? getenv('APTOS_DB_NAME') ?: 'sendra_db';
$aptosReplica = $_ENV['APTOS_DB_REPLICA_NAME'] ?? getenv('APTOS_DB_REPLICA_NAME') ?: 'sendra_db_replica';

$logUser = $_ENV['LOGISTICS_DB_USER'] ?? getenv('LOGISTICS_DB_USER') ?: 'developer';
$logPass = $_ENV['LOGISTICS_DB_PASS'] ?? getenv('LOGISTICS_DB_PASS') ?: '';
$logHost = $_ENV['LOGISTICS_DB_HOST'] ?? getenv('LOGISTICS_DB_HOST') ?: 'localhost';
$logName = $_ENV['LOGISTICS_DB_NAME'] ?? getenv('LOGISTICS_DB_NAME') ?: 'sendra_logistics_db';
$logReplica = $_ENV['LOGISTICS_DB_REPLICA_NAME'] ?? getenv('LOGISTICS_DB_REPLICA_NAME') ?: 'sendra_logistics_test_db';

return [
    $aptosPhoneId => [
        'name' => 'aptos',
        'controller' => 'AptosV2Kernel',
        'db_config' => [
            'live' => [
                'host' => $aptosHost,
                'name' => $aptosName,
                'user' => $aptosUser,
                'pass' => $aptosPass,
            ],
            'replica' => [
                'host' => $aptosHost,
                'name' => $aptosReplica,
                'user' => $aptosUser,
                'pass' => $aptosPass,
            ],
        ],
    ],
    $logisticsPhoneId => [
        'name' => 'logistics',
        'controller' => 'LogisticsMessageController',
        'db_config' => [
            'live' => [
                'host' => $logHost,
                'name' => $logName,
                'user' => $logUser,
                'pass' => $logPass,
            ],
            'replica' => [
                'host' => $logHost,
                'name' => $logReplica,
                'user' => $logUser,
                'pass' => $logPass,
            ],
        ],
    ],
];
