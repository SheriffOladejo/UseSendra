<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class InReviewStep extends BaseStep
{
    protected string $validationError;

    public function __construct()
    {
        // Default error message if translation fails
        $this->validationError = "⏳ Your account is under review. Please wait while we complete verification.";
    }

    /**
     * Handle messages from drivers whose accounts are pending verification
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        // Get driver details to check verification status
        $driver = $this->driverModel->findByPhone($phone);
        if (!$driver) {
            return $t->get('in_review_driver_not_found');
        }

        $status = $driver['verification_status'] ?? 'pending';

        switch ($status) {
            case 'approved':
                $this->nextStep($phone, 'driver_active');
                return $t->get('in_review_approved'
                );

            case 'rejected':
                return $t->get('in_review_rejected'
                );

            case 'pending':
            default:
                // Still pending - show in-review message
                $this->validationError = $t->get('in_review_message');
                return $this->validationError;
        }
    }

    /**
     * Validate input - allow certain commands, otherwise show in-review message
     */
    public function validateInput(string $message): bool
    {
        $message = strtolower(trim($message));
        $allowedCommands = ['status', 'help', 'support', 'reapply'];
        return in_array($message, $allowedCommands);
    }

    /**
     * Optional helper: format time ago
     */
    private function formatTimeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' minute' . (floor($diff / 60) > 1 ? 's' : '') . ' ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
        return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    }
}