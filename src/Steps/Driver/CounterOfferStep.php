<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class CounterOfferStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        if (!isset($session['counter_request_id'])) {
            return "⚠️ Could not find the delivery request. Please try again.";
        }

        $requestId = $session['counter_request_id'];
        $requestModel = $this->deliveryRequestModel->findById($requestId);
        $driver = $this->driverModel->findByPhone($phone);

        if (!$requestModel || !$driver) {
            return '⚠️ Request or rider profile missing. Try again from the job list.';
        }

        $biddingUntil = $requestModel['bidding_expires_at'] ?? null;

        if (($requestModel['status'] ?? '') === 'driver_payment_pending') {
            $expR = $requestModel['reservation_expires_at'] ?? null;
            if ($expR && strtotime($expR) > time()) {
                if ((int) ($requestModel['reserved_driver_id'] ?? 0) !== (int) ($driver['id'] ?? 0)) {
                    return '⏳ Another rider is paying. Wait or try another job.';
                }
                return '💳 Finish using the secure link we sent you.';
            }
            $this->deliveryRequestModel->update($requestId, [
                'status' => 'searching_driver',
                'reserved_driver_id' => null,
                'reservation_expires_at' => null,
                'acceptance_payment_reference' => null,
            ]);
            $requestModel = $this->deliveryRequestModel->findById($requestId);
        }

        if (($requestModel['status'] ?? '') === 'awaiting_rebroadcast') {
            return '⚠️ Customer is deciding on rebroadcast. Try again shortly.';
        }

        if (in_array($requestModel['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
            return "⚠️ This delivery has already been taken.";
        }

        if (!$biddingUntil || time() > strtotime($biddingUntil)) {
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
        if (is_numeric($message) && (float) $message > 0) {
            return true;
        }
        $this->validationError = '⚠️ Please enter a valid amount greater than 0 (numbers only).';

        return false;
    }
}