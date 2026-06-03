<?php

namespace App\Services;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/Paystack.php';

        
        $this->secretKey = $config['secret_key'];
        $this->baseUrl = $config['base_url'];

        //error_log("PaystackService initialized with base URL: {$this->baseUrl}. Secret Key: {$this->secretKey}");
    }

    private function request(string $endpoint, array $payload = [])
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->secretKey}",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    public function initializePayment(
        string $email,
        int $amount,
        string $reference,
        string $callbackUrl,
        array $metadata = []
    )
    {
        $payload = [
            "email" => $email,
            "amount" => (int) round((float) $amount * 100), // kobo
            "reference" => $reference,
            "callback_url" => $callbackUrl,
            "metadata" => $metadata
        ];

        return $this->request('/transaction/initialize', $payload);
    }

    public function verifyTransaction(string $reference)
    {
        $url = $this->baseUrl . "/transaction/verify/" . $reference;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->secretKey}"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}