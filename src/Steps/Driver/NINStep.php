<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Services\PremblyVerificationService;
use App\Utils\Translator;

class NinStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $raw = trim($message);
        $isSkip = strtolower($raw) === 'skip';
        $nin  = $isSkip ? '' : (preg_replace('/\D/', '', $raw) ?? '');
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $this->driverModel->update($phone, ['nin' => ($nin !== '' ? $nin : null)]);

        // Verify immediately (soft-fail when API is down; strict when API explicitly rejects).
        if (!$isSkip && $nin !== '') {
            $lim = $this->rateLimitAllow('prembly.nin', 3, 25);
            if (!$lim['allowed']) {
                return "⏳ Please wait {$lim['retry_after_seconds']}s before trying again.\n\n"
                    . "Tip: If you're stuck, you can type *skip* and we’ll verify later.";
            }

            $prembly = new PremblyVerificationService();
            if ($prembly->isConfigured()) {
                $resp = $prembly->verifyNin($nin);
                $payload = $resp['data'] ?? [];

                $apiSaysOk = is_array($payload)
                    && (($payload['status'] ?? null) === true || ($payload['response_code'] ?? null) === '00');

                if ($resp['success'] && !$apiSaysOk) {
                    // Explicit negative from provider → ask to retry.
                    $detail = is_string($payload['detail'] ?? null) ? (string) $payload['detail'] : 'Verification failed';
                    return "❌ {$detail}\n\nPlease re-enter your *11-digit NIN* (numbers only), or type *skip*.";
                }

                // Save verification evidence for ops review (in session JSON, no schema change).
                $sd = $this->getSessionData();
                $sd['kyc'] = is_array($sd['kyc'] ?? null) ? $sd['kyc'] : [];
                $sd['kyc']['nin'] = [
                    'checked_at' => date('c'),
                    'provider' => 'prembly',
                    'http_code' => $resp['http_code'],
                    'success' => $resp['success'],
                    'response_code' => $payload['response_code'] ?? null,
                    'detail' => $payload['detail'] ?? null,
                    'reference' => is_array($payload['verification'] ?? null) ? ($payload['verification']['reference'] ?? null) : null,
                ];

                // Name match (not overly strict): require first/last to broadly align.
                $driver = $this->driverModel->findByPhone($phone) ?: null;
                $first = is_array($driver) ? (string) ($driver['first_name'] ?? '') : '';
                $last = is_array($driver) ? (string) ($driver['last_name'] ?? '') : '';

                $ninData = is_array($payload['nin_data'] ?? null) ? $payload['nin_data'] : (is_array($payload['Nin_data'] ?? null) ? $payload['Nin_data'] : []);
                $ninFirst = is_array($ninData) ? (string) ($ninData['firstname'] ?? '') : '';
                $ninLast = is_array($ninData) ? (string) ($ninData['surname'] ?? '') : '';

                $sd['kyc']['nin']['name'] = [
                    'profile_first' => $first,
                    'profile_last' => $last,
                    'nin_first' => $ninFirst,
                    'nin_last' => $ninLast,
                    'match' => $this->namesLooselyMatch($first, $last, $ninFirst, $ninLast),
                ];

                $this->setSessionData($sd);
            }
        }

        // Next: driver license photo upload.
        $this->nextStep($phone, 'driver_id');

        return $t->get('license_picture_prompt');
    }

    public function validateInput(string $nin): bool
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);

        $nin = trim($nin);
        if (strtolower($nin) === 'skip') {
            return true;
        }
        if (preg_match('/^\d{11}$/', $nin)) {
            return true;
        }

        $this->validationError = $t->get('nin_invalid');
        return false;
    }

    private function namesLooselyMatch(string $profileFirst, string $profileLast, string $ninFirst, string $ninLast): bool
    {
        $pf = $this->normNameToken($profileFirst);
        $pl = $this->normNameToken($profileLast);
        $nf = $this->normNameToken($ninFirst);
        $nl = $this->normNameToken($ninLast);

        if ($pf === '' || $pl === '' || $nf === '' || $nl === '') {
            // Can't compare reliably → don't block.
            return true;
        }

        $lastOk = ($pl === $nl) || str_starts_with($pl, $nl) || str_starts_with($nl, $pl);
        $firstOk = ($pf === $nf) || str_starts_with($pf, $nf) || str_starts_with($nf, $pf);

        return $lastOk && $firstOk;
    }

    private function normNameToken(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z]+/', '', $s) ?? '';
        return $s;
    }
}