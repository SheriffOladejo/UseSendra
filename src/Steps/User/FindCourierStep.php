<?php

namespace App\Steps\User;

use App\Jobs\FindNearbyCouriersJob;

use App\Steps\BaseStep;

class FindCourierStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;

        if (!$requestId) {
            return "⚠️ We couldn't find your delivery request.";
        }

        $biddingWindow = 110; // seconds

        $biddingExpires = date('Y-m-d H:i:s', time() + $biddingWindow);

        // Mark request as searching
        $this->deliveryRequestModel->update($requestId, [
            'status' => 'searching_driver',
            'bidding_expires_at' => $biddingExpires
        ]);

        // Dispatch background job
        $job = new FindNearbyCouriersJob(
            $requestId,
            $this->driverModel,
            $this->deliveryRequestModel,
            $this->whatsappService
        );

        $job->handle(); // later this can go to queue

        return "🚀 Your delivery request is now live!\n\nWe're searching for nearby couriers.\n\nYou'll be notified once a rider accepts.";
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}