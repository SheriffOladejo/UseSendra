<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class DriverRejectedStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        $driver = $this->driverModel->findByPhone($phone);

        $reasons = json_decode($driver['rejection_reason'], true);

        if (!$reasons || empty($reasons)) {
            return "Please resend the requested document.";
        }

        $current = $reasons[0];

        switch ($current) {

            case 'license_picture':

                $this->driverModel->update($phone, [
                    'license_picture_url' => $message
                ]);

                break;

            case 'vehicle_photo':

                $this->driverModel->update($phone, [
                    'vehicle_picture_url' => $message
                ]);

                break;

            case 'selfie':

                $this->driverModel->update($phone, [
                    'selfie_url' => $message
                ]);

                break;
        }

        /**
         * Remove fixed reason
         */

        array_shift($reasons);

        if (empty($reasons)) {

            $this->driverModel->update($phone, [
                'verification_status' => 'pending',
                'rejection_reason' => null
            ]);

            $this->nextStep($phone, 'rider_ready');

            return
            "✅ Thanks! Your documents have been resubmitted.\n\n".
            "Our team will review them shortly.";
        }

        /**
         * Save remaining issues
         */

        $this->driverModel->update($phone, [
            'rejection_reason' => json_encode($reasons)
        ]);

        $next = $reasons[0];

        return
        "✅ Document received.\n\n".
        "Next, please send:\n".
        strtoupper(str_replace('_',' ', $next));
    }

    public function validateInput(string $message): bool
    {
        return true;
    }
}