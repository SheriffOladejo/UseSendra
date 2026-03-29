<?php
namespace App\Steps;

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Services\WhatsAppService;

abstract class BaseStep implements StepInterface
{
    protected $sessionModel;
    protected $whatsappService;
    protected $driverModel;
    protected $userModel;
    protected $deliveryRequestModel;
    protected string $validationError = "⚠️ Invalid input. Please try again.";
    protected string $username = "";

    public function __construct(
        Session $sessionModel,
        WhatsAppService $whatsappService,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel
    ) {
        $this->sessionModel = $sessionModel;
        $this->whatsappService = $whatsappService;
        $this->driverModel = $driverModel;
        $this->userModel = $userModel;
        $this->deliveryRequestModel = $deliveryRequestModel;
    }

    public function validateInput(string $message): bool
    {
        return true;
    }

    protected function nextStep($phone, $step)
    {
        $this->sessionModel->updateStep($phone, $step);
    }

    public function getValidationError(): string
    {
        return $this->validationError;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

}