<?php

namespace App\Controllers;

class PaystackWebhookController
{
    public function handle()
    {
        $payload = file_get_contents("php://input");
        $event = json_decode($payload, true);

        if ($event['event'] === 'charge.success') {

            $reference = $event['data']['reference'];
            $amount = $event['data']['amount'];

            // find delivery request using reference
            // update status to driver_assigned
            // generate QR code
            // notify sender
        }

        http_response_code(200);
    }
}