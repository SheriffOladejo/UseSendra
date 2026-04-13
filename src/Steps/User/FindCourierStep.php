<?php

namespace App\Steps\User;

use App\Jobs\FindNearbyCouriersJob;
use App\Models\UserBlockedDriver;

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

        $blocked = [];
        $reqRow = $this->deliveryRequestModel->findById($requestId);
        if (!empty($reqRow['user_id'])) {
            $blockedModel = new UserBlockedDriver($this->deliveryRequestModel->getDb());
            $blocked = $blockedModel->listBlockedDriverIdsForUser((int) $reqRow['user_id']);
        }

        $job = new FindNearbyCouriersJob(
            $requestId,
            $this->driverModel,
            $this->deliveryRequestModel,
            $this->whatsappService,
            $blocked
        );

        $notified = $job->handle();

        $reqAfter = $this->deliveryRequestModel->findById($requestId);
        $n = (int) ($reqAfter['drivers_notified_count'] ?? $notified);
        if ($n < 1) {
            return "🚀 Request #{$requestId} is live.\n\n⚠️ No riders matched nearby yet. We'll notify you when someone accepts, or try again later from the menu.";
        }

        return "🚀 Request #{$requestId} is live.\n\n📡 Pinged {$n} rider(s). You'll get a message when a rider is assigned.\n\nIf no one takes it before search ends, we'll tell you.";
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}