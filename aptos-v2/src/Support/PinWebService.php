<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\WhatsAppService;

/**
 * First-time PIN setup and PIN change from the web PIN pages.
 */
final class PinWebService
{
    public function __construct(
        private User $userModel,
        private AuthController $auth,
        private WhatsAppService $whatsapp,
    ) {
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function setupPin(string $phone, string $pin): array
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            return ['success' => false, 'error' => 'PIN must be exactly 6 digits.'];
        }

        $userResult = $this->auth->getUser($phone);
        $user = $userResult['user'] ?? null;
        if (!$user) {
            return ['success' => false, 'error' => 'Account not found.'];
        }

        if (!empty($user['pin_hash']) && empty($user['awaiting_pin_setup'])) {
            return ['success' => false, 'error' => 'Your PIN is already set. Return to WhatsApp.'];
        }

        $this->userModel->setPin($phone, $pin);
        $this->whatsapp->sendMessage(
            $phone,
            "✅ *PIN set successfully!*\n\n" .
            "You'll use this PIN to confirm all transactions.\n\n" .
            'Type *menu* to see what you can do.'
        );

        return ['success' => true];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function changePin(string $phone, string $currentPin, string $newPin): array
    {
        if (!preg_match('/^\d{6}$/', $currentPin) || !preg_match('/^\d{6}$/', $newPin)) {
            return ['success' => false, 'error' => 'PIN must be exactly 6 digits.'];
        }

        if ($currentPin === $newPin) {
            return ['success' => false, 'error' => 'New PIN must be different from your current PIN.'];
        }

        $userResult = $this->auth->getUser($phone);
        $user = $userResult['user'] ?? null;
        if (!$user) {
            return ['success' => false, 'error' => 'Account not found.'];
        }

        if (empty($user['pin_hash'])) {
            return ['success' => false, 'error' => 'You have not set a PIN yet.'];
        }

        if (!$this->userModel->verifyPin($phone, $currentPin)) {
            return ['success' => false, 'error' => 'Current PIN is incorrect.'];
        }

        $this->userModel->setPin($phone, $newPin);
        $this->whatsapp->sendMessage(
            $phone,
            "✅ *Transaction PIN updated!*\n\n" .
            "Use your new PIN the next time you confirm a send, sell, or swap.\n\n" .
            'Type *menu* for options.'
        );

        return ['success' => true];
    }
}
