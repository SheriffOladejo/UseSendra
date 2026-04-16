<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ReceiverPhoneStep extends BaseStep
{
    protected string $validationError = "⚠️ Please enter a valid phone number.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $receiverPhone = trim($message);
        if (!preg_match('/^\+?\d{10,15}$/', $receiverPhone)) return $this->validationError;

        $this->deliveryRequestModel->update($requestId, [
            'receiver_phone' => $receiverPhone
        ]);

        if ($this->isEditMode() && in_array($this->getEditTarget(), ['edit_receiver_phone'], true)) {
            $this->finishEditAndShowSummary($phone, (int) $requestId);
            return '';
        }

        $this->nextStep($phone, 'sender_vehicle_type');

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Select the delivery vehicle type:",
            [
                'motorcycle' => "🏍️ Motorcycle",
                'keke' => "🛺 Keke"
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return preg_match('/^\+?\d{10,15}$/', trim($message));
    }
}