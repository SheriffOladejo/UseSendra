<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Extract Nigerian bank account + bank name from WhatsApp images.
 *
 * Providers (tried in order, configure via SENDRA_BANK_OCR_PROVIDERS):
 *   ocrspace  — free tier at https://ocr.space/ocrapi/freekey (good for photos/handwriting)
 *   gemini    — Google AI Studio key, free quota (vision)
 *   tesseract — local, no API cost (weak on handwriting; uses GD preprocess)
 *   openai    — when funded (best quality; kept for later)
 */
final class BankAccountOcrService
{
    private ?string $lastError = null;

    /** @var list<string> */
    private array $providers;

    public function __construct()
    {
        $raw = trim((string) ($_ENV['SENDRA_BANK_OCR_PROVIDERS'] ?? getenv('SENDRA_BANK_OCR_PROVIDERS') ?: 'gemini'));
        $this->providers = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($this->providers === []) {
            $this->providers = ['ocrspace', 'gemini', 'tesseract', 'openai'];
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isEnabled(): bool
    {
        if (!filter_var(
            (string) ($_ENV['SENDRA_BANK_OCR_ENABLED'] ?? getenv('SENDRA_BANK_OCR_ENABLED') ?: '1'),
            FILTER_VALIDATE_BOOLEAN
        )) {
            return false;
        }

        return $this->hasAnyProvider();
    }

    /**
     * @return array{account_number: string, bank_name: string, source: string}|null
     */
    public function extractFromImageFile(string $path, ?string $caption = null): ?array
    {
        $this->lastError = null;

        if ($caption !== null && trim($caption) !== '') {
            $fromCaption = self::parseFromFreeText($caption);
            if ($fromCaption !== null) {
                $fromCaption['source'] = 'caption';

                return $fromCaption;
            }
        }

        if (!$this->isEnabled()) {
            $this->lastError = 'disabled';

            return null;
        }

        foreach ($this->providers as $provider) {
            $result = match ($provider) {
                'ocrspace' => $this->extractViaOcrSpace($path),
                'gemini' => $this->extractViaGemini($path),
                'tesseract' => $this->extractViaTesseract($path),
                'openai' => $this->extractViaOpenAi($path),
                default => null,
            };

            if ($result !== null) {
                $result['source'] = $provider;

                return $result;
            }

            if ($this->lastError === 'api_quota' || $this->lastError === 'api_key') {
                // Don't keep burning quota on other paid APIs in same request
                if ($provider === 'openai' || $provider === 'gemini') {
                    continue;
                }
            }
        }

        if ($this->lastError === null) {
            $this->lastError = 'parse_failed';
        }

        return null;
    }

    /**
     * @return array{account_number: string, bank_name: string}|null
     */
    public static function parseFromFreeText(string $text): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return null;
        }

        $account = self::extractTenDigitAccount($text);
        if ($account === null) {
            return null;
        }

        $bank = self::guessBankNameFromText($text);
        if ($bank === null) {
            return null;
        }

        return ['account_number' => $account, 'bank_name' => $bank];
    }

    public static function extractTenDigitAccount(string $text): ?string
    {
        if (preg_match('/\b(\d{10})\b/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:\d[\s.\-·\/]{0,3}){10,}/u', $text, $m)) {
            $digits = preg_replace('/\D+/', '', $m[0]);
            if (strlen($digits) === 10) {
                return $digits;
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            if (!preg_match('/\d/', $line)) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $line);
            if (strlen($digits) === 10) {
                return $digits;
            }
        }

        $allDigits = preg_replace('/\D+/', '', $text);
        if ($allDigits !== null && strlen($allDigits) === 10) {
            return $allDigits;
        }

        return null;
    }

