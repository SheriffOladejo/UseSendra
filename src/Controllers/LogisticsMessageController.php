<?php

namespace App\Controllers;

use App\Services\WhatsAppService;
use App\Utils\StepRouter;

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Models\DeliveryOffer;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

use App\Services\PaystackService;

class LogisticsMessageController
{
    private $whatsappService;
    private $authController;
    private $paystackService;
    private $db;

    public function __construct(
        WhatsAppService $whatsappService,
        LogisticsAuthController $authController,
        $db
    ) {
        $this->whatsappService = $whatsappService;
        $this->authController  = $authController;
        $this->db              = $db;
        $this->paystackService = new PaystackService();
    }

    /**
     * Entry point for all incoming WhatsApp messages
     */
    public function handleIncomingMessage($phoneNumber, $message, $user, $displayName)
    {
        $message = trim(strtolower($message));

        // ─────────────────────────────
        // 1️⃣ Initialize models
        // ─────────────────────────────

        $sessionModel = new Session($this->db);
        $driverModel  = new LogisticsDriver($this->db);
        $userModel    = new LogisticsUser($this->db);
        $deliveryRequestModel = new DeliveryRequest($this->db);
        $deliveryOfferModel = new DeliveryOffer($this->db);

        // ─────────────────────────────
        // 2️⃣ Load session
        // ─────────────────────────────

        $session = $sessionModel->findByPhone($phoneNumber);
        //$pendingRequestId = $session['session_data']['pending_delivery_request'] ?? null;

        if (!$session) {

            $sessionModel->create($phoneNumber, 'start');

            $session = [
                'phone_number' => $phoneNumber,
                'current_step' => 'start',
                'data' => '{}'
            ];
        }
        

        // ─────────────────────────────
        // 3️⃣ Initialize StepRouter
        // ─────────────────────────────

        $router = new StepRouter(
            $sessionModel,
            $this->whatsappService,
            $driverModel,
            $userModel,
            $deliveryRequestModel,
            $deliveryOfferModel
        );

        if (str_starts_with($message, 'accept_')) {
            $requestId = str_replace('accept_', '', $message);

            $driver = $driverModel->findByPhone($phoneNumber);

            $request = $deliveryRequestModel->findById($requestId);

            if ($request['status'] === 'driver_payment_pending') {

                if (strtotime($request['reservation_expires_at']) > time()) {
                    return "⏳ Another driver is currently securing this delivery. Please wait.";
                }
            }
            else if (!empty($request['reservation_expires_at']) && strtotime($request['reservation_expires_at']) < time()) {

                $deliveryRequestModel->update($requestId, [
                    'status' => 'searching_driver',
                    'reserved_driver_id' => null
                ]);
                $request = $deliveryRequestModel->findById($requestId);

            }

            if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
                return "⚠️ This delivery has already been taken.";
            }

            $expires = date('Y-m-d H:i:s', strtotime('+3 minutes'));

            $fee = $request['offered_price'] * 0.10;
            $reference = "DRV_ACCEPT_" . uniqid();

            $payment = $this->paystackService->initializePayment(
                'sherifffoladejo@gmail.com',
                $fee,
                $reference,
                "https://usesendra.com/payment/callback",
                [
                    "request_id" => $requestId,
                    "driver_id" => $request['reserved_driver_id'],
                    "type" => "driver_acceptance_fee"
                ]
            );

            if (!$payment || !isset($payment['data']['authorization_url'])) {

                error_log("Paystack Error: " . json_encode($payment));

                return "⚠️ Unable to generate payment link. Please try again.";
            }

            $link = $payment['data']['authorization_url'];

            $deliveryRequestModel->update($requestId, [
                'status' => 'driver_payment_pending',
                'reserved_driver_id' => $driver['id'],
                'reservation_expires_at' => $expires,
                'acceptance_payment_reference' => $reference,
            ]);

            $this->whatsappService->sendMessage(
                $phoneNumber,
                "🚚 Delivery Reserved.\n" .
                "You have 3 minutes to confirm this delivery.\n" .
                "Acceptance Fee: ₦{$fee}\n\n" .
                "Complete payment below to secure this job.\n\n" .
                "👉 Pay Now: {$link}"
            );

            return '';

        }

        if (str_starts_with($message, 'accepting_offer_')) {
            // e.g. accepting_offer_18_5  → requestId=18, offerId=5
            $parts     = explode('_', $message); // ['accept', 'offer', '18', '5']
            $requestId = $parts[2];
            $offerId   = $parts[3];
            
            $offer   = $deliveryOfferModel->findById($offerId)[0];
            $request = $deliveryRequestModel->findById($requestId);
            $driver  = $driverModel->findById($offer['driver_id']);

            if ($request['status'] === 'driver_payment_pending') {
                if (strtotime($request['reservation_expires_at']) > time()) {
                    return "⏳ Another driver is currently securing this delivery. Please wait.";
                }
            }
            else if (!empty($request['reservation_expires_at']) && strtotime($request['reservation_expires_at']) < time()) {

                $deliveryRequestModel->update($requestId, [
                    'status' => 'searching_driver',
                    'reserved_driver_id' => null
                ]);

            }

            if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
                return "⚠️ This delivery has already been taken.";
            }

            // Mark offer accepted, reject others
            $deliveryOfferModel->updateStatus($offerId, 'accepted');
            $deliveryOfferModel->rejectOtherOffers($requestId, $offerId); // you'll need this method

            // Update request price to the accepted offer price
            $deliveryRequestModel->update($requestId, [
                'offered_price' => $offer['proposed_price'],
                'status'        => 'searching_driver', // reset so driver accept flow works
            ]);

