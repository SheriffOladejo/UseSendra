<?php

namespace App\Steps;

use App\Steps\BaseStep;

use App\Utils\Translator;
class LanguageStep extends BaseStep
{
    protected array $languages = [
        'en' => '🇬🇧 English',
        'yo' => '🇳🇬 Yoruba',
        'ig' => '🇳🇬 Igbo',
        'ha' => '🇳🇬 Hausa',
    ];

    protected string $validationError = "Please select a language by tapping a button.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $selectedLang = strtolower(trim($message));

        if (!array_key_exists($selectedLang, $this->languages)) {
            $this->sendLanguageSelectionButtons($phone);
            return '';
        }

        // Save language in DB
        $this->driverModel->update($phone, ['language' => $selectedLang]);

        $this->sessionModel->update($phone, ['language' => $selectedLang]);

        // Move to role selection
        $this->nextStep($phone, 'choose_role');

        $translator = new Translator($selectedLang);

        $this->whatsappService->sendMessage(
            $phone,
            $translator->get('language_selected', [
                'language' => $this->languages[$selectedLang]
            ])
        );

        $this->whatsappService->sendButtonMessage(
            $phone,
            $translator->get('choose_role_prompt'),
            [
                "sender" => $translator->get('role_sender'),
                "rider"  => $translator->get('role_rider')
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return array_key_exists(strtolower(trim($message)), $this->languages);
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
        $this->sendLanguageSelectionButtons($phoneNumber);
    }

    private function sendLanguageSelectionButtons(string $phone): void
    {
        $keys        = array_keys($this->languages);
        $firstBatch  = array_slice($keys, 0, 3);
        $secondBatch = array_slice($keys, 3);

        $buttons1 = [];
        foreach ($firstBatch as $key) {
            $buttons1[$key] = $this->languages[$key];
        }

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Welcome to Sendra Logistics! 🌟\nPlease select your preferred language:",
            $buttons1
        );

        if ($secondBatch !== []) {
            $buttons2 = [];
            foreach ($secondBatch as $key) {
                $buttons2[$key] = $this->languages[$key];
            }
            $this->whatsappService->sendButtonMessage(
                $phone,
                'More languages:',
                $buttons2
            );
        }
    }
}