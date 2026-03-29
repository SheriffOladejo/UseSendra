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
        $this->nextStep($phone, 'dropoff_location');

        $this->whatsappService->sendMessage(
            $phone,
            "Thanks! Now, please send the drop-off location using whatsapp location sharing:\n" .
            "1. Tap the '+' icon\n" .
            "2. Select 'Location'\n" .
            "3. Pick a spot on the map"
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}