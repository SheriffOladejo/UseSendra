<?php

namespace App\Steps;

use App\Models\Delivery;
use App\Models\DeliveryRequest;

class ConfirmDeliveryStep implements StepInterface
{
    private $sessionModel;
    private $deliveryModel;

    public function __construct($sessionModel)
    {
        $this->sessionModel = $sessionModel;
        $this->deliveryModel = new DeliveryRequest($sessionModel->getDb());
    }

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        // Retrieve stored session data
        $data = json_decode($session['data'] ?? '{}', true);

        $pickup  = $data['pickup_location'] ?? 'Unknown';
        $dropoff = $data['dropoff_location'] ?? 'Unknown';

        $message = strtolower(trim($message));

        // ── Confirm order ─────────────────────────────────
        if ($message === "confirm") {

            $deliveryId = $this->deliveryModel->create([
                'sender_phone'     => $phone,
                'pickup_location'  => $pickup,
                'dropoff_location' => $dropoff,
                'status'           => 'pending'
            ]);

            // Reset user session
            $this->sessionModel->updateStep($phone, 'start');
            $this->sessionModel->clearData($phone);

            return "✅ *Delivery Created!*\n\n"
                . "Pickup: {$pickup}\n"
                . "Dropoff: {$dropoff}\n\n"
                . "🚚 We’re now finding a driver for you.";
        }

        // ── Cancel order ─────────────────────────────────
        if ($message === "cancel") {

            $this->sessionModel->updateStep($phone, 'start');
            $this->sessionModel->clearData($phone);

            return "❌ Delivery cancelled.\n\nType *send* to start a new delivery.";
        }

        // ── Show confirmation summary ────────────────────
        return "*Confirm Delivery*\n\n"
            . "📍 Pickup: {$pickup}\n"
            . "🏁 Dropoff: {$dropoff}\n\n"
            . "Type *confirm* to proceed or *cancel*.";
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}