    public static function normalizeBankName(string $bank): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/', '', $bank) ?? '');
        $aliases = [
            'gtbank' => 'GTBank',
            'gtb' => 'GTBank',
            'guarantytrust' => 'GTBank',
            'accessbank' => 'Access Bank',
            'access' => 'Access Bank',
            'zenithbank' => 'Zenith Bank',
            'zenith' => 'Zenith Bank',
            'firstbank' => 'First Bank',
            'fbn' => 'First Bank',
            'uba' => 'UBA',
            'opay' => 'OPay',
            'palmpay' => 'PalmPay',
            'kuda' => 'Kuda',
            'moniepoint' => 'Moniepoint',
        ];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        return trim($bank);
    }

    private function hasAnyProvider(): bool
    {
        if ($this->ocrSpaceKey() !== '') {
            return true;
        }
        if ($this->geminiKey() !== '') {
            return true;
        }
        if ($this->openAiKey() !== '') {
            return true;
        }

        return $this->tesseractAvailable();
    }

    private function ocrSpaceKey(): string
    {
        return trim((string) ($_ENV['SENDRA_BANK_OCR_OCRSPACE_API_KEY'] ?? getenv('SENDRA_BANK_OCR_OCRSPACE_API_KEY') ?: ''));
    }

    private function geminiKey(): string
    {
        $d = trim((string) ($_ENV['SENDRA_BANK_OCR_GEMINI_API_KEY'] ?? getenv('SENDRA_BANK_OCR_GEMINI_API_KEY') ?: ''));
        if ($d !== '') {
            return $d;
        }

        return trim((string) ($_ENV['GOOGLE_AI_API_KEY'] ?? getenv('GOOGLE_AI_API_KEY') ?: ''));
    }

    private function openAiKey(): string
    {
        $d = trim((string) ($_ENV['SENDRA_BANK_OCR_OPENAI_API_KEY'] ?? getenv('SENDRA_BANK_OCR_OPENAI_API_KEY') ?: ''));
        if ($d !== '') {
            return $d;
        }

        return trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: ''));
    }

    private function tesseractAvailable(): bool
    {
        if (!filter_var(
            (string) ($_ENV['SENDRA_BANK_OCR_TESSERACT'] ?? getenv('SENDRA_BANK_OCR_TESSERACT') ?: '1'),
            FILTER_VALIDATE_BOOLEAN
        )) {
            return false;
        }
        $bin = trim((string) ($_ENV['SENDRA_BANK_OCR_TESSERACT_BIN'] ?? getenv('SENDRA_BANK_OCR_TESSERACT_BIN') ?: 'tesseract'));

        return $bin !== '' && is_executable($bin);
    }

    /**
     * OCR.space — free API key, 25k req/month. Good for phone photos and handwriting.
     *
     * @return array{account_number: string, bank_name: string}|null
     */
    private function extractViaOcrSpace(string $path): ?array
    {
        $apiKey = $this->ocrSpaceKey();
        if ($apiKey === '') {
            return null;
        }

        $ch = curl_init('https://api.ocr.space/parse/image');
        $post = [
            'apikey' => $apiKey,
            'language' => 'eng',
            'OCREngine' => '2',
            'scale' => 'true',
            'isTable' => 'false',
            'file' => new \CURLFile($path, $this->guessMime($path), basename($path)),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $http !== 200) {
            $this->lastError = 'ocrspace_error';
            error_log('BankAccountOcrService OCR.space HTTP ' . $http);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !empty($decoded['IsErroredOnProcessing'])) {
            $this->lastError = 'ocrspace_error';

            return null;
        }

        $text = (string) ($decoded['ParsedResults'][0]['ParsedText'] ?? '');
        if (trim($text) === '') {
            return null;
        }

        return self::parseFromFreeText($text);
    }

    /**
     * Google Gemini vision — free tier via AI Studio.
     *
     * @return array{account_number: string, bank_name: string}|null
     */
    private function extractViaGemini(string $path): ?array
    {
        $key = $this->geminiKey();
        if ($key === '') {
            return null;
        }

        $model = trim((string) ($_ENV['SENDRA_BANK_OCR_GEMINI_MODEL'] ?? getenv('SENDRA_BANK_OCR_GEMINI_MODEL') ?: 'gemini-2.5-flash-lite'));
        $mime = $this->guessMime($path);
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $body = json_encode([
            'contents' => [[
                'parts' => [
                    ['text' => 'Extract Nigerian bank payout details. Return JSON only: {"account_number":"10 digits","bank_name":"GTBank"}. Handwriting OK.'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            $this->lastError = 'api_error';

            return null;
        }

        $decoded = json_decode($raw, true);
        if ($http !== 200) {
            $err = is_array($decoded) ? json_encode($decoded['error'] ?? $decoded) : $raw;
            error_log('BankAccountOcrService Gemini HTTP ' . $http . ': ' . $err);
            if ($http === 429) {
                $this->lastError = 'api_quota';
            } else {
                $this->lastError = 'api_error';
            }

            return null;
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parseVisionJson(is_string($text) ? $text : '');
    }

    /**
     * OpenAI vision (optional, use when account has quota).
     *
     * @return array{account_number: string, bank_name: string}|null
     */
    private function extractViaOpenAi(string $path): ?array
    {
        $key = $this->openAiKey();
        if ($key === '') {
            return null;
        }

        $mime = $this->guessMime($path);
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $model = trim((string) ($_ENV['SENDRA_BANK_OCR_MODEL'] ?? getenv('SENDRA_BANK_OCR_MODEL') ?: 'gpt-4o-mini'));
        $body = json_encode([
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 300,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract Nigerian NUBAN: JSON {"account_number":"10 digits","bank_name":"GTBank"}.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Account number and bank name from image.'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'high']],
                    ],
                ],
            ],
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            $this->lastError = 'api_error';

            return null;
        }

        $decoded = json_decode($raw, true);
        if ($http !== 200) {
            $errMsg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : $raw;
            $this->lastError = self::classifyOpenAiFailure($errMsg, $http);
            error_log('BankAccountOcrService OpenAI HTTP ' . $http . ': ' . $errMsg);

            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';

        return $this->parseVisionJson(is_string($content) ? $content : '');
    }

    /**
     * @return array{account_number: string, bank_name: string}|null
     */
    private function extractViaTesseract(string $path): ?array
    {
        if (!$this->tesseractAvailable()) {
            return null;
        }

        $prepared = $this->preprocessImageForOcr($path);
        $ocrPath = $prepared ?? $path;

        $bin = trim((string) ($_ENV['SENDRA_BANK_OCR_TESSERACT_BIN'] ?? getenv('SENDRA_BANK_OCR_TESSERACT_BIN') ?: 'tesseract'));
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($ocrPath) . ' stdout -l eng --psm 6 2>/dev/null';
        $text = shell_exec($cmd);
        if ($prepared !== null && $prepared !== $path) {
            @unlink($prepared);
        }

        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        return self::parseFromFreeText($text);
    }

    private function preprocessImageForOcr(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = max(1, (int) ceil(1200 / max($w, 1)));
        $nw = min($w * $scale, 2400);
        $nh = min($h * $scale, 3200);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        imagefilter($dst, IMG_FILTER_CONTRAST, -30);
        imagefilter($dst, IMG_FILTER_BRIGHTNESS, 10);

        $out = sys_get_temp_dir() . '/sendra_ocr_' . bin2hex(random_bytes(6)) . '.png';
        if (!imagepng($dst, $out)) {
            imagedestroy($dst);

            return null;
        }
        imagedestroy($dst);

        return $out;
    }

    private static function classifyOpenAiFailure(string $msg, int $http = 0): string
    {
        $lower = strtolower($msg);
        if ($http === 401 || str_contains($lower, 'incorrect api key') || str_contains($lower, 'invalid_api_key')) {
            return 'api_key';
        }
        if ($http === 429 || str_contains($lower, 'insufficient_quota') || str_contains($lower, 'exceeded your current quota')) {
            return 'api_quota';
        }

        return 'api_error';
    }

    /**
     * @return array{account_number: string, bank_name: string}|null
     */
    private function parseVisionJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::parseFromFreeText($content);
        }

        if (!is_array($decoded)) {
            return self::parseFromFreeText($content);
        }

        $rawAccount = (string) ($decoded['account_number'] ?? $decoded['accountNumber'] ?? '');
        $account = self::extractTenDigitAccount($rawAccount) ?? self::extractTenDigitAccount($content);
        $bankRaw = trim((string) ($decoded['bank_name'] ?? $decoded['bankName'] ?? ''));
        $bank = $bankRaw !== '' ? self::normalizeBankName($bankRaw) : self::guessBankNameFromText($content);

        if ($account === null || $bank === null) {
            return null;
        }

        return ['account_number' => $account, 'bank_name' => $bank];
    }

    private static function guessBankNameFromText(string $text): ?string
    {
        $known = [
            'gtbank' => 'GTBank',
            'gtb' => 'GTBank',
            'guaranty trust' => 'GTBank',
            'access bank' => 'Access Bank',
            'access' => 'Access Bank',
            'zenith' => 'Zenith Bank',
            'uba' => 'UBA',
            'first bank' => 'First Bank',
            'fbn' => 'First Bank',
            'fcmb' => 'FCMB',
            'opay' => 'OPay',
            'palmpay' => 'PalmPay',
            'kuda' => 'Kuda',
            'moniepoint' => 'Moniepoint',
        ];
        $lower = strtolower($text);
        foreach ($known as $needle => $canonical) {
            if (str_contains($lower, $needle)) {
                return $canonical;
            }
        }

        if (preg_match('/\b(GTBANK|GTB|UBA|FCMB|OPAY)\b/i', $text, $m)) {
            return self::normalizeBankName($m[1]);
        }

        return null;
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }

        return 'image/jpeg';
    }
}
