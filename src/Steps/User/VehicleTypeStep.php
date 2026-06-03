<?php

namespace App\Steps\User;

use App\Services\DeliveryPricingService;
use App\Steps\BaseStep;
use App\Utils\VehicleTypeHelper;

class VehicleTypeStep extends BaseStep
{
    protected string $validationError = "⚠️ Please select a vehicle type.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) {
            return 'Delivery request not found.';
        }

        $normalized = VehicleTypeHelper::normalize($message);
        if ($normalized === null) {
            $this->sendCustomerVehiclePicker($phone);

            return '';
        }

        $this->deliveryRequestModel->update($requestId, [
            'vehicle_type' => $normalized,
        ]);

        $this->nextStep($phone, 'price_offer');

        $row            = $this->deliveryRequestModel->findById($requestId) ?: [];
        $pricing        = DeliveryPricingService::fromEnv();
        $estimatedPrice = $pricing->estimateForDeliveryRequest($row, $this->driverModel);
        $this->deliveryRequestModel->update($requestId, ['offered_price' => $estimatedPrice]);

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Great! The estimated delivery price is ₦{$estimatedPrice}.\n\n"
            . "Click yes to proceed or simply type your preferred price. e.g. 2500",
            [
                'yes' => 'Yes, proceed',
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return VehicleTypeHelper::normalize($message) !== null;
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
        $this->sendCustomerVehiclePicker($phoneNumber);
    }

    private function sendCustomerVehiclePicker(string $phone): void
    {
        $this->whatsappService->sendListMessage(
            $phone,
            "Select the delivery vehicle type:",
            'Choose vehicle',
            VehicleTypeHelper::customerListRows()
        );
    }
}
