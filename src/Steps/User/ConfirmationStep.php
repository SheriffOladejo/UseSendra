<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ConfirmationStep extends BaseStep
{
    protected string $validationError = 'Please tap Confirm or Edit below to continue.';

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = strtolower(trim($message));
        if ($choice === 'edit') {
            $this->nextStep($phone, 'summary');
            return "You can edit your delivery details now.";
        }

        if ($choice !== 'confirm') {
            $this->whatsappService->sendButtonMessage(
                $phone,
                'Please choose an option to proceed with your order:',
                [
                    'confirm' => "✅ Confirm",
                    'edit' => "✏️ Edit"
                ]
            );
            return '';
        }

        $this->deliveryRequestModel->update($requestId, [
            'status' => 'searching_driver',
        ]);

        $this->nextStep($phone, 'find_courier');

        $this->whatsappService->sendButtonMessage(
            $phone,
            'Delivery confirmed! A secure QR code will be generated for pickup. Next: Find a courier.',
            [
                'confirm' => "🚀 Find Courier"
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
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            'Please choose an option to proceed with your order:',
            [
                'confirm' => '✅ Confirm',
                'edit'    => '✏️ Edit',
            ]
        );
    }
}