<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class SenderPhoneStep extends BaseStep
{
    protected string $validationError = "⚠️ Please enter a valid phone number.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "We couldn't find your delivery request.";

        $senderPhone = trim($message);
        if (!preg_match('/^\+?\d{10,15}$/', $senderPhone)) return $this->validationError;

        $this->deliveryRequestModel->update($requestId, [
            'sender_phone' => $senderPhone
        ]);

        if ($this->isEditMode() && in_array($this->getEditTarget(), ['edit_sender_phone'], true)) {
            $this->finishEditAndShowSummary($phone, (int) $requestId);
            return '';
        }

        $this->nextStep($phone, 'receiver_phone');

        return "Got it! Now, enter the receiver's phone number.";
    }

    public function validateInput(string $message): bool
    {
        return preg_match('/^\+?\d{10,15}$/', trim($message));
    }
}