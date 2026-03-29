<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class ReadyStep extends BaseStep
{
    /**
     * Handle final rider onboarding confirmation
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        // Update rider status in DB to 'ready'
        $this->driverModel->update($phone, [
            'status' => 'ready'
        ]);

        // Mark step as complete
        $this->nextStep($phone, 'in_review');

        $createdAt = strtotime($driver['created_at'] ?? 'now');

                return "⏳ *Application Status: Under Review*\n\nYour application submitted " . $this->formatTimeAgo($createdAt) . " is currently being reviewed by our team.\n\n📋 *What we're verifying:*
✓ Driver's license
✓ Vehicle registration
✓ Identity documents
✓ Background check

⏱️ *Expected review time:* 24-48 hours

We'll send you a WhatsApp notification once your account is approved!

💡 *Need help?* Type *'support'* to contact our team.";
    }

    /**
     * Validate input for this step (any input is fine since it's just a confirmation)
     */
    public function validateInput($message): bool
    {
        return true;
    }

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
