<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class QRCodeStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) return "Delivery request not found.";

        $qrCodeUrl = $this->deliveryRequestModel->generateQRCode($requestId);

        $this->deliveryRequestModel->update($requestId, [
            'status' => 'live'
        ]);

        $this->nextStep($phone, null);

        return "🔐 Your delivery request is now live!\n\n";
        // Send QR code image with button to find courier
        $this->whatsappService->sendButtonMessage(
            $phone,
            "Scan the QR code for pickup confirmation or find a courier nearby.",
            [
                'find_courier' => "🚀 Find Courier"
            ],
            $qrCodeUrl
        );
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}