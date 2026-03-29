<?php

namespace App\Steps;

use App\Services\WhatsAppService;

class PaymentWhoStep implements StepInterface
{
    private $sessionModel;
    private $whatsappService;

    public function __construct($sessionModel, WhatsAppService $whatsappService)
    {
        $this->sessionModel = $sessionModel;
        $this->whatsappService = $whatsappService;
    }

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        if (!$message) {

            $this->whatsappService->sendButtonMessage(
                $phone,
                "Who will pay for the delivery?",
                [
                    "sender_pay" => "🙋 Sender",
                    "receiver_pay" => "📦 Receiver"
                ]
            );

            return '';
        }

        $this->sessionModel->updateData($phone, [
            'payer' => $message
        ]);

        $this->sessionModel->updateStep($phone, 'payment_method');

        return "How will the payment be made?";
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}