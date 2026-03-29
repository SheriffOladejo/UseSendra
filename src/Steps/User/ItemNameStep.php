<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ItemNameStep extends BaseStep
{
    protected string $validationError = "⚠️ Please enter the name of the item.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "We couldn't find your delivery request. Please start again.";

        $itemName = trim($message);
        if (!$itemName) return $this->validationError;

        $this->deliveryRequestModel->update($requestId, [
            'item_name' => $itemName
        ]);

        $this->nextStep($phone, 'item_description');

        return "Got it! Now, please enter a brief description of the item for the rider.";
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}