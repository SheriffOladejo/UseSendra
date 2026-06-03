<?php

namespace App\Utils;

final class DeliverySummaryFormatter
{
    /**
     * Format a delivery_requests row for WhatsApp summary.
     *
     * @param array<string, mixed> $request
     */
    public static function format(array $request): string
    {
        $pickupLink = (!empty($request['pickup_lat']) && !empty($request['pickup_lng']))
            ? "https://www.google.com/maps/search/?api=1&query=" . $request['pickup_lat'] . "," . $request['pickup_lng']
            : null;
        $dropoffLink = (!empty($request['dropoff_lat']) && !empty($request['dropoff_lng']))
            ? "https://www.google.com/maps/search/?api=1&query=" . $request['dropoff_lat'] . "," . $request['dropoff_lng']
            : null;

        $pickupText = trim((string) ($request['pickup_address'] ?? ''));
        $dropoffText = trim((string) ($request['dropoff_address'] ?? ''));
        $pickupNotes = trim((string) ($request['pickup_notes'] ?? ''));
        $dropoffNotes = trim((string) ($request['dropoff_notes'] ?? ''));

        $vehicleLabel = \App\Utils\VehicleTypeHelper::displayLabel((string) ($request['vehicle_type'] ?? 'motorcycle'));
        $payerLabel = ucfirst((string) ($request['payer'] ?? ''));
        $paymentLabel = ($request['payment_method'] ?? '') === 'bank_transfer' ? 'Bank transfer' : 'Cash';

        $summary = "Alright, here’s your delivery summary\n\n";
        $summary .= "📍 From:\n";
        $summary .= ($pickupText !== '' ? "- {$pickupText}\n" : '');
        $summary .= ($pickupLink ? "- {$pickupLink}\n" : "- Pickup pin received\n");
        $summary .= "\n📍 To:\n";
        $summary .= ($dropoffText !== '' ? "- {$dropoffText}\n" : '');
        $summary .= ($dropoffLink ? "- {$dropoffLink}\n" : "- Drop-off pin received\n");
        $summary .= "\n";
        if ($pickupNotes !== '') {
            $summary .= "📝 Pickup notes: {$pickupNotes}\n";
        }
        if ($dropoffNotes !== '') {
            $summary .= "📝 Rider instructions: {$dropoffNotes}\n";
        }
        $summary .= "📦 Item: " . (($request['item_description'] ?? '') ?: ($request['item_name'] ?? '')) . "\n";
        $summary .= "🚗 Vehicle: {$vehicleLabel}\n\n";
        $summary .= "💰 Offer: ₦" . ($request['offered_price'] ?? '') . "\n";
        $summary .= "💳 Payment: {$paymentLabel} ({$payerLabel})\n\n";
        $summary .= "📞 Sender: " . ($request['sender_phone'] ?? '') . "\n";
        $summary .= "📞 Receiver: " . ($request['receiver_phone'] ?? '') . "\n\n";
        $summary .= "Request confirmed! Next: Find a courier.";

        return $summary;
    }
}

