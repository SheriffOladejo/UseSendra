<?php

namespace App\Steps;

use App\Models\DeliveryOffer;
use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Services\WhatsAppService;
use App\Utils\Translator;

class ChooseRoleStep extends BaseStep
{
    public function __construct(
        Session $sessionModel,
        WhatsAppService $whatsappService,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel,
        DeliveryOffer $deliveryOfferModel
    ) {
        parent::__construct($sessionModel, $whatsappService, $driverModel, $userModel, $deliveryRequestModel, $deliveryOfferModel);
    }

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $message = strtolower(trim($message));

        $lang = $session['language'] ?? 'en';
        $t = new Translator($lang);

        if ($message === "sender") {

            $this->userModel->create(
                $phone,
                $this->getUsername()
            );

            // Start a new draft request immediately (short flow starts with pickup pin)
            $user = $this->userModel->findByPhone($phone);
            if ($user) {
                $requestId = $this->deliveryRequestModel->create([
                    'user_id' => $user['id'],
                    'sender_phone' => $phone,
                ]);
                if ($requestId) {
                    $this->sessionModel->update($phone, ['delivery_request_id' => $requestId]);
                }
            }

            $this->nextStep($phone, 'pickup_location');

            return "Nice, let’s get this moving.\n\n"
                . "First, where are we picking up from?\n\n"
                . "Please share the pickup location using WhatsApp's location sharing feature:\n"
                . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
                . "2. Select 'Location'\n"
                . "3. Search on the map or send your current location.";
        }

        if ($message === "rider") {

            $this->nextStep($phone, 'rider_name');

            return $t->get('rider_profile_setup');
        }

        $this->whatsappService->sendButtonMessage(
            $phone,
            $t->get('choose_role_invalid'),
            [
                "sender" => $t->get('role_sender'),
                "rider"  => $t->get('role_rider')
            ]
        );

        return "";
    }
}