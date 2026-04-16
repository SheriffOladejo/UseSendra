<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PackingChecklistStep extends BaseStep
{
    protected string $validationError = 'Please tap "Yes, continue" to proceed.';

    public function handle(array $session, string $message): string
    {
        $phone     = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) {
            return "We couldn't find your delivery request. Please start again.";
        }

        if (strtolower(trim($message)) !== 'yes_continue') {
            $this->whatsappService->sendButtonMessage(
                $phone,
                "Before we proceed, please confirm the packing checklist.\n\nAre you good to go?",
                [
                    'yes_continue' => 'Yes, continue',
                ]
            );
            return '';
        }

        $this->nextStep($phone, 'sender_phone');
        return "Please enter the sender's phone number.";
    }

    public function validateInput(string $message): bool
    {
        return strtolower(trim($message)) === 'yes_continue';
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            "Are you good to go?",
            [
                'yes_continue' => 'Yes, continue',
            ]
        );
    }
}

