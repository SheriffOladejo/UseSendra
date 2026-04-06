<?php
namespace App\Steps;

use App\Models\DeliveryOffer;
use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Services\WhatsAppService;
use App\Utils\Translator;   

abstract class BaseStep implements StepInterface
{
    protected $sessionModel;
    protected array $session = [];
    protected $whatsappService;
    protected $driverModel;
    protected $userModel;
    protected $deliveryRequestModel;
    protected $deliveryOfferModel;
    protected string $validationError;
    protected string $username = "";

    public function __construct(
        Session $sessionModel,
        WhatsAppService $whatsappService,
        LogisticsDriver $driverModel,
        LogisticsUser $userModel,
        DeliveryRequest $deliveryRequestModel,
        DeliveryOffer $deliveryOfferModel,
        array $session = []
    ) {
        $this->sessionModel = $sessionModel;
        $this->whatsappService = $whatsappService;
        $this->driverModel = $driverModel;
        $this->userModel = $userModel;
        $this->deliveryRequestModel = $deliveryRequestModel;
        $this->deliveryOfferModel = $deliveryOfferModel;

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

    public function setSession(array $session): void
    {
        $this->session = $session;
        // Get language from session if available, else default to 'en'
        $lang = $session['language'] ?? 'en';

        $translator = new Translator($lang);
        //$this->validationError = $translator->get('invalid_input');
    }

}