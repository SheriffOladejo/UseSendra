<?php

namespace App\Steps\User;

use App\Steps\BaseStep;


class ChooseOfferStep extends BaseStep
{
    public function validateInput($message, $lang = 'en'): bool
    {
        // Valid if it's a number (counteroffer) — button clicks (accepting_offer_) are handled upstream
        if (is_numeric($message) && (float)$message > 0) {
            return true;
        }

        $this->validationError = "Please enter a valid amount in ₦ to counter, or tap one of the Accept buttons.";
        return false;
    }

    public function handle($session, $message): string
    {

        $requestId   = $session['pending_delivery_request'] ?? null;
        $offerModel = $this->deliveryOfferModel->findInReviewByRequest($requestId)[0];
        $phoneNumber = $session['phone_number'];
        $requestModel = $this->deliveryRequestModel->findById($requestId);
        $updated_at = strtotime($offerModel['updated_at']);

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

        if (time() - $updated_at > 90) {
            $this->nextStep($phoneNumber, 'find_courier');
            
            $this->whatsappService->sendButtonMessage(
                $phoneNumber,
                '⏱️ This offer has expired. You can no longer make a counteroffer.',
                [
                    'confirm' => "🚀 Find Courier"
                ]
            );
                return "";
        }

        if (!$requestId) {
            $this->sessionModel->updateStep($phoneNumber, 'sender_menu');
            return "⚠️ No active delivery found. Please start a new delivery request.";
        }

        $customerPrice = (float) $message;
        $pendingOffers = $this->deliveryOfferModel->findInReviewByRequest($requestId);

        if (empty($pendingOffers)) {
            return "⚠️ No in-review offers found for your delivery.";
        }

        // Update request's offered price to customer's counter
        $this->deliveryRequestModel->update($requestId, ['offered_price' => $customerPrice]);

        // Notify each driver
        foreach ($pendingOffers as $offer) {
            $this->deliveryOfferModel->updateStatus($offer['id'], 'countered');
            $driver = $this->driverModel->findById($offer['driver_id']);
            if (!$driver) continue;

            $this->whatsappService->sendButtonMessage(
                $driver['phone'],
                "💸 The customer has countered with ₦" . number_format($customerPrice, 0) . " for delivery #{$requestId}. Do you accept?",
                [
                    "accept_{$requestId}"  => "✅ Accept",
                    "counter_{$requestId}" => "💬 Counter Again",
                ]
            );
        }

        // Keep customer in this step waiting for driver response
        $this->sessionModel->updateStep($phoneNumber, 'customer_choose_offer');

        return "✅ Your counteroffer of ₦" . number_format($customerPrice, 0) . " has been sent to the drivers. Waiting for their response.";
    }
}