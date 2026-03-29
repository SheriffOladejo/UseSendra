<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class DropoffNotesStep extends BaseStep
{
    protected string $validationError = "⚠️ Please provide a note or comment for the drop-off location.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;

        if (!$requestId) {
            return "We couldn't find your delivery request. Please start again.";
        }

        $note = trim($message);
        if (empty($note)) {
            return $this->validationError;
        }

        // Update the delivery request with dropoff notes
        $this->deliveryRequestModel->update($requestId, [
            'dropoff_notes' => $note
        ]);

        // Move to next step (e.g., package details or confirmation)
        $this->nextStep($phone, 'item_name');

        $this->whatsappService->sendMessage(
            $phone,
            "Got it! Now let's continue with your package details.\n\nWhat are you sending? Please provide the item name\nExample: 'Documents'."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}