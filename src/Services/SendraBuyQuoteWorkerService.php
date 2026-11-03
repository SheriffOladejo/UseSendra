<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use PDO;

/**
 * Processes pending buy_quote_jobs and delivers WhatsApp quote confirmations.
 */
final class SendraBuyQuoteWorkerService
{
    private PDO $db;

    private SendraBuyQuoteJobService $jobs;

    private WhatsAppService $whatsapp;

    private User $users;

    public function __construct(PDO $db, WhatsAppService $whatsapp)
    {
        $this->db = $db;
        $this->jobs = new SendraBuyQuoteJobService($db);
        $this->whatsapp = $whatsapp;
        $this->users = new User($db);
    }

    /**
     * @return array{processed: int, done: int, failed: int, skipped: int, expired: int}
     */
    public function run(int $batchSize = 10): array
    {
        $stats = ['processed' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'expired' => 0];
        if (!$this->jobs->isAvailable()) {
            error_log('SendraBuyQuoteWorker: buy_quote_jobs table missing — run migration 20260612_buy_quote_jobs.sql');

            return $stats;
        }

        $stats['expired'] = $this->jobs->expireStalePending();
        $stats['recovered'] = $this->jobs->recoverStaleProcessing();
        $buySvc = new SendraBuyRampService();
        $claimed = $this->jobs->claimPendingJobs($batchSize);

        foreach ($claimed as $job) {
            $stats['processed']++;
            $outcome = $this->processOne($job, $buySvc);
            if ($outcome === 'done') {
                $stats['done']++;
            } elseif ($outcome === 'failed') {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $job
     * @return 'done'|'failed'|'skipped'
     */
    private function processOne(array $job, SendraBuyRampService $buySvc): string
    {
        $jobId = (string) ($job['id'] ?? '');
        $phone = (string) ($job['user_phone'] ?? '');
        if ($jobId === '' || $phone === '') {
            return 'skipped';
        }

        $fresh = $this->jobs->findById($jobId);
        if ($fresh === null || ($fresh['status'] ?? '') !== 'processing') {
            return 'skipped';
        }

        if (!$this->contextExpectsJob($phone, $jobId)) {
            $this->jobs->cancelJob($jobId, 'context_mismatch');

            return 'skipped';
        }

        $t0 = hrtime(true);
        $mode = (string) ($job['quote_mode'] ?? 'naira');
        $asset = strtoupper((string) ($job['asset'] ?? 'USDC'));
        $aptos = (string) ($job['aptos_address'] ?? '');

        try {
            if ($mode === 'crypto_target') {
                $target = (float) ($job['target_crypto'] ?? 0);
                $quote = $buySvc->quoteCryptoBuy($target, $asset, $aptos);
            } else {
                $quote = $buySvc->quoteNairaBuy((float) ($job['pay_ngn'] ?? 0), $asset, $aptos);
            }
        } catch (\Throwable $e) {
            error_log('SendraBuyQuoteWorker quote exception job=' . $jobId . ': ' . $e->getMessage());
            $this->jobs->failJob($jobId, 'quote_exception', [
                'schema_version' => 1,
                'error' => ['code' => 'quote_exception', 'message' => $e->getMessage()],
            ]);
            $this->sendQuoteFailureMessage($phone, $asset, 'quote_exception', $mode);

            return 'failed';
        }

        $durationMs = (int) ((hrtime(true) - $t0) / 1_000_000);

        $fresh = $this->jobs->findById($jobId);
        if ($fresh === null || ($fresh['status'] ?? '') === 'cancelled') {
            return 'skipped';
        }

        if (!$this->contextExpectsJob($phone, $jobId)) {
            $this->jobs->cancelJob($jobId, 'cancelled_during_quote');

            return 'skipped';
        }

        if (empty($quote['success'])) {
            $err = (string) ($quote['error'] ?? 'quote_failed');
            $failJson = [
                'schema_version' => 1,
                'quoted_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_ms' => $durationMs,
                'error' => ['code' => $err, 'stage' => 'quote'],
            ];
            $this->jobs->failJob($jobId, $err, $failJson);
            $this->sendQuoteFailureMessage($phone, $asset, $err, $mode);

            return 'failed';
        }

        $resultJson = SendraBuyQuoteJobService::buildResultJsonV1($quote, $job, $durationMs);
        $legacy = is_array($resultJson['legacy_quote'] ?? null) ? $resultJson['legacy_quote'] : [];
        $this->jobs->completeJob($jobId, $resultJson);

        $existingCtx = $this->loadBuyChatContext($phone);
        $ctx = [
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'fiat' => 'NGN',
            'quote_job_id' => $jobId,
            'quote_mode' => $mode,
            'pay_ngn' => (float) ($legacy['pay_ngn'] ?? $job['pay_ngn'] ?? 0),
            'asset' => $asset,
            'receive_crypto_display' => (string) ($legacy['receive_crypto_display'] ?? '—'),
            'centiiv_usdc' => $legacy['centiiv_usdc'] ?? null,
            'oneclick_dry_quote_data' => $legacy['oneclick_dry_quote_data'] ?? null,
        ];
        if ($mode === 'crypto_target') {
            $ctx['target_crypto'] = (float) ($job['target_crypto'] ?? 0);
        }
        $ctx = $this->mergeBuyRoutingMeta($existingCtx, $ctx);
        $this->users->update($phone, ['chat_context' => json_encode($ctx, JSON_UNESCAPED_UNICODE)]);

        $wa = $this->whatsappForPhone($phone, $existingCtx);
        $this->sendQuoteConfirmMessage(
            $phone,
            $legacy,
            $asset,
            $mode,
            isset($job['target_crypto']) ? (float) $job['target_crypto'] : null,
            $buySvc->isOneClickBridgeConfigured(),
            $wa
        );

        return 'done';
    }

    private function contextExpectsJob(string $phone, string $jobId): bool
    {
        $user = $this->users->findByPhone($phone);
        if (!is_array($user)) {
            return false;
        }
        $raw = $user['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || ($raw['flow'] ?? '') !== 'buy') {
            return false;
        }
        if (($raw['step'] ?? '') !== 'quote_pending') {
            return false;
        }

        return (string) ($raw['quote_job_id'] ?? '') === $jobId;
    }

    /**
     * @param array<string, mixed> $legacy
     */
    private function sendQuoteConfirmMessage(
        string $phone,
        array $legacy,
        string $asset,
        string $mode,
        ?float $targetCrypto,
        bool $bridgeConfigured,
        WhatsAppService $wa
    ): void {
        $payNgn = (float) ($legacy['pay_ngn'] ?? 0);
        // Fee/rate breakdown is kept off the user-facing quote.
        $bridgeNote = $bridgeConfigured
            ? "\n\nFunds are routed via our payout network to your Aptos wallet."
            : '';
        $recv = (string) ($legacy['receive_crypto_display'] ?? '—');

        if ($mode === 'crypto_target' && $targetCrypto !== null) {
            $targetDisplay = rtrim(rtrim(number_format($targetCrypto, 6, '.', ''), '0'), '.');
            $payDisplay = number_format($payNgn, 0, '.', ',');
            $body = "🛒 *Buy *{$targetDisplay} {$asset}*\n\n" .
                "Pay *≈ ₦{$payDisplay}* to receive *≈ {$recv} {$asset}* (indicative).{$bridgeNote}\n\n" .
                'Continue to get a *virtual bank account*?';
        } else {
            $payDisplay = number_format($payNgn, 0, '.', ',');
            $body = "🛒 *Buy with ₦{$payDisplay}*\n\n" .
                "You receive *≈ {$recv} {$asset}* (indicative).{$bridgeNote}\n\n" .
                'Continue to get a *virtual bank account*?';
        }

        $wa->sendButtonMessage($phone, $body, [
            'buy_quote_yes' => '✅ Yes',
            'buy_quote_change' => '✏️ Different amount',
        ]);
    }

    private function sendQuoteFailureMessage(string $phone, string $asset, string $err, string $mode): void
    {
        if ($this->isUserCancelled($phone)) {
            return;
        }

        $existingCtx = $this->loadBuyChatContext($phone);
        $wa = $this->whatsappForPhone($phone, $existingCtx);

        $body = '❌ No buy rate available right now.';
        if ($err === 'min_ngn') {
            $minNgn = SendraBuyRampService::buyMinNgn();
            $body = "❌ Minimum buy is about *₦" . number_format($minNgn, 0, '.', ',') . "*.\n\nTry *buy {$minNgn}* or more.";
        } elseif (preg_match('/no liquidity|no rail|no route|liquidity provider/i', $err)) {
            $body .= "\n\nThe NGN→crypto corridor may be temporarily down. Try again later.";
        } else {
            $body .= "\n\nTry again shortly or type *cancel* then *buy*.";
        }
        $wa->sendMessage($phone, $body);

        $user = $this->users->findByPhone($phone);
        if (!is_array($user)) {
            return;
        }
        $raw = $user['chat_context'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw) && ($raw['flow'] ?? '') === 'buy' && ($raw['step'] ?? '') === 'quote_pending') {
            $raw['step'] = 'await_asset';
            unset($raw['quote_job_id']);
            $this->users->update($phone, ['chat_context' => json_encode($raw, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function isUserCancelled(string $phone): bool
    {
        $user = $this->users->findByPhone($phone);
        if (!is_array($user)) {
            return true;
        }
        $raw = $user['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBuyChatContext(string $phone): ?array
    {
        $user = $this->users->findByPhone($phone);
        if (!is_array($user)) {
            return null;
        }
        $raw = $user['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && ($raw['flow'] ?? '') === 'buy' ? $raw : null;
    }

    /**
     * Keep v2 origin bot routing when the async worker updates buy state.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function mergeBuyRoutingMeta(?array $existing, array $ctx): array
    {
        if (!is_array($existing)) {
            return $ctx;
        }
        foreach (['origin_whatsapp_phone_id', 'aptos_v2'] as $key) {
            if (!empty($existing[$key]) && empty($ctx[$key])) {
                $ctx[$key] = $existing[$key];
            }
        }

        return $ctx;
    }

    /**
     * Reply on the WhatsApp number the user used to start the buy (v2 Logistics vs legacy Aptos).
     *
     * @param array<string, mixed>|null $chatContext
     */
    private function whatsappForPhone(string $phone, ?array $chatContext = null): WhatsAppService
    {
        $routingMeta = null;
        if (is_array($chatContext)) {
            $origin = trim((string) ($chatContext['origin_whatsapp_phone_id'] ?? ''));
            if ($origin !== '') {
                $routingMeta = ['origin_whatsapp_phone_id' => $origin];
            }
        }

        $phoneId = \AptosV2\Support\WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
            $this->db,
            $phone,
            $routingMeta,
            $this->whatsapp->getPhoneNumberId()
        );
        if ($phoneId === '' || $phoneId === $this->whatsapp->getPhoneNumberId()) {
            return $this->whatsapp;
        }

        $access = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');

        return new WhatsAppService($access, $phoneId);
    }
}
