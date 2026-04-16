<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ItemDescriptionStep extends BaseStep
{
    protected string $validationError = "⚠️ Please enter what you are sending (e.g. documents, small box).";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "We couldn't find your delivery request. Please start again.";

        $itemDescription = trim($message);
        if (!$itemDescription) return $this->validationError;

        // Keep compatibility with existing schema: store the same short text as item_name + item_description.
        $shortName = mb_substr($itemDescription, 0, 60);
        $this->deliveryRequestModel->update($requestId, [
            'item_name' => $shortName,
            'item_description' => $itemDescription
        ]);

        if ($this->isEditMode() && $this->getEditTarget() === 'edit_item_desc') {
            $this->finishEditAndShowSummary($phone, (int) $requestId);
            return '';
        }

        $this->nextStep($phone, 'item_picture');

        return "Please send a clear picture of the item.";
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}