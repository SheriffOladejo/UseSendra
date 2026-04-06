<?php

namespace App\Steps;

use App\Steps\BaseStep;
use App\Utils\Translator;

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
        $this->nextStep($phone, 'choose_role');
        $translator = new Translator('en'); // Default to English for the greeting
        $this->whatsappService->sendButtonMessage(
            $phone,
            $translator->get('choose_role_prompt', ['name' => $name]),
            [
                "sender" => $translator->get('role_sender'),
                "rider"  => $translator->get('role_rider')
            ]
        );

        return '';
    }

    public function validateInput($message): bool
    {
        return true;
        // Accept any greeting or non-empty text
        //$greetings = ['hi', 'hello', 'hey', 'hola', 'howdy'];
        //$text = strtolower(trim($message));

        //return !empty($text) && (in_array($text, $greetings) || strlen($text) > 1);
    }
}