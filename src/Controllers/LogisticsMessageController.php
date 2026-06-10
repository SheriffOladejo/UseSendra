<?php

namespace App\Controllers;

use App\Services\WhatsAppService;
use App\Utils\StepRouter;

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Models\DeliveryOffer;
use App\Models\LogisticsReview;
use App\Models\UserBlockedDriver;
use App\Models\CompletedDeliveryOrder;
use App\Jobs\FindNearbyCouriersJob;
use App\Services\QrDecodeService;
use App\Services\PickupQrCodeService;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

use App\Services\CentiivDirectPayService;
use App\Services\LogisticsAfterDriverFeePaidService;
use App\Services\LogisticsDriverWalletAssignmentService;
use App\Services\RiderWalletWhatsAppHandler;
use App\Services\RiderWalletService;
use App\Models\RiderWalletTransaction;
use App\Services\Marketplace\MarketplaceWhatsAppHandler;
use App\Services\Marketplace\MarketplaceOrderCompletionService;
use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;

class LogisticsMessageController
{
    private const PAYMENT_RESERVATION_SECONDS = 90;

    /** tryHandleReviewStep: skip review and re-run main message handling with refreshed session */
    private const REVIEW_STEP_DEFER_TO_MAIN = '__review_step_defer__';

    private $whatsappService;
    private $authController;
    private CentiivDirectPayService $centiivDirectPay;
    private $db;

    public function __construct(
        WhatsAppService $whatsappService,
        LogisticsAuthController $authController,
        $db
    ) {
        $this->whatsappService = $whatsappService;
        $this->authController = $authController;
        $this->db = $db;
        $this->centiivDirectPay = new CentiivDirectPayService();
    }

    public function handleIncomingMessage($phoneNumber, $message, $user, $displayName)
    {
        $rawTrim = trim($message);
        $messageLower = strtolower($rawTrim);

        $imagePayload = null;
        if ($rawTrim !== '' && $rawTrim[0] === '{') {
            $j = json_decode($rawTrim, true);
            if (is_array($j) && isset($j['image']['id'])) {
                $imagePayload = $j;
            }
        }

        $sessionModel = new Session($this->db);
        $driverModel = new LogisticsDriver($this->db);
        $userModel = new LogisticsUser($this->db);
        $deliveryRequestModel = new DeliveryRequest($this->db);
        $deliveryOfferModel = new DeliveryOffer($this->db);
        $reviewModel = new LogisticsReview($this->db);
        $blockedModel = new UserBlockedDriver($this->db);

        $session = $sessionModel->findByPhone($phoneNumber);

        if (!$session) {
            $sessionModel->create($phoneNumber);
            $session = [
                'phone_number' => $phoneNumber,
                'current_step' => 'start',
                'data' => '{}',
            ];
        }

        // Language selection step is disabled for now — migrate anyone still on it.
        if (($session['current_step'] ?? '') === 'language') {
            $lang = !empty($session['language']) ? $session['language'] : 'en';
            $sessionModel->update($phoneNumber, [
                'current_step' => 'choose_role',
                'language' => $lang,
            ]);
            $session['current_step'] = 'choose_role';
            $session['language'] = $lang;
        }

        $reconciled = $this->reconcileStuckSenderSession(
            $phoneNumber,
            $session,
            $sessionModel,
            $userModel,
            $deliveryRequestModel
        );
        if ($reconciled !== null) {
            $session = $reconciled;
        }

        $riderRecon = $this->reconcileRiderSessionForInTransitJob(
            $phoneNumber,
            $session,
            $sessionModel,
            $driverModel,
            $deliveryRequestModel
        );
        if ($riderRecon !== null) {
            $session = $riderRecon;
        }

        $router = new StepRouter(
            $sessionModel,
            $this->whatsappService,
            $driverModel,
            $userModel,
            $deliveryRequestModel,
            $deliveryOfferModel
        );

        // ── Customer offer-selection escape hatch ─────────────────────────────
        // When the customer was asked to accept/counter offers (customer_choose_offer),
        // allow "hi/menu" (and explicit cancel words) to exit back to sender_menu.
        if (($session['current_step'] ?? '') === 'customer_choose_offer') {
            if (in_array($messageLower, ['hi', 'hello', 'hey', 'menu', 'status'], true)) {
                $sessionModel->update($phoneNumber, [
                    'pending_delivery_request' => null,
                    'counter_request_id' => null,
                ]);
                $sessionModel->updateStep($phoneNumber, 'sender_menu');
                $this->whatsappService->sendMessage($phoneNumber, "✅ Cancelled offer selection.\n\n" . (new \App\Steps\User\ShowMenuStep(
                    $sessionModel,
                    $this->whatsappService,
                    $driverModel,
                    $userModel,
                    $deliveryRequestModel,
                    $deliveryOfferModel
                ))->handle($session, 'hi'));
                return;
            }
            if (in_array($messageLower, ['cancel', 'cancel offer', 'reject', 'reject offer', 'stop'], true)) {
                $sessionModel->update($phoneNumber, [
                    'pending_delivery_request' => null,
                    'counter_request_id' => null,
                ]);
                $sessionModel->updateStep($phoneNumber, 'sender_menu');
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    "✅ Offer selection cancelled. Type *send* to start a new delivery, or *hi* for menu."
                );
                return;
            }
        }

        // ── Rider counter step escape hatch ───────────────────────────────────
        // If a rider got stuck in the "enter counter" step after a customer cancels/rejects,
        // allow "hi/menu" to reset them back to rider_ready.
        if (
            ($session['current_step'] ?? '') === 'driver_enter_counter'
            && in_array($messageLower, ['hi', 'hello', 'hey', 'menu', 'status'], true)
        ) {
            $d = $driverModel->findByPhone($phoneNumber);
            if ($d) {
                $sessionModel->update($phoneNumber, [
                    'counter_request_id' => null,
                    'pending_delivery_request' => null,
                ]);
                $sessionModel->updateStep($phoneNumber, 'rider_ready');

                $ready = $router->getStepHandler('rider_ready');
                $ready->setSession($sessionModel->findByPhone($phoneNumber) ?: $session);
                $ready->setUsername($displayName);
                $out = $ready->handle($session, $messageLower);
                if ($out !== '') {
                    $this->whatsappService->sendMessage($phoneNumber, $out);
                }
                return;
            }
        }

        // ── Image during pickup: decode QR server-side ─────────────────────────
        if ($imagePayload !== null) {
            $u = $userModel->findByPhone($phoneNumber);
            if ($u) {
                $pickReq = $deliveryRequestModel->findAssignedAwaitingPickupConfirm((int) $u['id']);
                if ($pickReq) {
                    $rid = (int) $pickReq['id'];
                    $mediaId = (string) ($imagePayload['image']['id'] ?? '');
                    if ($mediaId === '') {
                        return;
                    }
                    $tmp = $this->whatsappService->downloadMediaToFile($mediaId);
                    if (!$tmp) {
                        $retry = "📷 Couldn't load the photo. Please try again with a clear photo of the rider's Sendra QR.";
                        $this->whatsappService->sendMessage($phoneNumber, $retry);
                        return;
                    }
                    $decoder = new QrDecodeService();
                    $text = $decoder->decodeFile($tmp);
                    @unlink($tmp);
                    if ($text) {
                        $parsed = PickupQrCodeService::parseAndVerify(strtolower(trim($text)));
                        if ($parsed !== null) {
                            $this->handlePickupVerify(
                                $phoneNumber,
                                strtolower(trim($text)),
                                $sessionModel,
                                $userModel,
                                $deliveryRequestModel,
                                $driverModel
                            );
                            return;
                        }
                    }
                    $retry = "📷 No Sendra QR found in that image. Send a clear, well-lit photo of the rider's Sendra QR.";
                    $this->whatsappService->sendMessage($phoneNumber, $retry);
                    return;
                }
            }
        }

