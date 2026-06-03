<?php

namespace App\Steps\User;

use App\Services\DeliveryPricingService;
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

        // User accepts the estimated price (button payload "yes")
        if (strtolower($choice) === 'yes') {
            $row = $this->deliveryRequestModel->findById($requestId);
            if ($row && (empty($row['offered_price']) || (float) $row['offered_price'] <= 0)) {
                $est = DeliveryPricingService::fromEnv()->estimateForDeliveryRequest($row, $this->driverModel);
                $this->deliveryRequestModel->update($requestId, ['offered_price' => $est]);
            }

            if ($this->isEditMode() && in_array($this->getEditTarget(), ['edit_price', 'edit_vehicle', 'edit_pickup_location', 'edit_dropoff_location'], true)) {
                $this->finishEditAndShowSummary($phone, (int) $requestId);
                return '';
            }

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

            if ($this->isEditMode() && in_array($this->getEditTarget(), ['edit_price', 'edit_vehicle', 'edit_pickup_location', 'edit_dropoff_location'], true)) {
                $this->finishEditAndShowSummary($phone, (int) $requestId);
                return '';
            }

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
        if (strtolower($choice) === 'yes') {
            return true;
        }
        $numeric = filter_var($choice, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        return is_numeric($numeric) && (float) $numeric > 0;
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }

        $requestId      = $this->session['delivery_request_id'] ?? null;
        $estimatedPrice = 800;
        if ($requestId) {
            $row = $this->deliveryRequestModel->findById($requestId);
            if ($row) {
                if (!empty($row['offered_price']) && (float) $row['offered_price'] > 0) {
                    $estimatedPrice = (float) $row['offered_price'];
                } else {
                    $estimatedPrice = (float) DeliveryPricingService::fromEnv()->estimateForDeliveryRequest($row, $this->driverModel);
                }
            }
        }
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            "The estimated price is ₦{$estimatedPrice}.\n\nTap Yes to proceed or type your preferred price in numbers.",
            [
                'yes' => 'Yes, proceed',
            ]
        );
    }
}