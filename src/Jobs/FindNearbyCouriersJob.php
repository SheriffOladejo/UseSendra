<?php

namespace App\Jobs;

class FindNearbyCouriersJob
{
    private $requestId;
    private $driverModel;
    private $deliveryRequestModel;
    private $whatsappService;

    private const MESSAGING_WINDOW_SECONDS = 24 * 60 * 60;

    public function __construct($requestId, $driverModel, $deliveryRequestModel, $whatsappService)
    {
        $this->requestId = $requestId;
        $this->driverModel = $driverModel;
        $this->deliveryRequestModel = $deliveryRequestModel;
        $this->whatsappService = $whatsappService;
    }

    public function handle()
    {
        $request = $this->deliveryRequestModel->findById($this->requestId);

        if (!$request) return;

        $pickup = $request['pickup_address'];
        $dropoff = $request['dropoff_address'];
        $lng = $request['pickup_lng'];
        $lat = $request['pickup_lat'];

        $drivers = $this->driverModel->findNearby($lat, $lng);

        if (!$drivers) {
            return;
        }

        foreach ($drivers as $driver) {
            $this->notifyDriver($driver, $request, $pickup, $dropoff);
        }
    }

    private function notifyDriver($driver, $request, $pickup, $dropoff)
    {
        $withinWindow = $this->isWithinMessagingWindow($driver);

        if ($withinWindow) {
            $this->sendRegularNotification($driver, $request, $pickup, $dropoff);
        } else {
            $this->sendTemplateNotification($driver, $request, $pickup, $dropoff);
            sleep(2); // Small delay to ensure template is sent before retryig with regular message
            $this->sendRegularNotification($driver, $request, $pickup, $dropoff);
        }
    }

    private function isWithinMessagingWindow($driver)
    {
        if (!isset($driver['last_message_at']) || $driver['last_message_at'] === null) {
            return false;
        }

        $lastMessageTime = strtotime($driver['last_message_at']);
        $currentTime = time();
        $timeSinceLastMessage = $currentTime - $lastMessageTime;

        return $timeSinceLastMessage < self::MESSAGING_WINDOW_SECONDS;
    }

    /**
     * Send regular interactive message (within 24-hour window)
     */
    private function sendRegularNotification($driver, $request, $pickup, $dropoff)
    {
        $message =
            "📦 New Delivery Request\n\n" .
            "📍 Pickup Location: {$pickup}\n\n" .
            "📍 Dropoff Location: {$dropoff}\n\n" .
            "📦 Item: {$request['item_name']}\n\n" .
            "📝 Description: {$request['item_description']}\n\n" .
            "💰 Price: ₦{$request['offered_price']}\n\n" .
            "Do you want to accept this delivery?\n\n" .
            "You have 90 seconds to respond.";

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
     * Send template message with Quick Reply buttons (outside 24-hour window)
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