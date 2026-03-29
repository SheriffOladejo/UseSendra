<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PayerStep extends BaseStep
{
    protected string $validationError = "⚠️ Please type 'Sender' or 'Receiver'.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = strtolower(trim($message));
        if (!in_array($choice, ['sender','receiver'])) {
            $this->whatsappService->sendButtonMessage(
                $phone,
                "⚠️ Invalid input. Who will pay for the delivery?",
                [
                    'sender' => "Sender",
                    'receiver' => "Receiver"
                ]
            );
            return '';
        };

        $this->deliveryRequestModel->update($requestId, [
            'payer' => $choice
        ]);

        $this->nextStep($phone, 'payment_method');

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Choose a payment method:",
            [
                'cash' => "💵 Cash",
                'transfer' => "💳 Bank Transfer"
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        
        return in_array(strtolower(trim($message)), ['sender','receiver']);
    }
}