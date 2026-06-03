<?php

namespace App\Steps\User;

use App\Models\LogisticsReview;
use App\Steps\BaseStep;

class ChooseOfferStep extends BaseStep
{
    private const RESTART_HINT = "\n\nType *restart* to return to the main menu.";

    public function validateInput($message, $lang = 'en'): bool
    {
        if (is_numeric($message) && (float) $message > 0) {
            return true;
        }

        $this->validationError = 'Please enter a valid amount in ₦ to counter, or tap one of the Accept buttons.'
            . self::RESTART_HINT;

        return false;
    }

    public function handle($session, $message): string
    {
        $requestId   = $session['pending_delivery_request'] ?? null;
        $phoneNumber = $session['phone_number'];

        if (!$requestId) {
            $this->sessionModel->updateStep($phoneNumber, 'sender_menu');

            return '⚠️ No active delivery found. Start with *send*.' . self::RESTART_HINT;
        }

        $requestModel = $this->deliveryRequestModel->findById($requestId);
        if (!$requestModel) {
            $this->sessionModel->updateStep($phoneNumber, 'sender_menu');

            return '⚠️ That delivery no longer exists. Type *send* for a new one.' . self::RESTART_HINT;
        }

        if (($requestModel['status'] ?? '') === 'driver_payment_pending') {
            $expR = $requestModel['reservation_expires_at'] ?? null;
            if ($expR && strtotime($expR) > time()) {
                return '⏳ A rider is confirming this job. Please wait a moment.' . self::RESTART_HINT;
            }
            $this->deliveryRequestModel->update($requestId, [
                'status'                   => 'searching_driver',
                'reserved_driver_id'       => null,
                'reservation_expires_at'   => null,
                'acceptance_payment_reference' => null,
            ]);
            $requestModel = $this->deliveryRequestModel->findById($requestId);
        }

        if (($requestModel['status'] ?? '') === 'awaiting_rebroadcast') {
            return '⚠️ Search is paused. Use the *Ping* or *Cancel* buttons on the last bot message, or start fresh below.'
                . self::RESTART_HINT;
        }

        if (in_array($requestModel['status'], ['driver_assigned', 'in_transit', 'delivered'], true)) {
            return '⚠️ This delivery has already been taken.' . self::RESTART_HINT;
        }

        $pendingOffers = $this->deliveryOfferModel->findInReviewByRequest($requestId);
        if ($pendingOffers === []) {
            return '⚠️ No open offers to counter right now. Use *Find Courier* if the bot shows it.' . self::RESTART_HINT;
        }

        $offerModel  = $pendingOffers[0];
        $updated_at  = strtotime($offerModel['updated_at']);

        if (time() - $updated_at > 90) {
            $this->nextStep($phoneNumber, 'find_courier');

            $this->whatsappService->sendButtonMessage(
                $phoneNumber,
                '⏱️ This offer has expired. You can no longer make a counteroffer.',
                [
                    'confirm' => '🚀 Find Courier',
                ]
            );

            return '';
        }

        $customerPrice = (float) $message;
        $this->deliveryRequestModel->update($requestId, ['offered_price' => $customerPrice]);

        $rev = new LogisticsReview($this->deliveryRequestModel->getDb());
        $senderSummary = $rev->summaryForSenderUser((int) ($requestModel['user_id'] ?? 0));
        $customerStars = LogisticsReview::formatAverageStarsLine('Customer', $senderSummary);

        foreach ($pendingOffers as $offer) {
            $this->deliveryOfferModel->updateStatus($offer['id'], 'countered');
            $driver = $this->driverModel->findById($offer['driver_id']);
            if (!$driver) {
                continue;
            }

            $this->whatsappService->sendButtonMessage(
                $driver['phone'],
                $customerStars . "\n\n💸 The customer has countered with ₦" . number_format($customerPrice, 0) . " for delivery #{$requestId}. Do you accept?",
                [
                    "accept_{$requestId}"  => '✅ Accept',
                    "counter_{$requestId}" => '💬 Counter Again',
                ]
            );
        }

        $this->sessionModel->updateStep($phoneNumber, 'customer_choose_offer');

        return '✅ Your counteroffer of ₦' . number_format($customerPrice, 0) . ' has been sent to the drivers. Waiting for their response.';
    }
}
