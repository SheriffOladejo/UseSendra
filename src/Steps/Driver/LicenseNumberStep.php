<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Services\PremblyVerificationService;
use App\Utils\Translator;

class LicenseNumberStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $raw = trim($message);
        $isSkip = strtolower($raw) === 'skip';
        $normalized = strtoupper(str_replace([' ', '-'], '', $raw));

        if (!$isSkip) {
            // Save plate number (canonical field used in job messages)
            $this->driverModel->update($phone, [
                'vehicle_plate_number' => $normalized,
            ]);

            // Verify plate on the go (soft-fail if API down, strict if explicit rejection).
            $lim = $this->rateLimitAllow('prembly.plate', 3, 25);
            if (!$lim['allowed']) {
                return "⏳ Please wait {$lim['retry_after_seconds']}s before trying again.\n\n"
                    . "Or type *skip* to continue onboarding.";
            }

            $prembly = new PremblyVerificationService();
            if ($prembly->isConfigured()) {
                $resp = $prembly->verifyVehiclePlate($normalized);
                $payload = $resp['data'] ?? [];

                $apiSaysOk = is_array($payload)
                    && (($payload['status'] ?? null) === true || ($payload['response_code'] ?? null) === '00');

                if ($resp['success'] && !$apiSaysOk) {
                    $detail = is_string($payload['detail'] ?? null) ? (string) $payload['detail'] : 'Verification failed';
                    return "❌ {$detail}\n\nPlease re-enter your vehicle plate number (e.g., *ABC123DE*), or type *skip*.";
                }

                $sd = $this->getSessionData();
                $sd['kyc'] = is_array($sd['kyc'] ?? null) ? $sd['kyc'] : [];
                $sd['kyc']['plate'] = [
                    'checked_at' => date('c'),
                    'provider' => 'prembly',
                    'http_code' => $resp['http_code'],
                    'success' => $resp['success'],
                    'response_code' => is_array($payload) ? ($payload['response_code'] ?? null) : null,
                    'detail' => is_array($payload) ? ($payload['detail'] ?? null) : null,
                    'reference' => is_array($payload['verification'] ?? null) ? ($payload['verification']['reference'] ?? null) : null,
                    'data' => is_array($payload['data'] ?? null) ? [
                        'vehicle_number' => $payload['data']['vehicle_number'] ?? null,
                        'vehicle_name' => $payload['data']['vehicle_name'] ?? null,
                        'vehicle_color' => $payload['data']['vehicle_color'] ?? null,
                    ] : null,
                ];
                $this->setSessionData($sd);
            }
        } else {
            $this->driverModel->update($phone, [
                'vehicle_plate_number' => null,
            ]);
        }

        $this->nextStep($phone, 'vehicle_picture');

        // Return translated prompt
        return $t->get('vehicle_picture_prompt');
    }

    public function validateInput(string $plateNumber): bool
    {
        $t = new Translator($this->session['language'] ?? 'en');
        $plateNumber = trim($plateNumber);
        if (strtolower($plateNumber) === 'skip') {
            return true;
        }
        // Normalize input
        $normalized = strtoupper(trim($plateNumber));

        // Remove spaces and dashes
        $stripped = str_replace(['-', ' '], '', $normalized);

        // Check length only
        if (strlen($stripped) > 5 && strlen($stripped) <= 10) {
            return true;
        }

        $this->validationError = $t->get('plate_number_invalid');
        return false;
    }

}