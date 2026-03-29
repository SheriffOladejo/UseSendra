<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ItemDescriptionStep extends BaseStep
{
    protected string $validationError = "⚠️ Please enter a short description for your item.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "We couldn't find your delivery request. Please start again.";

        $itemDescription = trim($message);
        if (!$itemDescription) return $this->validationError;

        $this->deliveryRequestModel->update($requestId, [
            'item_description' => $itemDescription
        ]);

        $this->nextStep($phone, 'item_picture');

        return "Perfect! Please send a clear picture of the item.";
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}