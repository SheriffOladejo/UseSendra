<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class ReadyStep extends BaseStep
{
    protected string $validationError;

    /**
     * Handle final rider onboarding confirmation
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        // Update rider status in DB to 'ready'
        $this->driverModel->update($phone, [
            'status' => 'ready'
        ]);

        // Mark step as complete
        //$this->nextStep($phone, 'rider_ready');

        // Send review info message (multilingual)
        $this->whatsappService->sendMessage(
            $phone,
            $t->get('in_review_message')
        );

        return '';
    }

    /**
     * Validate input for this step (any input is fine)
     */
    public function validateInput($message): bool
    {
        return true;
    }

    /**
     * Optional: format timestamp into "x minutes/hours/days ago"
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