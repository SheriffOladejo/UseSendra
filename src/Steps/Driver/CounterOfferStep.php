<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class CounterOfferStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        $requestId = $session['counter_request_id'];
        $requestModel = $this->deliveryRequestModel->findById($requestId);
        $driver = $this->driverModel->findByPhone($phone);

        if (!isset($session['counter_request_id'])) {
            return "⚠️ Could not find the delivery request. Please try again.";
        }

        $expiry = strtotime($requestModel['bidding_expires_at']);

        if (!empty($requestModel['reservation_expires_at']) && strtotime($requestModel['reservation_expires_at']) < time()) {

            $this->deliveryRequestModel->update($requestId, [
                'status' => 'searching_driver',
                'reserved_driver_id' => null
            ]);
            $requestModel = $this->deliveryRequestModel->findById($requestId);

        }
        else if ($requestModel['status'] === 'driver_payment_pending') {

            if (strtotime($requestModel['reservation_expires_at']) > time()) {
                return "⏳ Another driver is currently securing this delivery. Please wait.";
            }
        }

        if (in_array($requestModel['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
            return "⚠️ This delivery has already been taken.";
        }

        if (time() > $expiry) {
            return "⏱️ This offer has expired. You can no longer make a counteroffer.";
        }

        $amount = floatval($message);

        if ($amount <= 0) {
            return "⚠️ Please enter a valid amount greater than 0.";
        }

        // Create the counter offer
        $this->deliveryOfferModel->create(
            $requestId,
            $driver['id'],
            $amount
        );

        $this->sessionModel->updateStep($phone, 'awaiting_rider_counter'); // optional

        return "✅ Your counter offer of ₦{$amount} has been sent. The customer will review soon.";
    }

    public function validateInput(string $message): bool
    {
        return is_numeric($message) && floatval($message) > 0;
    }
}