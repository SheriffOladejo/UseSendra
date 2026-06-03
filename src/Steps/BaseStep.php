<?php
namespace App\Steps;

use App\Models\DeliveryOffer;
use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\DeliveryRequest;
use App\Services\WhatsAppService;
use App\Utils\Translator;   
use App\Utils\DeliverySummaryFormatter;

abstract class BaseStep implements StepInterface
{
    protected $sessionModel;
    protected array $session = [];
    protected $whatsappService;
    protected $driverModel;
    protected $userModel;
    protected $deliveryRequestModel;
    protected $deliveryOfferModel;
    protected string $validationError = '';
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

    /**
     * After validateInput() fails: default is plain text; override in steps that use buttons/lists
     * so users see the same choices again.
     */
    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $err = trim($this->getValidationError());
        if ($err !== '') {
            $this->whatsappService->sendMessage($phoneNumber, $err);
        }
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

    /**
     * Session data helpers (whatsapp_sessions.data JSON).
     *
     * We keep edit-mode flags here so we don't need new DB columns.
     *
     * @return array<string, mixed>
     */
    protected function getSessionData(): array
    {
        // Some DBs use whatsapp_sessions.data, others use whatsapp_sessions.session_data.
        $raw = $this->session['data']
            ?? ($this->session['session_data'] ?? '{}');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function setSessionData(array $data): void
    {
        $phone = $this->session['phone_number'] ?? null;
        if (!is_string($phone) || $phone === '') {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        // Write back to whichever column exists in the loaded session row.
        if (array_key_exists('data', $this->session)) {
            $this->sessionModel->update($phone, ['data' => $json]);
            $this->session['data'] = $json;
            return;
        }
        if (array_key_exists('session_data', $this->session)) {
            $this->sessionModel->update($phone, ['session_data' => $json]);
            $this->session['session_data'] = $json;
            return;
        }

        // Fallback: attempt 'data' (Session::update() will fail gracefully if column missing).
        $this->sessionModel->update($phone, ['data' => $json]);
        $this->session['data'] = $json;
    }

    protected function setEditMode(string $target): void
    {
        $d = $this->getSessionData();
        $d['edit'] = [
            'active' => true,
            'target' => $target,
            'return_step' => 'summary',
        ];
        $this->setSessionData($d);
    }

    protected function isEditMode(): bool
    {
        $d = $this->getSessionData();
        return !empty($d['edit']['active']);
    }

    protected function getEditTarget(): ?string
    {
        $d = $this->getSessionData();
        $t = $d['edit']['target'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    protected function clearEditMode(): void
    {
        $d = $this->getSessionData();
        unset($d['edit']);
        $this->setSessionData($d);
    }

    /**
     * End edit mode and resend summary with Confirm/Edit buttons.
     */
    protected function finishEditAndShowSummary(string $phone, int $requestId): void
    {
        $this->clearEditMode();
        $this->nextStep($phone, 'summary');

        $req = $this->deliveryRequestModel->findById($requestId);
        $body = is_array($req) && !empty($req)
            ? DeliverySummaryFormatter::format($req)
            : 'Please choose an option to proceed with your order:';

        $this->whatsappService->sendButtonMessage(
            $phone,
            $body,
            [
                'confirm' => '✅ Confirm',
                'edit'    => '✏️ Edit',
            ]
        );
    }

    /**
     * Lightweight per-session rate limiting (stored in whatsapp_sessions.data JSON).
     * Used to avoid exhausting paid third-party API credits during onboarding retries.
     *
     * Buckets are per phone number (session), e.g. "prembly.nin", "prembly.plate", "prembly.bvn".
     *
     * @return array{allowed: bool, retry_after_seconds: int, count_today: int}
     */
    protected function rateLimitAllow(string $bucket, int $maxPerDay, int $minIntervalSeconds = 20): array
    {
        $now = time();
        $day = date('Y-m-d', $now);

        $d = $this->getSessionData();
        $rl = $d['rate_limits'] ?? [];
        if (!is_array($rl)) {
            $rl = [];
        }
        $b = $rl[$bucket] ?? [];
        if (!is_array($b)) {
            $b = [];
        }

        $bucketDay = is_string($b['day'] ?? null) ? $b['day'] : null;
        $count = is_numeric($b['count'] ?? null) ? (int) $b['count'] : 0;
        $lastAt = is_numeric($b['last_at'] ?? null) ? (int) $b['last_at'] : 0;

        if ($bucketDay !== $day) {
            $bucketDay = $day;
            $count = 0;
            $lastAt = 0;
        }

        $since = $lastAt > 0 ? ($now - $lastAt) : PHP_INT_MAX;
        if ($lastAt > 0 && $since < $minIntervalSeconds) {
            return [
                'allowed' => false,
                'retry_after_seconds' => max(1, $minIntervalSeconds - $since),
                'count_today' => $count,
            ];
        }

        if ($count >= $maxPerDay) {
            // Retry window is "tomorrow" (roughly).
            $tomorrow = strtotime($day . ' 23:59:59') ?: ($now + 86400);
            $retry = max(60, $tomorrow - $now);
            return [
                'allowed' => false,
                'retry_after_seconds' => $retry,
                'count_today' => $count,
            ];
        }

        $count++;
        $lastAt = $now;
        $rl[$bucket] = [
            'day' => $bucketDay,
            'count' => $count,
            'last_at' => $lastAt,
        ];
        $d['rate_limits'] = $rl;
        $this->setSessionData($d);

        return [
            'allowed' => true,
            'retry_after_seconds' => 0,
            'count_today' => $count,
        ];
    }

}