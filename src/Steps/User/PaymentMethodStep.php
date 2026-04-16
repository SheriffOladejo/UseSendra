<?php

namespace App\Steps\User;

use App\Steps\BaseStep;
use App\Utils\DeliverySummaryFormatter;

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
        $summary = is_array($request) && !empty($request)
            ? DeliverySummaryFormatter::format($request)
            : 'Please choose an option to proceed with your order:';

        $this->whatsappService->sendButtonMessage(
            $phone,
            $summary,
            [
                'confirm' => "✅ Confirm",
                'edit' => "✏️ Edit"
            ]
        );

        // If we came here from "edit", the summary is already re-sent; end edit mode.
        if ($this->isEditMode()) {
            $this->clearEditMode();
        }

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