            // Calculate fee and generate payment link for the driver
            $fee       = $offer['proposed_price'] * 0.10;
            $reference = "DRV_ACCEPT_" . uniqid();

            $payment = $this->paystackService->initializePayment(
                $driver['email'] ?? 'sherifffoladejo@gmail.com',
                $fee,
                $reference,
                "https://usesendra.com/payment/callback",
                [
                    "request_id" => $requestId,
                    "driver_id"  => $driver['id'],
                    "type"       => "driver_acceptance_fee"
                ]
            );

            if (!$payment || !isset($payment['data']['authorization_url'])) {
                $this->whatsappService->sendMessage($phoneNumber, "⚠️ Could not generate payment link. Please try again.");
                return '';
            }

            $link    = $payment['data']['authorization_url'];
            $expires = date('Y-m-d H:i:s', strtotime('+2 minutes'));

            $deliveryRequestModel->update($requestId, [
                'status'                      => 'driver_payment_pending',
                'reserved_driver_id'          => $driver['id'],
                'reservation_expires_at'      => $expires,
                'acceptance_payment_reference' => $reference,
            ]);

            // Notify customer
            $this->whatsappService->sendMessage(
                $phoneNumber,
                "✅ You've accepted {$driver['first_name']}'s offer of ₦" . number_format($offer['proposed_price'], 0) . ".\n\nWaiting for the driver to confirm and pay the acceptance fee."
            );

            // Notify driver — they have 2 minutes to pay
            $this->whatsappService->sendMessage(
                $driver['phone'],
                "🎉 The customer has accepted your offer of ₦" . number_format($offer['proposed_price'], 0) . "!\n\n" .
                "You have 2 minutes to pay the acceptance fee of ₦{$fee} to secure this delivery.\n\n" .
                "👉 Pay Now: {$link}"
            );

            return '';
        }

        if (str_starts_with($message, 'counter_')) {

            $requestId = str_replace('counter_', '', $message);
            $driver = $driverModel->findByPhone($phoneNumber);

            $request = $deliveryRequestModel->findById($requestId);

            if ($request['status'] === 'driver_payment_pending') {
                if (strtotime($request['reservation_expires_at']) > time()) {
                    return "⏳ Another driver is currently securing this delivery. Please wait.";
                }
            }
            else if (!empty($request['reservation_expires_at']) && strtotime($request['reservation_expires_at']) < time()) {

                $deliveryRequestModel->update($requestId, [
                    'status' => 'searching_driver',
                    'reserved_driver_id' => null
                ]);

            }

            if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'])) {
                return "⚠️ This delivery has already been taken.";
            }

            // Store requestId in session so we know which delivery the driver is countering
            $sessionData = $sessionModel->findByPhone($phoneNumber);
            $sessionData['counter_request_id'] = $requestId;
            $sessionModel->update($phoneNumber, $sessionData);

            // Set next step so the bot knows driver is entering amount
            $sessionModel->updateStep($phoneNumber, 'driver_enter_counter');

            $this->whatsappService->sendMessage(
                $phoneNumber,
                "💸 Enter your counter offer amount (in ₦) for this delivery:"
            );

            return "";
        }

        if (str_starts_with($message, 'payment_success_')) {

            $requestId = str_replace('payment_success_', '', $message);

            $request = $deliveryRequestModel->findById($requestId);

            $qrPath = $this->generatePickupQR($requestId);

            $deliveryRequestModel->update($requestId, [
                'status' => 'driver_assigned',
                'driver_id' => $request['reserved_driver_id'],
                'pickup_qr_code' => $qrPath,
            ]);

            $courier = $driverModel->findById($request['reserved_driver_id']);

            $this->whatsappService->sendMessage(
                $request['sender_phone'],
                "🎉 A courier has accepted your delivery and is on the way to the pickup location.\n\n".
                "Name: {$courier['first_name']} {$courier['last_name']}\n".
                "Phone: {$courier['preferred_phone_number']}\n".
                "Vehicle: {$courier['vehicle_color']} {$courier['vehicle_type']}\n".
                "Plate: {$courier['vehicle_plate_number']} \n\n"
            );

            $sender = $userModel->findById($request['user_id']);

            $qrPath = $this->generatePickupQR($requestId);

            $this->whatsappService->sendImage(
                $sender['phone'],
                $qrPath,
                "🔐 Pickup QR Code\n\nShow this to the courier when they arrive.\n\nIt confirms the package pickup securely."
            );
        }

        $currentStep = $session['current_step'] ?? 'start';

        $stepHandler = $router->getStepHandler($currentStep);

        // ─────────────────────────────
        // 4️⃣ Validate input
        // ─────────────────────────────

        $stepHandler->setSession($session);

        if (!$stepHandler->validateInput($message, $session['language'] ?? 'en')) {

            $this->whatsappService->sendMessage(
                $phoneNumber,
                $stepHandler->getValidationError()
            );

            return;
        }

        $stepHandler->setUsername($displayName);

        // ─────────────────────────────
        // 5️⃣ Execute step
        // ─────────────────────────────

        $response = $stepHandler->handle($session, $message);

        if ($response) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                $response
            );
        }
    }


    function generatePickupQR(int $requestId): string
    {
        $data = "SENDRA_PICKUP_" . $requestId;

        $qrCode = new QrCode(
            data: $data,
            size: 300,
            margin: 10
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $projectRoot = dirname(__DIR__, 2);
        $dir = $projectRoot . '/uploads/qrcodes';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . "/delivery_{$requestId}.png";

        $result->saveToFile($file);

        return "uploads/qrcodes/delivery_{$requestId}.png";
    }
}
