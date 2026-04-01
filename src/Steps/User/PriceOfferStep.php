<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PriceOfferStep extends BaseStep
{
    protected string $validationError = "⚠️ Please type 'yes' to accept the estimated price, or enter your preferred price in numbers.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = trim($message);

        // User accepts the estimated price
        if (strtolower($choice) === 'yes') {

            $this->nextStep($phone, 'payer');

            $this->whatsappService->sendButtonMessage(
                $phone,
                "Great! Who will pay for the delivery?",
                [
                    'sender' => "Sender",
                    'receiver' => "Receiver"
                ]
            );

            return '';
        }

        // User enters a numeric price
        $numericPrice = filter_var($choice, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        if (is_numeric($numericPrice) && $numericPrice > 0) {
            $this->deliveryRequestModel->update($requestId, [
                'offered_price' => $numericPrice
            ]);

            $this->nextStep($phone, 'payer');

            $this->whatsappService->sendButtonMessage(
                $phone,
                "Got it! Your price offer to riders will be ₦{$numericPrice}.\n\nWho will pay for the delivery?",
                [
                    'sender' => "Sender",
                    'receiver' => "Receiver"
                ]
            );

            return '';
        }

        // Invalid input
        return $this->validationError;
    }

    public function validateInput(string $message): bool
    {
        $choice = trim($message);
        if (strtolower($choice) === 'yes') return true;
        if (is_numeric(filter_var($choice, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)) && $choice > 0) return true;
        return false;
    }
}