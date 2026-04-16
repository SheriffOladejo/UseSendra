<?php

namespace App\Steps\User;

use App\Steps\BaseStep;
use App\Utils\DeliverySummaryFormatter;

class ConfirmationStep extends BaseStep
{
    protected string $validationError = 'Please tap Confirm or Edit below to continue.';

    private function resendSummaryWithButtons(string $phoneNumber, ?int $requestId): void
    {
        $request = $requestId ? $this->deliveryRequestModel->findById($requestId) : null;
        $body = is_array($request) && !empty($request)
            ? DeliverySummaryFormatter::format($request)
            : 'Please choose an option to proceed with your order:';

        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            $body,
            [
                'confirm' => '✅ Confirm',
                'edit'    => '✏️ Edit',
            ]
        );
    }

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = strtolower(trim($message));
        if ($choice === 'edit') {
            $this->nextStep($phone, 'edit_order');
            // Immediately show edit options (WhatsApp button max is 3, so use List).
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
            return '';
        }

        if ($choice !== 'confirm') {
            $this->resendSummaryWithButtons($phone, (int) $requestId);
            return '';
        }

        $this->deliveryRequestModel->update($requestId, [
            'status' => 'searching_driver',
        ]);

        $this->nextStep($phone, 'find_courier');

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Request confirmed! Next: Find a courier.",
            [
                'confirm' => "🚀 Find a Courier"
            ]
        );

        // Service that sends request to nearby couriers and updates request with assigned courier details
        //$this->courierMatchingService->findCourierForRequest($requestId);

        return "";
    }

    public function validateInput(string $message): bool
    {
        return in_array(strtolower(trim($message)), ['confirm','edit']);
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
        $requestId = isset($this->session['delivery_request_id']) ? (int) $this->session['delivery_request_id'] : null;
        $this->resendSummaryWithButtons($phoneNumber, $requestId);
    }
}