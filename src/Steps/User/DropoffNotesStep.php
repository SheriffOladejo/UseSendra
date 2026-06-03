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

        if ($this->isEditMode() && $this->getEditTarget() === 'edit_instructions') {
            $this->finishEditAndShowSummary($phone, (int) $requestId);
            return '';
        }

        // Move to package context (short flow: one prompt before photo)
        $this->nextStep($phone, 'item_description');

        $this->whatsappService->sendMessage(
            $phone,
            "What are you sending? 📦\n"
            . "(e.g. T-shirt, documents, small box)\n\n"
            . "Reply with a short description of the item."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}