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
     * Send image message
     * 
     * @param string $to Receiver phone number
     * @param string $imagePath Local file path or public URL
     * @param string $caption Optional caption text
     */
    public function sendImage($to, $imagePath, $caption = '')
    {
        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        // Check if it's a URL or local file path
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            // It's a URL - send directly
            $imageData = [
                "link" => $imagePath
            ];
        } else {
            // It's a local file - upload first, then send
            $mediaId = $this->uploadMedia($imagePath);

            if (!$mediaId) {
                return false;
            }

            $imageData = [
                "id" => $mediaId
            ];
        }

        // Add caption if provided
        if (!empty($caption)) {
            $imageData['caption'] = $caption;
        }

        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "to" => $to,
            "type" => "image",
            "image" => $imageData
        ]);

        return $this->makeRequest($url, $payload);
    }

    /**
     * Upload media file to WhatsApp
     * 
     * @param string $filePath Local file path
     * @return string|false Media ID or false on failure
     */
    private function uploadMedia($filePath)
    {
        if (!file_exists($filePath)) {
            error_log("File not found: {$filePath}");
            return false;
        }

        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/media";

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}"
            ],
            CURLOPT_POSTFIELDS => [
                'messaging_product' => 'whatsapp',
                'file' => new \CURLFile($filePath, mime_content_type($filePath), basename($filePath))
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['id'] ?? false;
        }

        error_log("Media upload failed: {$response}");
        return false;
    }

    /**
     * Download incoming media (e.g. image) from WhatsApp Cloud API to a temp file.
     *
     * @return string|null Absolute path to temp file, or null on failure
     */
    public function downloadMediaToFile(string $mediaId): ?string
    {
        $metaUrl = "https://graph.facebook.com/v20.0/{$mediaId}";

        $ch = curl_init($metaUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            error_log("WhatsApp media meta failed HTTP {$code}: {$body}");
            return null;
        }

        $data = json_decode($body, true);
        $url = $data['url'] ?? null;
        if (!$url) {
            return null;
        }

        $ch2 = curl_init($url);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
            ],
        ]);
        $binary = curl_exec($ch2);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($code2 < 200 || $code2 >= 300 || !is_string($binary) || $binary === '') {
            error_log("WhatsApp media binary download failed HTTP {$code2}");
            return null;
        }

        $mime = $data['mime_type'] ?? 'image/jpeg';
        $ext = str_contains((string) $mime, 'png') ? '.png' : '.jpg';
        $path = sys_get_temp_dir() . '/wa_media_' . bin2hex(random_bytes(8)) . $ext;

        if (file_put_contents($path, $binary) === false) {
            return null;
        }

        return $path;
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
 * Send interactive list message
 */
    public function sendListMessage($to, $bodyText, $buttonLabel, $rows)
    {
        $url = "https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages";

        // WhatsApp list supports max 10 rows per section
        $rowArray = [];
        foreach ($rows as $id => $title) {
            $rowArray[] = [
                "id"    => $id,
                "title" => substr($title, 0, 24) // max 24 chars for list row title
            ];
        }

        $payload = json_encode([
            "messaging_product" => "whatsapp",
            "recipient_type"    => "individual",
            "to"                => $to,
            "type"              => "interactive",
            "interactive"       => [
                "type" => "list",
                "body" => [
                    "text" => $bodyText
                ],
                "action" => [
                    "button"   => $buttonLabel, // the CTA button that opens the list
                    "sections" => [
                        [
                            "title" => "Available Offers",
                            "rows"  => $rowArray
                        ]
                    ]
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

    public function sendTemplateWithButtons($to, $templateName, $parameters = [], $requestId = null)
    {
        return $this->sendTemplate($to, $templateName, $parameters);
    }


    /**
     * Send template message
     */
    public function sendTemplate($to, $templateName, $parameters = [], $languageCode = 'en')
        {
            $url = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";
    
            $components = [];
            
            // Add body parameters if provided
            if (!empty($parameters)) {
                $bodyParameters = [];
                foreach ($parameters as $value) {
                    $bodyParameters[] = [
                        'type' => 'text',
                        'text' => (string)$value
                    ];
                }
    
                $components[] = [
                    'type' => 'body',
                    'parameters' => $bodyParameters
                ];
            }
    
            // NOTE: Button components are NOT added here
            // Buttons are defined in the template itself in Meta Business Manager
            // They are static and cannot be changed per message
    
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => $languageCode
                    ],
                    'components' => $components
                ]
            ];
    
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
    
            $responseData = json_decode($response, true);
    
            if ($httpCode !== 200) {
                error_log("Template send failed to {$to}: " . json_encode($responseData));
            }
    
            return $responseData;
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
    
