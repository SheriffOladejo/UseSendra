<?php

namespace App\Steps;

use App\Steps\BaseStep;

class StartStep extends BaseStep
{
    protected array $languages = [
        'yo' => '🇳🇬 Yoruba',
        'ig' => '🇳🇬 Igbo',
        'ha' => '🇳🇬 Hausa',
        'en' => '🇬🇧 English'
    ];

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $name = $this->getUsername() ?? 'there';

        // Move user to language selection step
        $this->nextStep($phone, 'language');

        // Split buttons into batches of 3 for WhatsApp
        $keys = array_keys($this->languages);
        $firstBatch = array_slice($keys, 0, 3);
        $secondBatch = array_slice($keys, 3);

        $buttons1 = [];
        foreach ($firstBatch as $key) {
            $buttons1[$key] = $this->languages[$key];
        }

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Hello 🙋🏽‍♀️ $name! Welcome to *Sendra Logistics* 🌟\nPlease select your preferred language:",
            $buttons1
        );

        if (!empty($secondBatch)) {
            $buttons2 = [];
            foreach ($secondBatch as $key) {
                $buttons2[$key] = $this->languages[$key];
            }

            $this->whatsappService->sendButtonMessage(
                $phone,
                "More languages:",
                $buttons2
            );
        }

        return '';
    }

    public function validateInput($message): bool
    {
        // Accept any greeting or non-empty text
        $greetings = ['hi', 'hello', 'hey', 'hola', 'howdy'];
        $text = strtolower(trim($message));

        return !empty($text) && (in_array($text, $greetings) || strlen($text) > 1);
    }
}