<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PickupNotesStep extends BaseStep
{
    protected string $validationError = "⚠️ Please provide a note or comment for the pickup location.";

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

        // Update the delivery request with pickup notes
        $this->deliveryRequestModel->update($requestId, [
            'pickup_notes' => $note
        ]);

        // Move to dropoff location step
        $this->nextStep($phone, 'dropoff_address');

        return "Thanks! Now we need to know where it is going?
                \nEnter the delivery address (e.g. *765 Fola Osibo, Lekki Phase 1, Lagos*)";
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}