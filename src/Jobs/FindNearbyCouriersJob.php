<?php

namespace App\Jobs;

class FindNearbyCouriersJob
{
    private $requestId;
    private $driverModel;
    private $deliveryRequestModel;
    private $whatsappService;

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

        // Find nearby drivers (within ~5km)
        $drivers = $this->driverModel->findNearby($lat, $lng);

        if (!$drivers) {
            return;
        }

        foreach ($drivers as $driver) {

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
    }
}