        // ── Review collection (optional 1–5 only; anything else skips and continues) ──
        $reviewHandled = $this->tryHandleReviewStep(
            $phoneNumber,
            $messageLower,
            $session,
            $sessionModel,
            $deliveryRequestModel,
            $deliveryOfferModel,
            $reviewModel,
            $userModel,
            $driverModel
        );
        if ($reviewHandled === self::REVIEW_STEP_DEFER_TO_MAIN) {
            $session = $sessionModel->findByPhone($phoneNumber) ?? $session;
        } elseif ($reviewHandled !== null) {
            if ($reviewHandled !== '') {
                $this->whatsappService->sendMessage($phoneNumber, $reviewHandled);
            }
            return;
        }

        // Pickup confirmation is QR-photo only (no typed SENDRA_PICKUP_{id} support).

        // ── Customer blocks rider from future pings ───────────────────────────
        if (preg_match('/^block_rider_(\d+)$/', $messageLower, $m)) {
            $u = $userModel->findByPhone($phoneNumber);
            if (!$u) {
                $this->whatsappService->sendMessage($phoneNumber, 'Register as a sender first.');
                return;
            }
            $blockedModel->block((int) $u['id'], (int) $m[1]);
            $this->whatsappService->sendMessage($phoneNumber, '🚫 Rider hidden from your future job broadcasts.');
            return;
        }

        if (preg_match('/^rebroadcast_(\d+)$/', $messageLower, $m)) {
            $this->handleRebroadcastChoice(
                $phoneNumber,
                (int) $m[1],
                $userModel,
                $deliveryRequestModel,
                $driverModel,
                $blockedModel
            );
            return;
        }

        if (preg_match('/^cancel_job_(\d+)$/', $messageLower, $m)) {
            $this->handleCancelAwaitingJob($phoneNumber, (int) $m[1], $userModel, $deliveryRequestModel);
            return;
        }

        if (in_array($messageLower, ['restart', 'start over', 'reset'], true)) {
            $this->handleRestartConversation(
                $phoneNumber,
                $sessionModel,
                $userModel,
                $driverModel,
                $deliveryRequestModel
            );
            return;
        }

        $senderUser = $userModel->findByPhone($phoneNumber);
        if ($senderUser) {
            $activeShip = $deliveryRequestModel->findSenderActiveShipment((int) $senderUser['id']);
            if ($activeShip) {
                if (in_array($messageLower, ['hi', 'hello', 'hey', 'menu', 'status'], true)) {
                    $this->whatsappService->sendMessage(
                        $phoneNumber,
                        $this->formatSenderActiveShipmentHelp($activeShip, $driverModel)
                    );
                    return;
                }
                if (in_array($messageLower, ['track', 'location', 'where', 'pin'], true)) {
                    $this->requestDriverLocationShare(
                        $phoneNumber,
                        $activeShip,
                        $sessionModel,
                        $driverModel
                    );
                    return;
                }
            }
        }

