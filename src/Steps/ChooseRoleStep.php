<?php

namespace App\Steps;

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Services\WhatsAppService;

class ChooseRoleStep extends BaseStep
{
    public function __construct(
        Session $sessionModel,
        WhatsAppService $whatsappService,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel
    ) {
        // Initialize the BaseStep constructor
        parent::__construct($sessionModel, $whatsappService, $driverModel, $userModel, $deliveryRequestModel);
    }

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $message = strtolower(trim($message));

        if ($message === "sender") {

            $this->userModel->create(
                $phone,
                $this->getUsername()
            );

            $this->nextStep($phone, 'sender_menu');

            $this->whatsappService->sendMessage(
                $phone,
                "I can help you send packages fast right here on WhatsApp.\n\n" .
                "Type one of the following to continue:\n" .
                "/send — To send a package 📦\n" .
                "/track — To track your order 📍\n" .
                "/help — To get help ❓\n" .
                "/history — To see your delivery history 🕘"
            );

            return '';
        }

        if ($message === "rider") {
            $this->nextStep($phone, 'rider_name');

            return "Great! Let's set up your rider profile.\n\nPlease enter your first name and last name as it appears on your ID (e.g. Chinedu Sani Adeyemi).";
        }

        $this->whatsappService->sendButtonMessage(
            $phone,
            "I didn't understand that. Please choose one of the options below to get started.",
            [
                "sender" => "📦 Send Package",
                "rider"  => "🏍 Delivery Rider"
            ]
        );

        return "";
    }
}