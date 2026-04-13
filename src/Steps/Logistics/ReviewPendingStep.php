<?php

namespace App\Steps\Logistics;

use App\Steps\BaseStep;

/**
 * Placeholder while pickup/dropoff review prompts are handled in LogisticsMessageController.
 */
class ReviewPendingStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        return '';
    }

    public function validateInput(string $message, string $lang = 'en'): bool
    {
        return true;
    }
}
