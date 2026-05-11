<?php

namespace App\Services;

class CentivService
{
    private $apiKey;
    private $baseUrl = 'https://api.centiv.africa/v1';

    public function __construct()
    {
        // Legacy Centiv Africa stub — Sendra Aptos uses {@see SendraCentiivRampService}.
        $this->apiKey = (string) ($_ENV['CENTIV_LEGACY_API_KEY'] ?? getenv('CENTIV_LEGACY_API_KEY') ?: '');
    }

    /**
     * Look up bank account details
     * Returns: ['success' => bool, 'account_name' => string, 'account_number' => string, 'bank_name' => string]
     */
    public function lookupBankAccount($accountNumber, $bankCode)
    {
        $url = "{$this->baseUrl}/bank-account/inquiry";
        
        $payload = [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode
        ];

        $response = $this->makeRequest($url, $payload);

        if ($response['success'] && isset($response['data']['account_name'])) {
            return [
                'success' => true,
                'account_name' => $response['data']['account_name'],
                'account_number' => $accountNumber,
                'bank_name' => $response['data']['bank_name'] ?? 'Unknown Bank'
            ];
        }

        return ['success' => false, 'error' => $response['error'] ?? 'Account lookup failed'];
    }

    /**
     * Get current exchange rate
     * Returns: ['success' => bool, 'rate' => float, 'fee' => float]
     */
    public function getExchangeRate($currency = 'USDC', $amountNaira)
    {
        // Centiv uses USDC as base - check their docs for exact endpoint
        $url = "{$this->baseUrl}/rates";
        
        $payload = [
            'currency' => $currency,
            'amount' => $amountNaira,
            'type' => 'sell' // selling crypto for fiat
        ];

        $response = $this->makeRequest($url, $payload, 'GET');

        if ($response['success']) {
            return [
                'success' => true,
                'rate' => $response['data']['rate'] ?? 0,
                'fee' => $response['data']['fee'] ?? 0,
                'crypto_amount' => $response['data']['crypto_amount'] ?? 0
            ];
        }

        return ['success' => false, 'error' => 'Rate fetch failed'];
    }

    /**
     * Create offramp order (sell crypto for fiat)
     */
    public function createOfframpOrder($orderData)
    {
        $url = "{$this->baseUrl}/orders/create";
        
        $payload = [
            'type' => 'sell',
            'currency' => $orderData['currency'], // USDC or USDT
            'amount' => $orderData['crypto_amount'], // amount in crypto
            'fiat_amount' => $orderData['fiat_amount'], // amount in NGN
            'bank_account' => [
                'account_number' => $orderData['account_number'],
                'bank_code' => $orderData['bank_code'],
                'account_name' => $orderData['account_name']
            ],
            'customer' => [
                'phone' => $orderData['customer_phone'],
                'email' => $orderData['customer_email'] ?? null
            ],
            'reference' => $orderData['reference'], // your unique tx reference
            'callback_url' => $_ENV['CENTIV_WEBHOOK_URL'] ?? null
        ];

        $response = $this->makeRequest($url, $payload);

        if ($response['success']) {
            return [
                'success' => true,
                'order_id' => $response['data']['order_id'],
                'status' => $response['data']['status'],
                'receipt_url' => $response['data']['receipt_url'] ?? null,
                'transaction_reference' => $response['data']['reference'] ?? null
            ];
        }

        return [
            'success' => false, 
            'error' => $response['error'] ?? 'Order creation failed'
        ];
    }

    /**
     * Get order status
     */
    public function getOrderStatus($orderId)
    {
        $url = "{$this->baseUrl}/orders/{$orderId}";
        
        $response = $this->makeRequest($url, [], 'GET');

        if ($response['success']) {
            return [
                'success' => true,
                'status' => $response['data']['status'],
                'receipt_url' => $response['data']['receipt_url'] ?? null
            ];
        }

        return ['success' => false];
    }

    private function makeRequest($url, $payload = [], $method = 'POST')
    {
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method === 'GET' && !empty($payload)) {
            $url .= '?' . http_build_query($payload);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        error_log("Centiv API Request: $method $url | Response: $httpCode | " . $response);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => $data ?? [],
            'error' => $data['message'] ?? $data['error'] ?? null
        ];
    }
}