        $driverRow = $driverModel->findByPhone($phoneNumber);
        if ($driverRow) {
            $walletHandler = new RiderWalletWhatsAppHandler($this->db, $this->whatsappService, $sessionModel);
            if ($walletHandler->tryHandle($phoneNumber, $messageLower, $session, $driverRow)) {
                return;
            }

            $openRiderJob = $deliveryRequestModel->findOpenAssignedJobForDriver((int) $driverRow['id']);
            // Rider can update preferred bus-stop location when not on a job
            if (
                !$openRiderJob
                && in_array($messageLower, ['location', 'update location', 'set location', 'bus stop', 'bus-stop', 'update bus stop'], true)
            ) {
                $sessionModel->updateStep($phoneNumber, 'rider_update_location');
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    "📍 Please send your preferred bus-stop location so we can send delivery requests near you.\n\n"
                    . "Use WhatsApp's location feature:\n"
                    . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
                    . "2. Select 'Location'\n"
                    . "3. Search on the map or send your current location.\n\n"
                    . "No worries — you can change it anytime by typing *location*."
                );
                return;
            }
            if ($openRiderJob && in_array($messageLower, ['hi', 'hello', 'hey', 'menu', 'status'], true)) {
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    $this->formatRiderActiveJobHelp($openRiderJob, $session)
                );
                return;
            }
            if (($session['current_step'] ?? '') === 'awaiting_dropoff_pin') {
                if (preg_match('/^\d{4}$/', $rawTrim)) {
                    $this->handleRiderDropoffCodeSubmit(
                        $phoneNumber,
                        $rawTrim,
                        $driverRow,
                        $deliveryRequestModel,
                        $userModel,
                        $driverModel,
                        $sessionModel
                    );
                    return;
                }
                if (!str_starts_with($messageLower, 'accept')
                    && !str_starts_with($messageLower, 'reject')
                    && !str_starts_with($messageLower, 'counter')
                    && !str_starts_with($messageLower, 'payment_success')
                    && !str_starts_with($messageLower, 'accepting_offer')) {
                    $this->whatsappService->sendMessage(
                        $phoneNumber,
                        "🔢 *Dropoff step*\nType the *4-digit code* the receiver told you in person.\n\n"
                        . 'Anything else isn’t valid right now — say *hi* only after this job is finished.'
                    );
                    return;
                }
            }
            if (($session['current_step'] ?? '') === 'awaiting_location_share') {
                $coords = $this->parseWhatsAppLocationCoords($rawTrim);
                if ($coords !== null) {
                    $this->handleRiderSharedLocationPin(
                        $phoneNumber,
                        $coords[0],
                        $coords[1],
                        $driverRow,
                        $session,
                        $deliveryRequestModel,
                        $userModel,
                        $sessionModel
                    );
                    return;
                }
                if (!str_starts_with($messageLower, 'accept')
                    && !str_starts_with($messageLower, 'reject')
                    && !str_starts_with($messageLower, 'counter')) {
                    $this->whatsappService->sendMessage(
                        $phoneNumber,
                        "📍 *Share your location*\n\n"
                        . "Use WhatsApp's location feature:\n"
                        . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
                        . "2. Select 'Location'\n"
                        . "3. Choose 'Send your current location' or pick a spot on the map"
                    );
                    return;
                }
            }
        }

        $marketplaceHandler = MarketplaceWhatsAppHandler::build($this->db, $this->whatsappService);
        if ($marketplaceHandler->tryHandle($phoneNumber, $messageLower, $rawTrim, $session)) {
            return;
        }

        if (str_starts_with($messageLower, 'reject_')) {
            $this->whatsappService->sendMessage($phoneNumber, '👍 Declined. Say hi when you want another job.');
            return;
        }

        if (str_starts_with($messageLower, 'accept_')) {
            $requestId = (int) str_replace('accept_', '', $messageLower);
            $this->handleDriverAcceptDirect(
                $phoneNumber,
                $requestId,
                $driverModel,
                $deliveryRequestModel
            );
            return;
        }

        if (str_starts_with($messageLower, 'accepting_offer_')) {
            $parts = explode('_', $messageLower);
            $requestId = (int) ($parts[2] ?? 0);
            $offerId = (int) ($parts[3] ?? 0);
            $this->handleAcceptingOffer(
                $phoneNumber,
                $requestId,
                $offerId,
                $driverModel,
                $deliveryRequestModel,
                $deliveryOfferModel
            );
            return;
        }

        if (str_starts_with($messageLower, 'counter_')) {
            $requestId = (int) str_replace('counter_', '', $messageLower);
            $this->handleCounterOffer(
                $phoneNumber,
                $requestId,
                $sessionModel,
                $driverModel,
                $deliveryRequestModel
            );
            return;
        }

        if (str_starts_with($messageLower, 'payment_success_')) {
            $requestId = (int) str_replace('payment_success_', '', $messageLower);
            $this->handleLegacyPaymentSuccessCallback($requestId, $deliveryRequestModel, $driverModel, $userModel);
            return;
        }

        $currentStep = $session['current_step'] ?? 'start';

        $stepHandler = $router->getStepHandler($currentStep);

        $stepHandler->setSession($session);

        if (!$stepHandler->validateInput($messageLower, $session['language'] ?? 'en')) {
            $stepHandler->sendValidationFailureFeedback($phoneNumber);
            return;
        }

        $stepHandler->setUsername($displayName);

        $response = $stepHandler->handle($session, $messageLower);

        if ($response) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                $response
            );
        }
    }

    /**
     * Clear stuck delivery / offer state and return the person to a sensible home step.
     */
    private function handleRestartConversation(
        string $phoneNumber,
        Session $sessionModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel,
        DeliveryRequest $deliveryRequestModel
    ): void {
        $user   = $userModel->findByPhone($phoneNumber);
        $driver = $driverModel->findByPhone($phoneNumber);

        if ($user) {
            $active = $deliveryRequestModel->findSenderActiveShipment((int) $user['id']);
            if ($active) {
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    '🔄 *Restart isn’t available* while you have an open job (rider assigned or in transit). '
                    . 'Use *track* for the rider’s location, or wait until dropoff completes. '
                    . 'Contact support if something is wrong.'
                );

                return;
            }
        }

        if ($driver) {
            $open = $deliveryRequestModel->findOpenAssignedJobForDriver((int) $driver['id']);
            if ($open) {
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    '🔄 You have an open job (#'
                    . (int) $open['id']
                    . '). Finish pickup/dropoff first, then say *hi*.'
                );

                return;
            }
        }

        $sessionModel->update($phoneNumber, [
            'delivery_request_id'      => null,
            'pending_delivery_request' => null,
            'counter_request_id'       => null,
        ]);

        if ($user) {
            $sessionModel->updateStep($phoneNumber, 'sender_menu');
            $this->whatsappService->sendMessage(
                $phoneNumber,
                "🔄 *Order chat reset.* You're back at the main menu.\n\n"
                . "• Type *send* to start a new delivery\n"
                . "• *order* to track\n"
                . "• *help* for support\n"
                . "• *history* for past orders\n"
                . "• *restart* anytime you're stuck on an old offer or search"
            );
            return;
        }

        if ($driver) {
            $status = $driver['verification_status'] ?? 'pending';
            if (in_array($status, ['approved', 'approved_test', 'approved_paused'], true)) {
                $sessionModel->updateStep($phoneNumber, 'rider_ready');
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    '🔄 *Rider chat reset.* Any stuck job prompts were cleared. Say *hi* if you need the menu again.'
                );
                return;
            }
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '🔄 Cleared any stuck job context. Continue your rider signup where you left off, or say *hi*.'
            );
            return;
        }

        $sessionModel->updateStep($phoneNumber, 'start');
        $this->whatsappService->sendMessage(
            $phoneNumber,
            "🔄 *Fresh start.* Say *hi* to begin.\n\n_After you have an account, type *restart* anytime to clear a stuck order._"
        );
    }

    private function refreshExpiredPaymentReservation(DeliveryRequest $deliveryRequestModel, int $requestId): ?array
    {
        $request = $deliveryRequestModel->findById($requestId);
        if (!$request) {
            return null;
        }
        if (($request['status'] ?? '') !== 'driver_payment_pending') {
            return $request;
        }
        $exp = $request['reservation_expires_at'] ?? null;
        if (!$exp || strtotime($exp) > time()) {
            return $request;
        }
        $deliveryRequestModel->update($requestId, [
            'status' => 'searching_driver',
            'reserved_driver_id' => null,
            'reservation_expires_at' => null,
            'acceptance_payment_reference' => null,
        ]);
        return $deliveryRequestModel->findById($requestId);
    }

    /**
     * @return string|null Error message for driver, or null if OK to proceed
     */
    private function paymentPendingBlockForDriver(array $request, array $driver): ?string
    {
        if (($request['status'] ?? '') !== 'driver_payment_pending') {
            return null;
        }
        $exp = $request['reservation_expires_at'] ?? null;
        if (!$exp || strtotime($exp) <= time()) {
            return null;
        }
        if ((int) ($request['reserved_driver_id'] ?? 0) === (int) $driver['id']) {
            return '💳 Use the payment link we sent — you have ' . self::PAYMENT_RESERVATION_SECONDS . 's total to pay.';
        }
        return '⏳ Another rider is confirming this job. Wait a moment or try again.';
    }

    private function handleDriverAcceptDirect(
        string $phoneNumber,
        int $requestId,
        LogisticsDriver $driverModel,
        DeliveryRequest $deliveryRequestModel
    ): void {
        $driver = $driverModel->findByPhone($phoneNumber);
        if (!$driver) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Finish rider signup first.');
            return;
        }

        $driverId = (int) $driver['id'];
        $wallet = new RiderWalletService(
            $this->db,
            $driverModel,
            new RiderWalletTransaction($this->db)
        );

        if ($wallet->hasNegativeBalance($driverId)) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ Your wallet balance is negative. Type *fund* to add money before accepting new jobs.'
            );

            return;
        }

        $request = $this->refreshExpiredPaymentReservation($deliveryRequestModel, $requestId);
        if (!$request) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Request not found.');
            return;
        }

        $block = $this->paymentPendingBlockForDriver($request, $driver);
        if ($block !== null) {
            $this->whatsappService->sendMessage($phoneNumber, $block);
            return;
        }

        if (($request['status'] ?? '') === 'awaiting_rebroadcast') {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ Sender is choosing to rebroadcast or cancel — try again in a moment.'
            );
            return;
        }

        if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'], true)) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Already assigned.');
            return;
        }

        if (in_array($request['status'], ['draft', 'cancelled', 'payment_expired'], true)) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ This job is not open.');
            return;
        }

        if (($request['status'] ?? '') === 'driver_payment_pending') {
            $reserved = (int) ($request['reserved_driver_id'] ?? 0);
            if ($reserved !== $driverId) {
                $this->whatsappService->sendMessage($phoneNumber, '⏳ Another rider is confirming this job. Try again shortly.');

                return;
            }
        } elseif (!$deliveryRequestModel->tryAtomicClaimForDriver($requestId, $driverId, ['searching_driver'])) {
            $fresh = $deliveryRequestModel->findById($requestId);
            if ($fresh && in_array($fresh['status'] ?? '', ['driver_assigned', 'in_transit', 'delivered'], true)) {
                $this->whatsappService->sendMessage($phoneNumber, '⚠️ Already assigned.');
            } else {
                $this->whatsappService->sendMessage($phoneNumber, '⏳ Another rider just took this job. Watch for the next offer.');
            }

            return;
        }

        $fee = (float) $request['offered_price'] * 0.10;
        $bal = $wallet->getBalanceNgn($driverId);
        $graceAvailable = (int) ($driver['wallet_grace_used'] ?? 0) === 0;
        if ($bal < $fee && !$graceAvailable) {
            $deliveryRequestModel->releaseClaim($requestId, $driverId);
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ Not enough wallet balance for the 10% fee (₦' . number_format($fee, 0) . ").\n"
                . "Type *fund* to top up, or *balance* to check."
            );

            return;
        }

        $assigner = new LogisticsDriverWalletAssignmentService(
            $this->db,
            $this->whatsappService,
            __DIR__ . '/../..',
            new LogisticsAfterDriverFeePaidService($this->whatsappService, __DIR__ . '/../..')
        );
        $result = $assigner->assignWithWalletDebit($requestId, $driverId, (float) $request['offered_price']);

        if (!$result['ok']) {
            if (($result['error'] ?? '') === 'negative_balance') {
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    '⚠️ Fund your wallet — your balance is negative and you cannot accept jobs until it is topped up.'
                );

                return;
            }
            if (($result['error'] ?? '') === 'insufficient_balance') {
                $this->whatsappService->sendMessage(
                    $phoneNumber,
                    '⚠️ Not enough wallet balance. Type *fund* to add ₦' . number_format($fee, 0) . ' or more.'
                );

                return;
            }
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Could not confirm this job. Try again.');

            return;
        }

        $this->whatsappService->sendMessage(
            $phoneNumber,
            '✅ Job #' . $requestId . ' is yours — 10% fee (₦' . number_format($fee, 0) . ') deducted from your wallet.'
        );
    }

    private function handleAcceptingOffer(
        string $phoneNumber,
        int $requestId,
        int $offerId,
        LogisticsDriver $driverModel,
        DeliveryRequest $deliveryRequestModel,
        DeliveryOffer $deliveryOfferModel
    ): void {
        $request = $this->refreshExpiredPaymentReservation($deliveryRequestModel, $requestId);
        if ($request && ($request['status'] ?? '') === 'driver_payment_pending') {
            $exp = $request['reservation_expires_at'] ?? '';
            if ($exp && strtotime($exp) > time()) {
                $this->whatsappService->sendMessage($phoneNumber, '⏳ Another rider is confirming this job. Try again shortly.');
                return;
            }
        }

        if ($request && ($request['status'] ?? '') === 'awaiting_rebroadcast') {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ Search paused — tap Ping again or Cancel on our last message before accepting offers.'
            );
            return;
        }

        $rows = $deliveryOfferModel->findByID($offerId);
        $offer = (is_array($rows) && isset($rows[0])) ? $rows[0] : null;
        if (!$offer) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Offer not found.');
            return;
        }

        $request = $this->refreshExpiredPaymentReservation($deliveryRequestModel, $requestId);
        if (!$request) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Request not found.');
            return;
        }

        $driver = $driverModel->findById((int) $offer['driver_id']);
        if (!$driver) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Driver missing.');
            return;
        }

        if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'], true)) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Already taken.');
            return;
        }

        $deliveryOfferModel->updateStatus($offerId, 'accepted');
        $deliveryOfferModel->rejectOtherOffers($requestId, $offerId);

        $deliveryRequestModel->update($requestId, [
            'offered_price' => $offer['proposed_price'],
            'status' => 'searching_driver',
            'reserved_driver_id' => null,
            'reservation_expires_at' => null,
        ]);

        $this->whatsappService->sendMessage(
            $phoneNumber,
            '✅ Offer accepted — we\'re locking in your rider now. You\'ll get another message when they\'re confirmed.'
        );

        $driverPhone = (string) ($driver['phone'] ?? '');
        if ($driverPhone !== '') {
            $this->handleDriverAcceptDirect(
                $driverPhone,
                $requestId,
                $driverModel,
                $deliveryRequestModel
            );
        }
    }

    private function handleCounterOffer(
        string $phoneNumber,
        int $requestId,
        Session $sessionModel,
        LogisticsDriver $driverModel,
        DeliveryRequest $deliveryRequestModel
    ): void {
        $driver = $driverModel->findByPhone($phoneNumber);
        if (!$driver) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Register as rider first.');
            return;
        }

        $request = $this->refreshExpiredPaymentReservation($deliveryRequestModel, $requestId);
        if (!$request) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Request not found.');
            return;
        }

        $block = $this->paymentPendingBlockForDriver($request, $driver);
        if ($block !== null) {
            $this->whatsappService->sendMessage($phoneNumber, $block);
            return;
        }

        if (in_array($request['status'], ['driver_assigned', 'in_transit', 'delivered'], true)) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Already taken.');
            return;
        }

        if (($request['status'] ?? '') === 'awaiting_rebroadcast') {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Customer is rebroadcasting this job. Wait a moment.');
            return;
        }

        $sessionData = $sessionModel->findByPhone($phoneNumber);
        $sessionData['counter_request_id'] = $requestId;
        $sessionModel->update($phoneNumber, $sessionData);
        $sessionModel->updateStep($phoneNumber, 'driver_enter_counter');

        $this->whatsappService->sendMessage($phoneNumber, '💸 Enter your counter (₦ amount, numbers only):');
    }

    private function handlePickupVerify(
        string $phoneNumber,
        string $codeInput,
        Session $sessionModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel,
        LogisticsDriver $driverModel
    ): void {
        $parsedRequestId = PickupQrCodeService::parseAndVerify($codeInput);
        if ($parsedRequestId === null) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ That QR could not be verified. Send a clear photo of the rider\'s Sendra QR.'
            );

            return;
        }

        $u = $userModel->findByPhone($phoneNumber);
        if (!$u) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Only the sender can confirm pickup.');
            return;
        }

        $req = $deliveryRequestModel->findAssignedAwaitingPickupConfirm((int) $u['id']);
        if (!$req || (int) $req['id'] !== $parsedRequestId) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ Code does not match your active pickup. Do not hand over the package — risk is yours if you continue.'
            );
            return;
        }

        $driver = $driverModel->findById((int) ($req['reserved_driver_id'] ?? 0));
        if (!$driver) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ No rider on file. Contact support.');
            return;
        }

        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $deliveryRequestModel->update($parsedRequestId, [
            'pickup_confirmed_at' => date('Y-m-d H:i:s'),
            'status' => 'in_transit',
            'delivery_handoff_code' => $code,
        ]);

        $this->whatsappService->sendMessage(
            $phoneNumber,
            "✅ Rider verified for job #{$parsedRequestId}.\n\n"
            . "🔑 *Receiver handoff code:* {$code}\n"
            . "*Share this code with the receiver in person* (call/text them). They should *not* message this bot.\n"
            . "When the rider arrives, the receiver tells them these 4 digits; *the rider types the code here* to complete dropoff.\n\n"
            . 'Keep the code private.'
        );

        $receiverDigits = preg_replace('/\D/', '', (string) ($req['receiver_phone'] ?? ''));
        if ($receiverDigits !== '') {
            $this->whatsappService->sendMessage(
                $req['receiver_phone'],
                "📦 *Sendra — your package is on the way* (job #{$parsedRequestId}).\n\n"
                . "When the rider arrives, *tell them this code in person:*\n🔑 *{$code}*\n\n"
                . 'You do not need to reply to this number — the rider confirms in their own Sendra chat.'
            );
        }

        $sessionModel->update($driver['phone'], [
            'delivery_request_id'      => (string) $parsedRequestId,
            'pending_delivery_request' => null,
            'counter_request_id'       => null,
        ]);
        $sessionModel->updateStep($driver['phone'], 'awaiting_dropoff_pin');

        $riderMsg = "✅ Pickup confirmed for #{$parsedRequestId}. Head to dropoff.\n\n"
            . "🔢 When the *receiver tells you the 4-digit code in person*, type *only those digits* here (e.g. 0000).\n\n"
            . '📍 If the sender asks for your location, you’ll get a separate prompt to share a map pin.';
        if (!empty($req['store_order_id'])) {
            $riderMsg .= "\n\nIf the *receiver is unavailable*, tap the button we send next.";
            $this->whatsappService->sendButtonMessage(
                $driver['phone'],
                'Marketplace delivery — receiver not available?',
                ['receiver_unavailable_' . $parsedRequestId => '❌ Receiver unavailable']
            );
        }
        $this->whatsappService->sendMessage($driver['phone'], $riderMsg);
    }

    private function finalizeDropoffFromMatchedCode(
        array $req,
        DeliveryRequest $deliveryRequestModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel,
        Session $sessionModel
    ): void {
        $rid = (int) $req['id'];
        $deliveryRequestModel->update($rid, [
            'dropoff_confirmed_at' => date('Y-m-d H:i:s'),
            'status'               => 'delivered',
        ]);

        if (!empty($req['store_order_id'])) {
            (new MarketplaceOrderCompletionService(
                new StoreOrder($deliveryRequestModel->getDb()),
                new MarketplaceOrder($deliveryRequestModel->getDb()),
                $this->whatsappService
            ))->onDeliveryRequestDelivered($rid);
        }

        $sender = $userModel->findById((int) $req['user_id']);
        $driver   = $driverModel->findById((int) ($req['reserved_driver_id'] ?? 0));

        if ($sender) {
            $this->whatsappService->sendMessage(
                $sender['phone'],
                "✅ Dropoff completed for job #{$rid}.\n\n"
                . 'Optional: send *1–5* to rate your rider, or say *hi* to skip.'
            );
            $sessionModel->update($sender['phone'], ['delivery_request_id' => (string) $rid]);
            $sessionModel->updateStep($sender['phone'], 'logistics_review_rider');
        }

        if ($driver) {
            $this->whatsappService->sendMessage(
                $driver['phone'],
                "✅ Dropoff recorded for #{$rid}.\n\n"
                . 'Optional: send *1–5* to rate the customer, or say *hi* to skip.'
            );
            $sessionModel->update($driver['phone'], ['delivery_request_id' => (string) $rid]);
            $sessionModel->updateStep($driver['phone'], 'logistics_review_customer');
        }
    }

    private function handleRiderDropoffCodeSubmit(
        string $phoneNumber,
        string $fourDigits,
        array $driverRow,
        DeliveryRequest $deliveryRequestModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel,
        Session $sessionModel
    ): void {
        $req = $deliveryRequestModel->findDriverInTransitOpen((int) $driverRow['id']);
        if (!$req) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ No delivery is waiting for a dropoff code right now. Say *hi* if you need the menu.'
            );
            $sessionModel->updateStep($phoneNumber, 'rider_ready');

            return;
        }
        if (($req['delivery_handoff_code'] ?? '') !== $fourDigits) {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                '⚠️ *Wrong code.* Ask the receiver to confirm all 4 digits, then try again.'
            );

            return;
        }

        $this->finalizeDropoffFromMatchedCode($req, $deliveryRequestModel, $userModel, $driverModel, $sessionModel);
    }

    private function formatSenderActiveShipmentHelp(array $job, LogisticsDriver $driverModel): string
    {
        $id     = (int) $job['id'];
        $driver = $driverModel->findById((int) ($job['reserved_driver_id'] ?? 0));
        $name   = $driver ? trim(($driver['first_name'] ?? '') . ' ' . ($driver['last_name'] ?? '')) : '';
        $phone  = $driver ? (string) ($driver['preferred_phone_number'] ?: $driver['phone']) : '';

        if (($job['status'] ?? '') === 'driver_assigned') {
            $pickupLine = "At pickup, send a *photo* of the rider's Sendra QR in this chat to confirm them.\n\n";

            return "📦 *Job #{$id}* — rider assigned, pickup next.\n\n"
                . ($name !== '' ? "Rider: *{$name}*\n{$phone}\n\n" : '')
                . $pickupLine
                . "• *track* — ask rider to share location\n"
                . '• After delivery you can optionally rate *1–5* (numbers only)';
        }

        return "📦 *Job #{$id}* — *in transit* to dropoff.\n\n"
            . ($name !== '' ? "Rider: *{$name}*\n{$phone}\n\n" : '')
            . "• *track* — we’ll ask the rider to send a map pin\n"
            . "• *restart* is disabled while this job is open\n\n"
            . 'After dropoff you can optionally rate *1–5* (numbers only), or skip.';
    }

    private function formatRiderActiveJobHelp(array $job, array $session): string
    {
        $id   = (int) $job['id'];
        $step = $session['current_step'] ?? '';

        if (($job['status'] ?? '') === 'driver_assigned') {
            $pickupLine = "Go to pickup. The sender will *photograph your Sendra QR* in their chat to confirm you.\n\n";

            return "🚚 *Job #{$id}* — you’re assigned.\n\n"
                . $pickupLine
                . 'After pickup you’ll enter the receiver’s *4-digit code* (digits only) at dropoff.';
        }

        if (($job['status'] ?? '') === 'in_transit') {
            if ($step === 'awaiting_location_share') {
                return "🚚 *Job #{$id}* — customer asked for your *location*.\n\n"
                    . "Please send a *WhatsApp location pin* using WhatsApp's Location feature.";
            }

            return "🚚 *Job #{$id}* — *in transit*.\n\n"
                . "When the receiver gives you the code *in person*, type the *4 digits* here (nothing else).\n\n"
                . 'If the customer requests your location, you’ll get a separate prompt for a map pin.';
        }

        return '🚚 You have an open job — follow the latest instructions from Sendra above.';
    }

    private function requestDriverLocationShare(
        string $customerPhone,
        array $job,
        Session $sessionModel,
        LogisticsDriver $driverModel
    ): void {
        $driver = $driverModel->findById((int) ($job['reserved_driver_id'] ?? 0));
        if (!$driver) {
            $this->whatsappService->sendMessage($customerPhone, '⚠️ No rider on file for this job.');

            return;
        }

        $jid = (int) $job['id'];
        $sessionModel->update($driver['phone'], [
            'delivery_request_id' => (string) $jid,
        ]);
        $sessionModel->updateStep($driver['phone'], 'awaiting_location_share');

        $this->whatsappService->sendMessage(
            $customerPhone,
            "📍 We’ve asked your rider to share a location for job *#{$jid}*. You’ll get a Google Maps link when they send it."
        );
        $this->whatsappService->sendMessage(
            $driver['phone'],
            "📍 *Location request* from the customer (job #{$jid}).\n\n"
            . "Use WhatsApp's location feature:\n"
            . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
            . "2. Select 'Location'\n"
            . "3. Choose 'Send your current location'"
        );
    }

    private function handleRiderSharedLocationPin(
        string $riderPhone,
        float $lat,
        float $lng,
        array $driverRow,
        array $session,
        DeliveryRequest $deliveryRequestModel,
        LogisticsUser $userModel,
        Session $sessionModel
    ): void {
        $rid = (int) ($session['delivery_request_id'] ?? 0);
        $req = $rid > 0 ? $deliveryRequestModel->findById($rid) : null;
        if (
            !$req
            || (int) ($req['reserved_driver_id'] ?? 0) !== (int) $driverRow['id']
            || !in_array($req['status'] ?? '', ['driver_assigned', 'in_transit'], true)
        ) {
            $this->whatsappService->sendMessage(
                $riderPhone,
                '⚠️ That location doesn’t match your active job. Say *hi* if you need help.'
            );
            $sessionModel->updateStep($riderPhone, 'rider_ready');

            return;
        }

        $link = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode("{$lat},{$lng}");
        $sender = $userModel->findById((int) ($req['user_id'] ?? 0));
        if ($sender) {
            $this->whatsappService->sendMessage(
                $sender['phone'],
                '📍 *Rider location* (job #' . (int) $req['id'] . "):\n{$link}"
            );
        }

        $this->whatsappService->sendMessage($riderPhone, '✅ Location sent to the customer. Thanks!');

        if (($req['status'] ?? '') === 'in_transit') {
            $sessionModel->updateStep($riderPhone, 'awaiting_dropoff_pin');
        } else {
            $sessionModel->updateStep($riderPhone, 'rider_ready');
        }
    }

    private function parseCommaLatLng(string $message): ?array
    {
        $message = trim($message);
        if (!str_contains($message, ',')) {
            return null;
        }
        $parts = explode(',', $message, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $lat = trim($parts[0]);
        $lng = trim($parts[1]);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            return null;
        }

        return [$latF, $lngF];
    }

    /**
     * Accepts WhatsApp location JSON payloads (from our webhook) and legacy "lat,lng" strings.
     *
     * @return array{0: float, 1: float}|null
     */
    private function parseWhatsAppLocationCoords(string $rawMessage): ?array
    {
        $rawMessage = trim($rawMessage);
        if ($rawMessage === '') {
            return null;
        }

        // New: webhook forwards location as JSON, e.g. {"location":{"latitude":..,"longitude":..}}
        if ($rawMessage[0] === '{') {
            $j = json_decode($rawMessage, true);
            if (is_array($j)) {
                $loc = $j['location'] ?? null;
                if (is_array($loc)) {
                    $lat = $loc['latitude'] ?? ($loc['lat'] ?? null);
                    $lng = $loc['longitude'] ?? ($loc['lng'] ?? null);
                    if (is_numeric($lat) && is_numeric($lng)) {
                        $latF = (float) $lat;
                        $lngF = (float) $lng;
                        if ($latF >= -90 && $latF <= 90 && $lngF >= -180 && $lngF <= 180) {
                            return [$latF, $lngF];
                        }
                    }
                }

                // Some providers might send top-level keys.
                $latTop = $j['latitude'] ?? ($j['lat'] ?? null);
                $lngTop = $j['longitude'] ?? ($j['lng'] ?? null);
                if (is_numeric($latTop) && is_numeric($lngTop)) {
                    $latF = (float) $latTop;
                    $lngF = (float) $lngTop;
                    if ($latF >= -90 && $latF <= 90 && $lngF >= -180 && $lngF <= 180) {
                        return [$latF, $lngF];
                    }
                }
            }
        }

        // Legacy fallback (accepted, but we no longer mention it in prompts)
        return $this->parseCommaLatLng($rawMessage);
    }

    private function reconcileStuckSenderSession(
        string $phoneNumber,
        array $session,
        Session $sessionModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel
    ): ?array {
        $u = $userModel->findByPhone($phoneNumber);
        if (!$u) {
            return null;
        }

        $changed = false;
        $pendingId = (int) ($session['pending_delivery_request'] ?? 0);
        if ($pendingId > 0) {
            $pr = $deliveryRequestModel->findById($pendingId);
            if ($pr && in_array($pr['status'] ?? '', [
                'driver_assigned', 'in_transit', 'delivered', 'driver_payment_pending',
            ], true)) {
                $sessionModel->update($phoneNumber, ['pending_delivery_request' => null]);
                $changed = true;
            }
        }

        $step = $session['current_step'] ?? '';
        if (in_array($step, ['customer_choose_offer', 'find_courier'], true)) {
            $active = $deliveryRequestModel->findSenderActiveShipment((int) $u['id']);
            if ($active) {
                $sessionModel->update($phoneNumber, [
                    'current_step'             => 'sender_menu',
                    'pending_delivery_request' => null,
                ]);
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }

        return $sessionModel->findByPhone($phoneNumber) ?: null;
    }

    /**
     * Rider DB says in-transit + code issued, but session still on rider_ready → fix step so "hi" isn’t routed to ReadyStep.
     */
    private function reconcileRiderSessionForInTransitJob(
        string $phoneNumber,
        array $session,
        Session $sessionModel,
        LogisticsDriver $driverModel,
        DeliveryRequest $deliveryRequestModel
    ): ?array {
        $d = $driverModel->findByPhone($phoneNumber);
        if (!$d) {
            return null;
        }

        $transit = $deliveryRequestModel->findDriverInTransitOpen((int) $d['id']);
        if (!$transit) {
            return null;
        }

        $jobId  = (int) $transit['id'];
        $step   = $session['current_step'] ?? '';
        $sessRid = (int) ($session['delivery_request_id'] ?? 0);

        if ($step === 'awaiting_dropoff_pin' && $sessRid === $jobId) {
            return null;
        }
        if ($step === 'awaiting_location_share' && $sessRid === $jobId) {
            return null;
        }

        $repair = in_array($step, ['rider_ready', 'rider_approved', 'start'], true)
            || ($step === 'awaiting_dropoff_pin' && $sessRid !== $jobId);

        if (!$repair) {
            return null;
        }

        $sessionModel->update($phoneNumber, [
            'delivery_request_id'      => (string) $jobId,
            'pending_delivery_request' => null,
            'counter_request_id'       => null,
            'current_step'             => 'awaiting_dropoff_pin',
        ]);

        return $sessionModel->findByPhone($phoneNumber) ?: null;
    }

    /**
     * @return string|null null = not in review step; '' = no reply; self::REVIEW_STEP_DEFER_TO_MAIN = continue pipeline
     */
    private function tryHandleReviewStep(
        string $phoneNumber,
        string $messageLower,
        array $session,
        Session $sessionModel,
        DeliveryRequest $deliveryRequestModel,
        DeliveryOffer $deliveryOfferModel,
        LogisticsReview $reviewModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel
    ): ?string {
        $step = $session['current_step'] ?? '';
        if (!in_array($step, ['logistics_review_rider', 'logistics_review_customer'], true)) {
            return null;
        }

        $requestId = (int) ($session['delivery_request_id'] ?? 0);
        if ($requestId < 1) {
            $sessionModel->update($phoneNumber, [
                'current_step' => $step === 'logistics_review_rider' ? 'sender_menu' : 'rider_ready',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);

            return self::REVIEW_STEP_DEFER_TO_MAIN;
        }

        $req = $deliveryRequestModel->findById($requestId);
        if (!$req || ($req['status'] ?? '') !== 'delivered') {
            $sessionModel->update($phoneNumber, [
                'current_step' => $step === 'logistics_review_rider' ? 'sender_menu' : 'rider_ready',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);

            return self::REVIEW_STEP_DEFER_TO_MAIN;
        }

        $isRating = preg_match('/^\s*([1-5])\s*$/', $messageLower, $m);
        if (!$isRating) {
            return $this->skipLogisticsReviewAndContinue(
                $phoneNumber,
                $step,
                $requestId,
                $req,
                $deliveryRequestModel,
                $deliveryOfferModel,
                $reviewModel,
                $userModel,
                $driverModel,
                $sessionModel
            );
        }

        $rating = (int) $m[1];

        if ($step === 'logistics_review_rider') {
            $u = $userModel->findByPhone($phoneNumber);
            $driverId = (int) ($req['reserved_driver_id'] ?? 0);
            if (!$u || (int) $req['user_id'] !== (int) $u['id']) {
                return 'Only the sender rates here.';
            }
            if (!empty($req['review_sender_submitted_at'])) {
                $sessionModel->update($phoneNumber, [
                    'current_step' => 'sender_menu',
                    'delivery_request_id' => null,
                    'pending_delivery_request' => null,
                    'counter_request_id' => null,
                ]);

                return self::REVIEW_STEP_DEFER_TO_MAIN;
            }
            $reviewModel->create([
                'delivery_request_id' => $requestId,
                'reviewer_user_id' => (int) $u['id'],
                'reviewer_driver_id' => null,
                'reviewee_user_id' => null,
                'reviewee_driver_id' => $driverId,
                'rating' => $rating,
                'comment' => null,
            ]);
            $deliveryRequestModel->update($requestId, ['review_sender_submitted_at' => date('Y-m-d H:i:s')]);
            if ($this->finalizeCompletedJobIfReady(
                $requestId,
                $deliveryRequestModel,
                $deliveryOfferModel,
                $reviewModel,
                new CompletedDeliveryOrder($this->db),
                $sessionModel,
                $userModel,
                $driverModel
            )) {
                return 'Thanks — order completed and archived.';
            }
            $sessionModel->update($phoneNumber, [
                'current_step' => 'sender_menu',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);

            return 'Thanks — rating saved.';
        }

        $driver = $driverModel->findByPhone($phoneNumber);
        if (!$driver || (int) ($req['reserved_driver_id'] ?? 0) !== (int) $driver['id']) {
            return 'Only the assigned rider rates the customer here.';
        }
        if (!empty($req['review_driver_submitted_at'])) {
            $sessionModel->update($phoneNumber, [
                'current_step' => 'rider_ready',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);

            return self::REVIEW_STEP_DEFER_TO_MAIN;
        }
        $reviewModel->create([
            'delivery_request_id' => $requestId,
            'reviewer_user_id' => null,
            'reviewer_driver_id' => (int) $driver['id'],
            'reviewee_user_id' => (int) $req['user_id'],
            'reviewee_driver_id' => null,
            'rating' => $rating,
            'comment' => null,
        ]);
        $deliveryRequestModel->update($requestId, ['review_driver_submitted_at' => date('Y-m-d H:i:s')]);
        if ($this->finalizeCompletedJobIfReady(
            $requestId,
            $deliveryRequestModel,
            $deliveryOfferModel,
            $reviewModel,
            new CompletedDeliveryOrder($this->db),
            $sessionModel,
            $userModel,
            $driverModel
        )) {
            return 'Thanks — order completed and archived.';
        }
        $sessionModel->update($phoneNumber, [
            'current_step' => 'rider_ready',
            'delivery_request_id' => null,
            'pending_delivery_request' => null,
            'counter_request_id' => null,
        ]);

        return 'Thanks — saved.';
    }

    /**
     * Skip rating: mark this party done without a review row, then archive if both sides are done.
     */
    private function skipLogisticsReviewAndContinue(
        string $phoneNumber,
        string $step,
        int $requestId,
        array $req,
        DeliveryRequest $deliveryRequestModel,
        DeliveryOffer $deliveryOfferModel,
        LogisticsReview $reviewModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel,
        Session $sessionModel
    ): string {
        if ($step === 'logistics_review_rider') {
            $u = $userModel->findByPhone($phoneNumber);
            if (!$u || (int) $req['user_id'] !== (int) $u['id']) {
                return 'Only the sender uses this step.';
            }
            if (!empty($req['review_sender_submitted_at'])) {
                $sessionModel->update($phoneNumber, [
                    'current_step' => 'sender_menu',
                    'delivery_request_id' => null,
                    'pending_delivery_request' => null,
                    'counter_request_id' => null,
                ]);

                return self::REVIEW_STEP_DEFER_TO_MAIN;
            }
            $deliveryRequestModel->update($requestId, ['review_sender_submitted_at' => date('Y-m-d H:i:s')]);
        } else {
            $driver = $driverModel->findByPhone($phoneNumber);
            if (!$driver || (int) ($req['reserved_driver_id'] ?? 0) !== (int) $driver['id']) {
                return 'Only the assigned rider uses this step.';
            }
            if (!empty($req['review_driver_submitted_at'])) {
                $sessionModel->update($phoneNumber, [
                    'current_step' => 'rider_ready',
                    'delivery_request_id' => null,
                    'pending_delivery_request' => null,
                    'counter_request_id' => null,
                ]);

                return self::REVIEW_STEP_DEFER_TO_MAIN;
            }
            $deliveryRequestModel->update($requestId, ['review_driver_submitted_at' => date('Y-m-d H:i:s')]);
        }

        $req = $deliveryRequestModel->findById($requestId) ?: $req;

        if ($this->finalizeCompletedJobIfReady(
            $requestId,
            $deliveryRequestModel,
            $deliveryOfferModel,
            $reviewModel,
            new CompletedDeliveryOrder($this->db),
            $sessionModel,
            $userModel,
            $driverModel
        )) {
            return '';
        }

        if ($step === 'logistics_review_rider') {
            $sessionModel->update($phoneNumber, [
                'current_step' => 'sender_menu',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);
        } else {
            $sessionModel->update($phoneNumber, [
                'current_step' => 'rider_ready',
                'delivery_request_id' => null,
                'pending_delivery_request' => null,
                'counter_request_id' => null,
            ]);
        }

        return self::REVIEW_STEP_DEFER_TO_MAIN;
    }

    private function finalizeCompletedJobIfReady(
        int $requestId,
        DeliveryRequest $drm,
        DeliveryOffer $dom,
        LogisticsReview $lrm,
        CompletedDeliveryOrder $cdo,
        Session $sessionModel,
        LogisticsUser $userModel,
        LogisticsDriver $driverModel
    ): bool {
        $req = $drm->findById($requestId);
        if (!$req || empty($req['review_sender_submitted_at']) || empty($req['review_driver_submitted_at'])) {
            return false;
        }

        $reviews = $lrm->listByRequestId($requestId);

        try {
            $this->db->beginTransaction();
            $cdo->insertArchive($req, $reviews);
            $dom->deleteByRequestId($requestId);
            $lrm->deleteByRequestId($requestId);
            if (!$drm->deleteById($requestId)) {
                throw new \RuntimeException('Failed to delete delivery_requests row');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('finalizeCompletedJobIfReady: ' . $e->getMessage());

            return false;
        }

        $sender = $userModel->findById((int) ($req['user_id'] ?? 0));
        $driver = $driverModel->findById((int) ($req['reserved_driver_id'] ?? 0));
        if ($sender) {
            $sessionModel->resetLogisticsProgress($sender['phone'], 'sender_menu');
            $this->whatsappService->sendMessage($sender['phone'], '✅ Order completed and saved. Type hi anytime.');
        }
        if ($driver) {
            $sessionModel->resetLogisticsProgress($driver['phone'], 'rider_ready');
            $this->whatsappService->sendMessage($driver['phone'], '✅ Order completed. You are ready for the next job.');
        }

        return true;
    }

    private function runCourierBroadcastForRequest(
        int $requestId,
        DeliveryRequest $drm,
        LogisticsDriver $driverModel,
        UserBlockedDriver $blockedModel
    ): int {
        $req = $drm->findById($requestId);
        if (!$req) {
            return 0;
        }
        $blocked = [];
        if (!empty($req['user_id'])) {
            $blocked = $blockedModel->listBlockedDriverIdsForUser((int) $req['user_id']);
        }
        $job = new FindNearbyCouriersJob(
            $requestId,
            $driverModel,
            $drm,
            $this->whatsappService,
            $blocked
        );

        return $job->handle();
    }

    private function handleRebroadcastChoice(
        string $phoneNumber,
        int $requestId,
        LogisticsUser $userModel,
        DeliveryRequest $drm,
        LogisticsDriver $driverModel,
        UserBlockedDriver $blockedModel
    ): void {
        $u = $userModel->findByPhone($phoneNumber);
        $req = $drm->findById($requestId);
        if (!$u || !$req || (int) $req['user_id'] !== (int) $u['id']) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ That request is not on your account.');
            return;
        }
        if (($req['status'] ?? '') !== 'awaiting_rebroadcast') {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ This job is not waiting for rebroadcast.');
            return;
        }

        $window = 110;
        $drm->update($requestId, [
            'status' => 'searching_driver',
            'bidding_expires_at' => date('Y-m-d H:i:s', time() + $window),
            'bidding_closed' => 0,
        ]);

        $n = $this->runCourierBroadcastForRequest($requestId, $drm, $driverModel, $blockedModel);
        $this->whatsappService->sendMessage(
            $phoneNumber,
            "🔁 Riders pinged again for #{$requestId} ({$n} notified).\nYou'll hear when someone takes the job."
        );
    }

    private function handleCancelAwaitingJob(
        string $phoneNumber,
        int $requestId,
        LogisticsUser $userModel,
        DeliveryRequest $drm
    ): void {
        $u = $userModel->findByPhone($phoneNumber);
        $req = $drm->findById($requestId);
        if (!$u || !$req || (int) $req['user_id'] !== (int) $u['id']) {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Not your request.');
            return;
        }
        if (($req['status'] ?? '') !== 'awaiting_rebroadcast') {
            $this->whatsappService->sendMessage($phoneNumber, '⚠️ Use the menu to manage this order.');
            return;
        }

        $drm->update($requestId, [
            'status' => 'cancelled',
            'bidding_closed' => 1,
        ]);
        $this->whatsappService->sendMessage(
            $phoneNumber,
            "❌ Request #{$requestId} cancelled. Type hi when you need a new delivery."
        );
    }

    private function handleLegacyPaymentSuccessCallback(
        int $requestId,
        DeliveryRequest $deliveryRequestModel,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel
    ): void {
        $request = $deliveryRequestModel->findById($requestId);
        if (!$request) {
            return;
        }

        $qrPath = $this->generatePickupQR($requestId);
        $deliveryRequestModel->update($requestId, [
            'status' => 'driver_assigned',
            'reserved_driver_id' => $request['reserved_driver_id'],
            'pickup_qr_code' => $qrPath,
        ]);

        $courier = $driverModel->findById((int) $request['reserved_driver_id']);
        $sender = $userModel->findById((int) $request['user_id']);

        if ($courier && $sender) {
            $plate = ($courier['vehicle_type'] ?? '') === 'bicycle'
                ? ''
                : ($courier['vehicle_plate_number'] ?: ($courier['license_number'] ?? ''));
            $vLabel = \App\Utils\VehicleTypeHelper::displayLabel((string) ($courier['vehicle_type'] ?? ''));
            $plateLine = ($courier['vehicle_type'] ?? '') === 'bicycle' ? '' : "\nPlate: {$plate}";
            $this->whatsappService->sendMessage(
                $sender['phone'],
                "🎉 Rider assigned #{$requestId}\n{$courier['first_name']} {$courier['last_name']}\n"
                . ($courier['preferred_phone_number'] ?: $courier['phone'])
                . "\n{$courier['vehicle_color']} {$vLabel}{$plateLine}"
            );
            $abs = $this->absoluteUploadPath($qrPath);
            $this->whatsappService->sendImage(
                $courier['phone'],
                $abs,
                '🔐 Show this QR at pickup — the sender will photograph it in their chat to confirm you.'
            );
        }
    }

    private function absoluteUploadPath(string $relative): string
    {
        if (filter_var($relative, FILTER_VALIDATE_URL)) {
            return $relative;
        }
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    private function generatePickupQR(int $requestId): string
    {
        $data = PickupQrCodeService::payloadForRequest($requestId);

        $qrCode = new QrCode(
            data: $data,
            size: 300,
            margin: 10
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $dir = dirname(__DIR__, 2) . '/uploads/qrcodes';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . "/delivery_{$requestId}.png";

        $result->saveToFile($file);

        return "uploads/qrcodes/delivery_{$requestId}.png";
    }
}
