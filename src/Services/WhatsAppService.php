<?php

namespace App\Services;

class WhatsAppService
{
    private $accessToken;
    private $phoneNumberId;

    public function __construct($accessToken, $phoneNumberId)
    {
        $this->accessToken = $accessToken;
        $this->phoneNumberId = $phoneNumberId;
    }

    /**
     * Send text message
     */
    public function sendMessage($to, $message)
    {
        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "to" => $to,
            "text" => ["body" => $message]
        ]);

        return $this->makeRequest($url, $payload);
    }

    /**
     * Send welcome message to new user
     */
    public function sendWelcomeMessage($to, $userName)
    {
        $message = "Hi 👋 *{$userName}*, I'm Sendra.\n\n" .
            "I help you send, receive and manage USDC/USDT right here on WhatsApp.\n\n" .
            "How would you like to get started?";

        $buttons = [
            //"create_logistics_account" => "Logistics Account",
            "create_account" => "Create an Account",
            "import_account"  => "Import Account"
        ];

        return $this->sendButtonMessage($to, $message, $buttons);
    }

    public function sendSignupSuccessMessage($phoneNumber, $username)
    {
        $message = "🎉 *Welcome to Sendra, $username!*\n\n" .
            "Your account has been successfully created! ✅\n\n" .
            "You can now:\n" .
            "💰 Buy and sell USDT/USDC\n" .
            "📤 Send and receive crypto\n" .
            "💬 Manage everything right here on WhatsApp\n\n" .
            "Type *menu* to see what you can do";

        return $this->sendMessage($phoneNumber, $message);
    }

    /**
     * Delete a WhatsApp message by WAMID (Cloud API)
     */
    public function deleteMessage($wamid)
    {
        if (!$wamid) {
            error_log("deleteMessage called without wamid");
            return false;
        }

        $url = "https://graph.facebook.com/v20.0/{$wamid}";

        error_log("=== Deleting Message ===");
        error_log("DELETE URL: $url");

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}"
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        error_log("Delete HTTP Code: $httpCode");
        error_log("Delete Response: $response");

        if (curl_errno($ch)) {
            error_log("cURL error (delete): " . curl_error($ch));
        }

        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Send interactive button message
     */
    public function sendButtonMessage($to, $bodyText, $buttons)
    {
        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        $buttonArray = [];
        foreach ($buttons as $id => $title) {
            $buttonArray[] = [
                "type" => "reply",
                "reply" => [
                    "id" => $id,
                    "title" => $title
                ]
            ];
        }

        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to,
            "type" => "interactive",
            "interactive" => [
                "type" => "button",
                "body" => [
                    "text" => $bodyText
                ],
                "action" => [
                    "buttons" => $buttonArray
                ]
            ]
        ]);

        return $this->makeRequest($url, $payload);
    }

    /**
     * Send Call-to-Action (CTA) URL button message
     * This hides the URL behind a button - user only sees the button text
     */
    public function sendCTAUrlButton($to, $bodyText, $buttonText, $url)
    {

        $apiUrl = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to,
            "type" => "interactive",
            "interactive" => [
                "type" => "cta_url",
                "body" => [
                    "text" => $bodyText
                ],
                "action" => [
                    "name" => "cta_url",
                    "parameters" => [
                        "display_text" => $buttonText,
                        "url" => $url
                    ]
                ]
            ]
        ];

        $payloadJson = json_encode($payload);
        error_log("CTA Button Payload: " . $payloadJson);

        $result = $this->makeRequest($apiUrl, $payloadJson);

        error_log("CTA Button Result: " . json_encode($result));
        error_log("=== sendCTAUrlButton completed ===");

        return $result;
    }


    /**
     * Send template message
     */
    public function sendTemplate($to, $templateName, $languageCode = 'en_US')
    {
        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to,
            "type" => "template",
            "template" => [
                "name" => $templateName,
                "language" => ["code" => $languageCode]
            ]
        ]);

        return $this->makeRequest($url, $payload);
    }

    /**
     * Extract user info from webhook payload
     */
    public function extractUserInfo($data)
    {
        $userInfo = [
            'phone' => null,
            'username' => null
        ];

        if (isset($data['entry'])) {
            foreach ($data['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    $value = $change['value'] ?? [];

                    if (isset($value['contacts'][0])) {
                        $contact = $value['contacts'][0];
                        $userInfo['phone'] = $contact['wa_id'] ?? null;
                        $userInfo['username'] = $contact['profile']['name'] ?? null;
                    }

                    if (isset($value['messages'][0]['from'])) {
                        $userInfo['phone'] = $value['messages'][0]['from'];
                    }
                }
            }
        }

        return $userInfo;
    }

    /**
     * Make HTTP request to WhatsApp API
     */
    private function makeRequest($url, $payload)
    {
        error_log("=== Making API Request ===");
        error_log("URL: $url");
        error_log("Payload: $payload");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        error_log("HTTP Response Code: $httpCode");
        error_log("HTTP Response Body: $response");

        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            error_log("cURL error: $curlError");
        }

        curl_close($ch);

        $result = [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'response' => json_decode($response, true)
        ];

        error_log("Request result: " . json_encode($result));
        error_log("=== API Request Complete ===");

        return $result;
    }

    /**
     * Send wallet connection URL
     */
    public function sendWalletConnectionURL($to, $phoneNumber, $username)
    {
        $url = "https://usesendra.com/client/?phoneNumber=" . urlencode($phoneNumber) . "&username=" . urlencode($username);

        $message = "🔗 *Create your Account*\n\n" .
            "Please click the link below to create an account:\n\n" .
            "$url\n\n" .
            "Once created, we can continue with getting you onboard! 🚀";

        return $this->sendMessage($to, $message);
    }

