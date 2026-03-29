<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class FindCourierStep extends BaseStep
{
    protected string $validationError = "⚠️ Please wait while we find a courier.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;

        if (!$requestId) {
            return "Delivery request not found.";
        }

        // Fetch delivery request details
        $request = $this->deliveryRequestModel->findById($requestId);
        if (!$request) {
            return "Could not find your delivery request.";
        }

        // Example: mark request as 'searching_driver'
        $this->deliveryRequestModel->update($requestId, [
            'status' => 'searching_driver'
        ]);

        // Logic: fetch nearby riders (pseudo code)
        $latitude = $request['pickup_lat'];
        $longitude = $request['pickup_lng'];

        //$nearbyRiders = $this->driverModel->findNearby($latitude, $longitude);
        $nearbyRiders = [];

        if (!empty($nearbyRiders)) {
            // Assign first available rider (simplified)
            $assignedRider = $nearbyRiders[0];
            $this->deliveryRequestModel->update($requestId, [
                'rider_id' => $assignedRider['id'],
                'status' => 'assigned'
            ]);

            // Notify user
            return "🎉 Good news! A courier has been found for your delivery.\n\n" .
                   "Courier: {$assignedRider['name']}\n" .
                   "Phone: {$assignedRider['phone']}\n\n" .
                   "You can track your delivery in real-time.";
        }

        // If no riders found
        $this->whatsappService->sendButtonMessage(
            $phone,
            "We are still searching for a courier near you. ⏳\n\nYou can try again or cancel the request.",
            [
                'refresh' => "🔄 Refresh",
                'cancel'  => "❌ Cancel Delivery"
            ]
        );

        return "We are searching for a courier nearby. You can press 'Refresh' to check again or 'Cancel' to stop the request.";
    }

    public function validateInput(string $message): bool
    {
        $message = strtolower(trim($message));
        return in_array($message, ['refresh','cancel']);
    }
}