<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class CounterOfferStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        if (!isset($session['counter_request_id']) || (int) $session['counter_request_id'] < 1) {
            $this->sessionModel->update($phone, ['counter_request_id' => null]);
            $this->nextStep($phone, 'rider_ready');
            return "⚠️ That counter-offer prompt is no longer active.\n\nSay *hi* to see the rider menu.";
        }

        $requestId = (int) $session['counter_request_id'];
        $requestModel = $this->deliveryRequestModel->findById($requestId);
        $driver = $this->driverModel->findByPhone($phone);

        if (!$requestModel || !$driver) {
            $this->sessionModel->update($phone, ['counter_request_id' => null]);
            $this->nextStep($phone, 'rider_ready');
            return '⚠️ Request or rider profile missing. You are back on standby — say *hi* anytime.';
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
            $this->sessionModel->update($phone, ['counter_request_id' => null]);
            $this->nextStep($phone, 'rider_ready');
            return '⚠️ This job is paused while the customer decides. You are back on standby — say *hi* anytime.';
        }

        if (in_array($requestModel['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
            $this->sessionModel->update($phone, ['counter_request_id' => null]);
            $this->nextStep($phone, 'rider_ready');
            return "⚠️ This delivery has already been taken.\n\nYou are back on standby — say *hi* anytime.";
        }

        if (!$biddingUntil || time() > strtotime($biddingUntil)) {
            $this->sessionModel->update($phone, ['counter_request_id' => null]);
            $this->nextStep($phone, 'rider_ready');
            return "⏱️ This offer has expired. You can no longer make a counteroffer.\n\nSay *hi* to see the rider menu.";
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

        // Clear counter context and return rider to standby.
        $this->sessionModel->update($phone, ['counter_request_id' => null]);
        $this->nextStep($phone, 'rider_ready');

        return "✅ Your counter offer of ₦{$amount} has been sent. The customer will review soon.\n\nSay *hi* anytime for the rider menu.";
    }

    public function validateInput(string $message): bool
    {
        if (is_numeric($message) && (float) $message > 0) {
            return true;
        }
        $this->validationError = '⚠️ Please enter a valid amount in ₦ (numbers only), or say *hi* to exit this counter step.';

        return false;
    }
}