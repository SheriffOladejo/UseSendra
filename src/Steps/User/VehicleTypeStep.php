<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class VehicleTypeStep extends BaseStep
{
    protected string $validationError = "⚠️ Please select a vehicle type.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = strtolower(trim($message));
        $mapping = ['motorcycle' => 'motorcycle', 'keke' => 'keke', '🏍️' => 'motorcycle', '🛺' => 'keke'];

        if (!isset($mapping[$choice])) {
            $this->whatsappService->sendButtonMessage(
                $phone,
                "Select the delivery vehicle type:",
                [
                    'motorcycle' => "🏍️ Motorcycle",
                    'keke' => "🛺 Keke"
                ]
            );

            return '';
        };

        $this->deliveryRequestModel->update($requestId, [
            'vehicle_type' => $mapping[$choice]
        ]);

        $this->nextStep($phone, 'price_offer');

        // Here, Sendra calculates/estimates a delivery price
        //$estimatedPrice = $this->deliveryRequestModel->calculateEstimatedPrice($requestId);
        $estimatedPrice = 3000; // Placeholder for demonstration
        $this->whatsappService->sendButtonMessage(
            $phone,
            "Great! The estimated delivery price is ₦{$estimatedPrice}.
            \n\nClick yes to proceed or simply type your preferred price. e.g. 2500",
            [
                $estimatedPrice => "Yes, proceed"
            ]
        );
        return '';
    }

    public function validateInput(string $message): bool
    {

        return true;
    }
}