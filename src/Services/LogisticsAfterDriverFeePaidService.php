<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeliveryRequest;
use App\Models\DriverAcceptanceFeePayment;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Shared flow after a driver acceptance fee is confirmed (Paystack, Centiiv, etc.):
 * ledger row, assign rider, QR, WhatsApp to sender + driver.
 */
final class LogisticsAfterDriverFeePaidService
{
    public function __construct(
        private WhatsAppService $whatsappService,
        private string $projectRoot
    ) {
    }

    /**
     * @param array<string, mixed> $ledgerData Normalized charge payload for storage
     * @param array<string, mixed> $metadata Must include type, request_id, driver_id for driver_acceptance_fee
     */
    public function run(
        \PDO $db,
        int $requestId,
        int $driverId,
        string $reference,
        array $ledgerData,
        array $metadata,
        DeliveryRequest $deliveryRequestModel,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel
    ): void {
        $requestForLedger = $deliveryRequestModel->findById($requestId);
        if (!is_array($requestForLedger)) {
            $requestForLedger = null;
        }
        (new DriverAcceptanceFeePayment($db))->recordSuccessfulCharge($ledgerData, $metadata, $requestForLedger);

        if ($requestId < 1 || $driverId < 1) {
            return;
        }

        $request = $deliveryRequestModel->findById($requestId);
        if (!$request) {
            return;
        }

        if ($request['status'] === 'driver_assigned'
            && (int) ($request['reserved_driver_id'] ?? 0) === $driverId) {
            error_log("LogisticsAfterDriverFeePaidService: idempotent skip req {$requestId} already assigned to {$driverId}");

            return;
        }

        if ($request['status'] !== 'driver_payment_pending') {
            error_log('LogisticsAfterDriverFeePaidService: skip req ' . $requestId . ' status=' . ($request['status'] ?? 'null') . ' (expected driver_payment_pending)');

            return;
        }

        if ((int) ($request['reserved_driver_id'] ?? 0) !== $driverId) {
            error_log("Driver fee paid webhook: driver mismatch req {$requestId} meta {$driverId} reserved " . ($request['reserved_driver_id'] ?? 'null'));

            return;
        }

        $expectedRef = (string) ($request['acceptance_payment_reference'] ?? '');
        if ($expectedRef !== '' && $reference !== '' && $expectedRef !== $reference) {
            error_log("Driver fee paid webhook: reference mismatch for req {$requestId} expected={$expectedRef} got={$reference}");

            return;
        }

        $driver = $driverModel->findById($driverId);
        $sender = $userModel->findById((int) $request['user_id']);

        if (!$driver || !$sender) {
            error_log("Driver fee paid webhook: missing driver or sender for req {$requestId}");

            return;
        }

        $relativeQrPath = $this->writePickupQrFile($requestId);
        $absQrPath = $this->projectRoot . '/' . $relativeQrPath;

        $deliveryRequestModel->update($requestId, [
            'status' => 'driver_assigned',
            'reserved_driver_id' => $driverId,
            'pickup_qr_code' => $relativeQrPath,
        ]);

        $request = $deliveryRequestModel->findById($requestId);

        $plate = $driver['vehicle_plate_number'] ?: ($driver['license_number'] ?? '—');
        $pickupPayload = PickupQrCodeService::payloadForRequest($requestId);
        $pickupConfirmLine = PickupQrCodeService::pickupConfirmViaQrOnly()
            ? "At pickup: send a *photo* of the rider's Sendra QR in this chat to confirm they're the right person."
            : "At pickup: photograph the rider's QR or type the code they show (e.g. {$pickupPayload}) to confirm they're the right person.";
        $customerMessage = "🎉 A rider is assigned to your job #{$requestId}.\n\n"
            . "Pickup: {$request['pickup_address']}\n"
            . "Dropoff: {$request['dropoff_address']}\n"
            . "Item: {$request['item_name']}\n"
            . "Price: ₦" . number_format((float) $request['offered_price'], 0) . "\n\n"
            . "Rider: {$driver['first_name']} {$driver['last_name']}\n"
            . "Phone: " . ($driver['preferred_phone_number'] ?: $driver['phone']) . "\n"
            . "Vehicle: {$driver['vehicle_color']} {$driver['vehicle_type']}\n"
            . "Plate: {$plate}\n\n"
            . $pickupConfirmLine;

        $this->whatsappService->sendMessage($sender['phone'], $customerMessage);

        if (!file_exists($absQrPath)) {
            error_log("QR file missing: {$absQrPath}");
        } else {
            $this->whatsappService->sendImage(
                $driver['phone'],
                $absQrPath,
                PickupQrCodeService::pickupConfirmViaQrOnly()
                    ? '🔐 Show this QR at pickup — the sender will photograph it in chat to confirm you.'
                    : '🔐 Show this QR at pickup. Sender confirms by photo or typing: ' . PickupQrCodeService::payloadForRequest($requestId)
            );
        }

        $pickupLink = "https://www.google.com/maps/search/?api=1&query={$request['pickup_lat']},{$request['pickup_lng']}";
        $dropoffLink = "https://www.google.com/maps/search/?api=1&query={$request['dropoff_lat']},{$request['dropoff_lng']}";

        $driverMessage = "✅ Payment received. Job #{$requestId} is yours.\n\n"
            . "Pickup: {$request['pickup_address']}\nMaps: {$pickupLink}\n\n"
            . "Dropoff: {$request['dropoff_address']}\nMaps: {$dropoffLink}\n\n"
            . "Sender: {$request['sender_phone']}\nReceiver: {$request['receiver_phone']}\n"
            . "Fee: ₦" . number_format((float) $request['offered_price'], 0) . "\n\n"
            . 'Show your QR at pickup. Sender will confirm in the chat.';

        $this->whatsappService->sendMessage($driver['phone'], $driverMessage);

        $relativeItem = $request['item_photo_url'] ?? '';
        if ($relativeItem !== '') {
            $itemAbs = filter_var($relativeItem, FILTER_VALIDATE_URL) ? $relativeItem : ($this->projectRoot . '/' . ltrim($relativeItem, '/'));
            if (file_exists($itemAbs) || filter_var($relativeItem, FILTER_VALIDATE_URL)) {
                $this->whatsappService->sendImage($driver['phone'], $itemAbs, '📦 Item photo');
            }
        }
    }

    private function writePickupQrFile(int $requestId): string
    {
        $data = PickupQrCodeService::payloadForRequest($requestId);
        $qrCode = new QrCode(data: $data, size: 300, margin: 10);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $dir = $this->projectRoot . '/uploads/qrcodes';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $relative = "uploads/qrcodes/delivery_{$requestId}.png";
        $result->saveToFile($this->projectRoot . '/' . $relative);

        return $relative;
    }
}
