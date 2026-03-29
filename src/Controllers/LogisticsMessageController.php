<?php

namespace App\Controllers;

use App\Services\WhatsAppService;
use App\Utils\StepRouter;

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;

class LogisticsMessageController
{
    private $whatsappService;
    private $authController;
    private $db;

    public function __construct(
        WhatsAppService $whatsappService,
        LogisticsAuthController $authController,
        $db
    ) {
        $this->whatsappService = $whatsappService;
        $this->authController  = $authController;
        $this->db              = $db;
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

        // ─────────────────────────────
        // 2️⃣ Load session
        // ─────────────────────────────

        $session = $sessionModel->findByPhone($phoneNumber);

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
            $deliveryRequestModel
        );

        $currentStep = $session['current_step'] ?? 'start';

        error_log("=== Current Step for {$phoneNumber}: {$currentStep} ===");

        $stepHandler = $router->getStepHandler($currentStep);

        // ─────────────────────────────
        // 4️⃣ Validate input
        // ─────────────────────────────

        if (!$stepHandler->validateInput($message)) {

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
}