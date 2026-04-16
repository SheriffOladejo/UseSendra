<?php

namespace App\Steps\User;

use App\Services\DeliveryPricingService;
use App\Steps\BaseStep;

/**
 * Summary "Edit" flow using WhatsApp List messages (avoids free-text typing).
 * Step key: edit_order
 */
class EditOrderStep extends BaseStep
{
    protected string $validationError = 'Please pick one option from the list.';

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = isset($session['delivery_request_id']) ? (int) $session['delivery_request_id'] : 0;
        if ($requestId < 1) {
            $this->nextStep($phone, 'sender_menu');
            return "We couldn't find your active draft order. Type *send* to start a new delivery.";
        }

        $choice = strtolower(trim($message));

        // Initial entry (or any unknown): show list page 1
        if ($choice === '' || $choice === 'edit' || $choice === 'edit_order' || $choice === 'menu') {
            $this->sendListPage1($phone);
            return '';
        }

        if ($choice === 'edit_more') {
            $this->sendListPage2($phone);
            return '';
        }
        if ($choice === 'edit_back') {
            $this->sendListPage1($phone);
            return '';
        }
        if ($choice === 'edit_cancel') {
            $this->finishEditAndShowSummary($phone, $requestId);
            return '';
        }

        // Mark edit mode and jump to the relevant step with a prompt
        switch ($choice) {
            case 'edit_pickup_location':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'pickup_location');
                return "Update pickup location 📍\n\n"
                    . "Please share the pickup location using WhatsApp's location sharing feature:\n"
                    . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
                    . "2. Select 'Location'\n"
                    . "3. Search on the map or send your current location.";

            case 'edit_dropoff_location':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'dropoff_location');
                return "Update drop-off location 📍\n\n"
                    . "Please share the drop-off location using WhatsApp's location sharing feature:\n"
                    . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
                    . "2. Select 'Location'\n"
                    . "3. Search on the map or send your current location.";

            case 'edit_instructions':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'dropoff_notes');
                return "Please give more context or instructions to the rider.";

            case 'edit_item_desc':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'item_description');
                return "What are you sending? 📦\n(e.g. T-shirt, documents, small box)\n\nReply with a short description of the item.";

            case 'edit_item_photo':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'item_picture');
                return "Please send a clear picture of the item.";

            case 'edit_sender_phone':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'sender_phone');
                return "Please enter the sender's phone number.";

            case 'edit_receiver_phone':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'receiver_phone');
                return "Please enter the receiver’s number 📞";

            case 'edit_vehicle':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'sender_vehicle_type');
                $this->whatsappService->sendButtonMessage(
                    $phone,
                    "What kind of delivery do you prefer?",
                    [
                        'motorcycle' => '🏍 Bike (faster & cheaper)',
                        'keke' => '🛺 Keke (for bigger items)',
                    ]
                );
                return '';

            case 'edit_price':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'price_offer');
                $row = $this->deliveryRequestModel->findById($requestId) ?: [];
                $pricing = DeliveryPricingService::fromEnv();
                $estimatedPrice = $pricing->estimateForDeliveryRequest($row, $this->driverModel);
                $this->deliveryRequestModel->update($requestId, ['offered_price' => $estimatedPrice]);
                $this->whatsappService->sendButtonMessage(
                    $phone,
                    "Great! The estimated delivery price is ₦{$estimatedPrice}.\n\nTap Yes to proceed or type your preferred price. e.g. 2500",
                    [
                        'yes' => 'Yes, proceed',
                    ]
                );
                return '';

            case 'edit_payer':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'payer');
                $this->whatsappService->sendButtonMessage(
                    $phone,
                    "Who will pay for the delivery?",
                    [
                        'sender' => 'Sender (you)',
                        'receiver' => 'Receiver',
                    ]
                );
                return '';

            case 'edit_payment_method':
                $this->setEditMode($choice);
                $this->nextStep($phone, 'payment_method');
                $this->whatsappService->sendButtonMessage(
                    $phone,
                    "How will the payment be made?",
                    [
                        'cash' => '💵 Cash',
                        'transfer' => '🏦 Bank transfer',
                    ]
                );
                return '';
        }

        $this->sendListPage1($phone);
        return '';
    }

    public function validateInput(string $message): bool
    {
        return true;
    }

    private function sendListPage1(string $phone): void
    {
        $this->whatsappService->sendListMessage(
            $phone,
            "What would you like to edit?",
            "Edit order",
            [
                'edit_pickup_location' => 'Pickup location',
                'edit_dropoff_location' => 'Drop-off location',
                'edit_instructions' => 'Rider instructions',
                'edit_item_desc' => 'Item description',
                'edit_item_photo' => 'Item photo',
                'edit_sender_phone' => 'Sender phone',
                'edit_receiver_phone' => 'Receiver phone',
                'edit_vehicle' => 'Vehicle type',
                'edit_price' => 'Offer price',
                'edit_more' => 'More options…',
            ]
        );
    }

    private function sendListPage2(string $phone): void
    {
        $this->whatsappService->sendListMessage(
            $phone,
            "More edit options",
            "More",
            [
                'edit_payer' => 'Who pays',
                'edit_payment_method' => 'Payment method',
                'edit_cancel' => 'Back to summary',
                'edit_back' => 'Back',
            ]
        );
    }
}

