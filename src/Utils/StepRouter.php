<?php
namespace App\Utils;

use App\Services\WhatsAppService;
use App\Models\Session;
use App\Models\DeliveryRequest;

use App\Steps\StartStep;
use App\Steps\ChooseRoleStep;

use App\Steps\User\ShowMenuStep;
use App\Steps\User\PickupLocationStep;
use App\Steps\User\PickupNotesStep;

use App\Steps\User\DropoffLocationStep;
use App\Steps\User\DropoffNotesStep;
use App\Steps\User\SenderPhoneStep;
use App\Steps\User\ReceiverPhoneStep;
use App\Steps\User\ItemDescriptionStep;
use App\Steps\User\ItemNameStep;
use App\Steps\User\ItemPictureStep;
use App\Steps\User\VehicleTypeStep as UserVehicleTypeStep;
use App\Steps\User\PayerStep;
use App\Steps\User\ConfirmationStep;
use App\Steps\User\PriceOfferStep;
use App\Steps\User\PaymentMethodStep;

use App\Steps\User\FindCourierStep;
use App\Steps\Driver\LocationPreferenceStep;
use App\Steps\Driver\VehicleTypeStep;
use App\Steps\Driver\ReadyStep;
use App\Steps\Driver\LicenseNumberStep;
use App\Steps\Driver\NINStep;
use App\Steps\Driver\VehicleColorStep;
use App\Steps\Driver\PhoneStep;
use App\Steps\Driver\FullNameStep;

use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;

use App\Steps\Driver\VehicleBrandStep;
use App\Steps\Driver\VehiclePictureStep;
use App\Steps\Driver\LicensePictureStep;
use App\Steps\Driver\InReviewStep;

class StepRouter
{
    private $steps = [

        // onboarding
        'start' => StartStep::class,
        'choose_role' => ChooseRoleStep::class,
        'sender_menu' => ShowMenuStep::class,

        'rider_name' => FullNameStep::class,
        'rider_phone' => PhoneStep::class,
        'vehicle_type' => VehicleTypeStep::class,
        'plate_number' => LicenseNumberStep::class,
        'driver_id' => LicensePictureStep::class,
        'vehicle_picture' => VehiclePictureStep::class,
        'vehicle_color' => VehicleColorStep::class,
        'vehicle_brand' => VehicleBrandStep::class,
        'nin' => NINStep::class,
        'rider_location_preference' => LocationPreferenceStep::class,
        'in_review' => InReviewStep::class,

        // delivery builder flow
        'pickup_location' => PickupLocationStep::class,
        'pickup_notes' => PickupNotesStep::class,
        'dropoff_location' => DropoffLocationStep::class,
        'dropoff_notes' => DropoffNotesStep::class,
        'item_name' => ItemNameStep::class,
        'item_description' => ItemDescriptionStep::class,
        'item_picture' => ItemPictureStep::class,
        'sender_phone' => SenderPhoneStep::class,
        'receiver_phone' => ReceiverPhoneStep::class,
        'sender_vehicle_type' => UserVehicleTypeStep::class,
        'find_courier' => FindCourierStep::class,

        'price_offer' => PriceOfferStep::class,
        'payer' => PayerStep::class,
        'payment_method' => PaymentMethodStep::class,

        'summary' => ConfirmationStep::class,
        'rider_ready' => ReadyStep::class,
    ];

    private $sessionModel;
    private $whatsappService;
    private $driverModel;
    private $userModel;
    private $deliveryRequestModel;

    public function __construct(Session $sessionModel, WhatsAppService $whatsappService, LogisticsDriver $driverModel, LogisticsUser $userModel, DeliveryRequest $deliveryRequestModel)
    {
        $this->sessionModel = $sessionModel;
        $this->whatsappService = $whatsappService;
        $this->driverModel = $driverModel;
        $this->userModel = $userModel;
        $this->deliveryRequestModel = $deliveryRequestModel;
    }

    public function getStepHandler(string $step)
    {
        $stepClass = $this->steps[$step] ?? StartStep::class;

        return new $stepClass(
            $this->sessionModel,
            $this->whatsappService,
            $this->driverModel,
            $this->userModel,
            $this->deliveryRequestModel
        );
    }
}