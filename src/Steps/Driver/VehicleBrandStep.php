<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class VehicleBrandStep extends BaseStep
{
    // ✅ FIXED - Added string type to match BaseStep
    protected string $validationError = "Please enter your vehicle brand and model (e.g., TVS HLX 125 or Honda Activa).";

    /**
     * Handle vehicle brand + model input
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $brandModel = strtoupper(trim($message));

        // Save vehicle brand/model
        $this->driverModel->update($phone, [
            'vehicle_brand_model' => $brandModel
        ]);

        // Move to next step
        $this->nextStep($phone, 'rider_ready');

        return "Perfect! ✅\n\nYour vehicle: *{$brandModel}* has been saved.\n\n⏳ We're reviewing your details now…This usually takes a few minutes";
    }

    /**
     * Validate brand-model format
     */
    public function validateInput(string $message): bool
    {
        error_log("Validating vehicle brand/model: " . $message);
        $message = trim($message);

        // Allow letters, numbers, spaces, dash and slash
        return preg_match('/^[A-Za-z0-9\s\-\/]{3,50}$/', $message) === 1;
    }
}