/**
 * Download media from WhatsApp in two steps:
 * 1. Get the media URL from media ID
 * 2. Download the actual media content
 */
public function downloadMedia(string $mediaId): string
{
    error_log("Starting media download for ID: $mediaId");

    // Step 1: Get the media URL from the media ID
    $mediaUrl = $this->getMediaUrl($mediaId);
    
    error_log("Media URL retrieved: $mediaUrl");

    // Step 2: Download the actual media content
    return $this->downloadMediaContent($mediaUrl);
}

/**
 * Step 1: Get media URL from media ID
 */
private function getMediaUrl(string $mediaId): string
{
    $url = "https://graph.facebook.com/v21.0/{$mediaId}";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $this->accessToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    curl_close($ch);

    if ($curlErrno) {
        error_log("cURL error getting media URL ($curlErrno): $curlError");
        throw new \Exception("cURL error: $curlError");
    }

    if ($httpCode !== 200) {
        error_log("Failed to get media URL. HTTP Code: $httpCode. Response: " . $response);
        throw new \Exception("Failed to get media URL. HTTP Code: $httpCode");
    }

    $data = json_decode($response, true);
    
    if (!isset($data['url'])) {
        error_log("No URL in media response: " . $response);
        throw new \Exception("Media URL not found in response");
    }

    return $data['url'];
}

/**
 * Step 2: Download the actual media file
 */
private function downloadMediaContent(string $mediaUrl): string
{
    error_log("Downloading media content from: $mediaUrl");

    $ch = curl_init($mediaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $this->accessToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    curl_close($ch);

    // Log everything useful
    error_log("Media download HTTP code: $httpCode");
    error_log("Media content-type: $contentType");

    if ($curlErrno) {
        error_log("cURL error downloading media ($curlErrno): $curlError");
        throw new \Exception("cURL error: $curlError");
    }

    if ($httpCode !== 200) {
        error_log("Media download failed. Response length: " . strlen($response));
        throw new \Exception("Failed to download media from WhatsApp. HTTP Code: $httpCode");
    }

    error_log("Media downloaded successfully. Size: " . strlen($response) . " bytes");
    
    return $response;
}
}
