<?php

namespace App\Jobs;

use App\Models\LogisticsReview;
use App\Services\WhatsAppMessagingWindow;

class FindNearbyCouriersJob
{
    private $requestId;
    private $driverModel;
    private $deliveryRequestModel;
    private $whatsappService;
    /** @var int[] */
    private array $blockedDriverIds;

    public function __construct($requestId, $driverModel, $deliveryRequestModel, $whatsappService, array $blockedDriverIds = [])
    {
        $this->requestId = $requestId;
        $this->driverModel = $driverModel;
        $this->deliveryRequestModel = $deliveryRequestModel;
        $this->whatsappService = $whatsappService;
        $this->blockedDriverIds = array_map('intval', $blockedDriverIds);
    }

    /**
     * @return int Number of riders notified (after blocks)
     */
    public function handle(): int
    {
        $request = $this->deliveryRequestModel->findById($this->requestId);

        if (!$request) {
            return 0;
        }

        $pickup = $request['pickup_address'];
        $dropoff = $request['dropoff_address'];
        $lng = $request['pickup_lng'];
        $lat = $request['pickup_lat'];

        $courierVehicle = isset($request['vehicle_type']) ? (string) $request['vehicle_type'] : null;
        $drivers = $this->driverModel->findNearby($lat, $lng, 5, $courierVehicle !== '' ? $courierVehicle : null);

        if (!$drivers) {
            $this->deliveryRequestModel->setDriversNotifiedCount((int) $this->requestId, 0);
            return 0;
        }

        $notified = 0;
        $totalEligible = 0;
        foreach ($drivers as $driver) {
            if (in_array((int) $driver['id'], $this->blockedDriverIds, true)) {
                continue;
            }
            $totalEligible++;
        }

        foreach ($drivers as $driver) {
            if (in_array((int) $driver['id'], $this->blockedDriverIds, true)) {
                continue;
            }
            $this->notifyDriver($driver, $request, $pickup, $dropoff, max(0, $totalEligible - 1));
            $notified++;
        }

        $this->deliveryRequestModel->setDriversNotifiedCount((int) $this->requestId, $notified);

        return $notified;
    }

    private function notifyDriver($driver, $request, $pickup, $dropoff, int $otherRidersNotified)
    {
        $lastTouch = $driver['last_message_at'] ?? null;
        if (WhatsAppMessagingWindow::isWithinCustomerCareWindow(is_string($lastTouch) ? $lastTouch : null)) {
            $this->sendRegularNotification($driver, $request, $pickup, $dropoff, $otherRidersNotified);

            return;
        }

        // Outside ~23h30m window: template only (session messages would be rejected by Meta).
        $this->sendTemplateNotification($driver, $request, $pickup, $dropoff);
    }

    /**
     * Send regular interactive message (within customer-care window)
     */
    private function sendRegularNotification($driver, $request, $pickup, $dropoff, int $otherRidersNotified)
    {
        $othersLine = $otherRidersNotified > 0
            ? "👥 ~{$otherRidersNotified} other rider(s) got this too. First to confirm secures the job.\n\n"
            : '';

        $custLine = '';
        try {
            $rev = new LogisticsReview($this->deliveryRequestModel->getDb());
            $cs = $rev->summaryForSenderUser((int) ($request['user_id'] ?? 0));
            $custLine = LogisticsReview::formatAverageStarsLine('Customer', $cs) . "\n\n";
        } catch (\Throwable $e) {
            $custLine = "⭐ Customer: no ratings yet\n\n";
        }

        $message =
            "📦 Job #{$this->requestId} · ₦{$request['offered_price']}\n\n" .
            $custLine .
            $othersLine .
            "📍 Pickup: {$pickup}\n\n" .
            "📍 Dropoff: {$dropoff}\n\n" .
            "📦 {$request['item_name']}\n" .
            "📝 {$request['item_description']}\n\n" .
            'Tap *Accept* to lock the job — complete the quick step we send right after.';

        $this->whatsappService->sendButtonMessage(
            $driver['phone'],
            $message,
            [
                "accept_{$this->requestId}" => "✅ Accept ₦{$request['offered_price']}",
                "reject_{$this->requestId}" => "❌ Reject",
                "counter_{$this->requestId}" => "💸 Counter Offer"
            ]
        );
    }

    /**
     * Send template message with Quick Reply buttons (outside customer-care window)
     * 
     * NOTE: Template buttons are STATIC and pre-approved by Meta.
     * When driver clicks a button, it sends that button's payload as a text message.
     * Your webhook then processes it like any other incoming message.
     */
    private function sendTemplateNotification($driver, $request, $pickup, $dropoff)
    {
        // Send template with Quick Reply buttons
        // The buttons are defined in the template itself (in Meta Business Manager)
        // When clicked, they send the button text/payload back to your webhook
        
        $this->whatsappService->sendTemplateWithButtons(
            $driver['phone'],
            'new_delivery_request', // Template name in Meta
            [

            ],
            // Optional: Store request_id in session so we know which request they're responding to
            $this->requestId
        );
    }

    /**
     * Store pending request ID for driver in session
     * This helps us match their button click response to the correct request
     */
    private function storePendingRequestForDriver($phone, $requestId)
    {
        $db = $this->driverModel->getDb(); // Assuming you have access to DB
        
        $stmt = $db->prepare("
            UPDATE whatsapp_sessions 
            SET session_data = JSON_SET(
                COALESCE(session_data, '{}'),
                '$.pending_delivery_request',
                ?
            )
            WHERE phone_number = ?
        ");
        
        $stmt->execute([$requestId, $phone]);
    }
}