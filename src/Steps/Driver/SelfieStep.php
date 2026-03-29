<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class SelfieStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        // Note: This assumes you're receiving the image URL or have uploaded it
        // You'll need to handle WhatsApp media download separately
        $selfieUrl = trim($message);

        if (!$this->validateInput($selfieUrl)) {
            return "Please send a valid image. Make sure to send it as a photo attachment.";
        }

        $this->driverModel->update($session['phone_number'], [
            'selfie_url' => $selfieUrl,
            'verification_status' => 'pending'
        ]);

        $this->nextStep($session['phone_number'], 'completed');

        return "🎉 *Registration Complete!*  

        Thank you for submitting your information!  

        Your application is now under review. Our team will verify your details and get back to you within 24-48 hours.  

        You'll receive a notification once your account is *approved* and you can start accepting rides.  

        Welcome to the team! 🚀";
    }

    public function validateInput(string $selfieUrl): bool
    {
        // Basic URL validation or check if it's a valid file path
        return !empty($selfieUrl) && (filter_var($selfieUrl, FILTER_VALIDATE_URL) || strlen($selfieUrl) > 10);
    }
}