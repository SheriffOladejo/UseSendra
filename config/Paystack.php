<?php

$secret = $_ENV['PAYSTACK_SECRET_KEY'] ?? getenv('PAYSTACK_SECRET_KEY') ?: '';
$base = $_ENV['PAYSTACK_BASE_URL'] ?? getenv('PAYSTACK_BASE_URL') ?: 'https://api.paystack.co';

return [
    'secret_key' => $secret,
    'base_url' => rtrim($base, '/'),
];
