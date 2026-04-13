<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PaymentMethodStep extends BaseStep
{
    protected string $validationError = "⚠️ Please select 'Cash' or 'Transfer'.";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $choice = strtolower(trim($message));
        if (!in_array($choice, ['cash','transfer','💵','💳'])) return $this->validationError;

        $method = in_array($choice, ['💵','cash']) ? 'cash' : 'bank_transfer';

        $this->deliveryRequestModel->update($requestId, [
            'payment_method' => $method
        ]);

        $this->nextStep($phone, 'summary');

        $request = $this->deliveryRequestModel->findById($requestId);

        $summary = "Here’s your delivery summary:\n\n";
        $summary .= "📦 Item: {$request['item_name']}\n";
        $summary .= "📝 Description: {$request['item_description']}\n";
        $summary .= "📍 Pickup Location:\n";
        $summary .= "Address: {$request['pickup_address']}\n";
        $summary .= "Notes: {$request['pickup_notes']}\n\n";
        $summary .= "📍 Drop-off Location:\n";
        $summary .= "Address: {$request['dropoff_address']}\n";
        $summary .= "Notes: {$request['dropoff_notes']}\n\n";
        $summary .= "📞 Sender: {$request['sender_phone']}\n";
        $summary .= "📞 Receiver: {$request['receiver_phone']}\n";
        $summary .= "🏍 Vehicle: {$request['vehicle_type']}\n";
        $summary .= "💰 Price: ₦{$request['offered_price']}\n";
        $summary .= "🧾 Payer: {$request['payer']}\n";
        $summary .= "💳 Payment: {$request['payment_method']}\n\n";
        $summary .= "Type 'edit' to modify or 'confirm' to proceed.";

        $this->whatsappService->sendButtonMessage(
            $phone,
            $summary,
            [
                'confirm' => "✅ Confirm",
                'edit' => "✏️ Edit"
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return in_array(strtolower(trim($message)), ['cash','transfer','💵','💳']);
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            'Choose a payment method:',
            [
                'cash'     => '💵 Cash',
                'transfer' => '💳 Bank Transfer',
            ]
        );
    }
}