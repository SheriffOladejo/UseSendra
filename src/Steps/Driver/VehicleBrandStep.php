<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class VehicleBrandStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $brandModel = strtoupper(trim($message));

        // Save vehicle brand/model
        $this->driverModel->update($phone, [
            'vehicle_brand_model' => $brandModel
        ]);

        // Auto-approve if IDs verified successfully (NIN + plate) during onboarding.
        $sd = $this->getSessionData();
        $kyc = is_array($sd['kyc'] ?? null) ? $sd['kyc'] : [];
        $ninOk = isset($kyc['nin']['success']) ? (bool) $kyc['nin']['success'] : false;
        $plateOk = isset($kyc['plate']['success']) ? (bool) $kyc['plate']['success'] : false;

        if ($ninOk && $plateOk) {
            $this->driverModel->update($phone, [
                'verification_status' => 'approved',
            ]);

            // Next: collect operating bus-stop so they can start receiving nearby offers.
            $this->nextStep($phone, 'rider_location_preference');

            return "✅ *Verification successful!*\n\n"
                . "Your rider account is now approved.\n\n"
                . "Next step: please set your *preferred bus-stop location* so we can send delivery requests near you.\n\n"
                . $t->get('rider_location_request');
        }

        // IDs not verified: continue onboarding without hard blocking.
        $this->nextStep($phone, 'rider_location_preference');

        return "✅ Profile saved.\n\n"
            . "Next step: please set your *preferred bus-stop location*.\n\n"
            . $t->get('rider_location_request');
    }

    public function validateInput(string $message): bool
    {
        $message = trim($message);

        $valid = preg_match('/^[A-Za-z0-9\s\-\/]{3,50}$/', $message) === 1;

        if (!$valid) {
            $lang = $this->session['language'] ?? 'en';
            $t = new Translator($lang);
            $this->validationError = $t->get('vehicle_brand_invalid');
        }

        return $valid;
    }
}