<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class InReviewStep extends BaseStep
{
    protected string $validationError = "⏳ Your application is still under review.

Please wait for our team to complete the verification process. We'll notify you once approved!";

    /**
     * Handle messages from drivers whose accounts are pending verification
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        
        // Get driver details to check verification status
        $driver = $this->driverModel->findByPhone($phone);
        
        if (!$driver) {
            return "❌ Driver record not found. Please contact support.";
        }

        // Check current verification status
        $status = $driver['verification_status'] ?? 'pending';

        switch ($status) {
            case 'approved':
                // Driver was approved, move them to active state
                $this->nextStep($phone, 'driver_active');
                return "🎉 *Congratulations!* Your account has been approved! 

You can now start accepting ride requests. 

Type *'online'* to go online and start receiving requests.
Type *'help'* to see available commands.";

            case 'rejected':
                // Driver was rejected
                return "❌ Unfortunately, your application was not approved.

*Reason:* " . ($driver['rejection_reason'] ?? 'Please contact support for details.') . "

You can reapply by typing *'reapply'* or contact support for assistance.";

            case 'pending':
            default:
                // Still pending - provide status update
                $createdAt = strtotime($driver['created_at'] ?? 'now');
                $hoursWaiting = floor((time() - $createdAt) / 3600);

                return "⏳ *Application Status: Under Review*\n\nYour application submitted " . $this->formatTimeAgo($createdAt) . " is currently being reviewed by our team.\n\n📋 *What we're verifying:*
✓ Driver's license
✓ Vehicle registration
✓ Identity documents
✓ Background check

⏱️ *Expected review time:* 24-48 hours

We'll send you a WhatsApp notification once your account is approved!

💡 *Need help?* Type *'support'* to contact our team.";
        }
    }

    /**
     * Validate input - always return false to show the in-review message
     * Unless they type specific commands
     */
    public function validateInput(string $message): bool
    {
        $message = strtolower(trim($message));

        // Allow specific commands
        $allowedCommands = ['status', 'help', 'support', 'reapply'];

        if (in_array($message, $allowedCommands)) {
            return true;
        }

        // For any other input, show the validation error (which is the in-review message)
        return false;
    }

    /**
     * Format time ago in human-readable format
     */
    private function formatTimeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } else {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }
    }
}