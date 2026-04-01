<?php

return [
    '851265871413834' => [
        'name' => 'aptos',
        'controller' => 'MessageController',
        'db_config' => [
            'live' => [
                'host' => 'localhost',
                'name' => 'sendra_db',
                'user' => 'developer',
                'pass' => 'Stipendly@2026'
            ],
            'replica' => [
                'host' => 'localhost',
                'name' => 'sendra_db_replica',
                'user' => 'developer',
                'pass' => 'Stipendly@2026'
            ]
        ]
    ],
    '1097784993409171' => [
        'name' => 'logistics',
        'controller' => 'LogisticsMessageController',
        'db_config' => [
            'live' => [
                'host' => 'localhost',
                'name' => 'sendra_logistics_db',
                'user' => 'developer',
                'pass' => 'Stipendly@2026'
            ],
            'replica' => [
                'host' => 'localhost',
                'name' => 'sendra_logistics_db_replica',
                'user' => 'developer',
                'pass' => 'Stipendly@2026'
            ]
        ]
    ],
];