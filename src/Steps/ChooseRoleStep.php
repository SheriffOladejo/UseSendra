<?php

namespace App\Steps;

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
        DeliveryRequest $deliveryRequestModel
    ) {
        parent::__construct($sessionModel, $whatsappService, $driverModel, $userModel, $deliveryRequestModel);
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

            $this->nextStep($phone, 'sender_menu');

            $this->whatsappService->sendMessage(
                $phone,
                $t->get('sender_menu_intro')
            );

            return '';
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