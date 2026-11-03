<?php

declare(strict_types=1);

namespace AptosV2\Services\Buy;

use App\Services\SendraBuyQuoteJobService;
use App\Services\SendraBuyRampService;
use App\Services\WhatsAppService;

/**
 * Buy quote enqueue + synchronous fallback (ported from MessageController buy* quote methods).
 */
final class QuoteService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private \PDO $db,
    ) {
    }

    public function cancelActiveForPhone(string $phone): void
    {
        $svc = $this->quoteJobs();
        if ($svc !== null) {
            $svc->cancelActiveForPhone($phone);
        }
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    public function enqueueAndPrompt(
        string $from,
        array $userRow,
        string $quoteMode,
        string $asset,
        ?float $payNgn,
        ?float $targetCrypto,
        ?string $inboundWamid,
        string $destChain = 'aptos',
        ?string $solanaDestination = null,
    ): array {
        $buySvc = new SendraBuyRampService();
        $aptos = (string) ($userRow['aptos_address'] ?? '');
        $jobs = $this->quoteJobs();

        if ($destChain === 'stellar' || $destChain === 'solana' || $destChain === 'arc') {
            return $quoteMode === 'crypto_target' && $targetCrypto !== null
                ? $this->applySyncCrypto($from, $userRow, $targetCrypto, $asset, $buySvc, $destChain, $solanaDestination)
                : $this->applySyncNaira($from, $userRow, (float) $payNgn, $asset, $buySvc, $destChain, $solanaDestination);
        }

        if ($destChain === 'aptos' && \App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return $quoteMode === 'crypto_target' && $targetCrypto !== null
                ? $this->applySyncDaya($from, $userRow, 'crypto_target', null, $targetCrypto, $asset)
                : $this->applySyncDaya($from, $userRow, 'naira', (float) $payNgn, null, $asset);
        }

        if ($jobs === null || !$jobs->isAvailable()) {
            error_log('Sendra buy: quote jobs unavailable — sync fallback for ' . $from);

            return $quoteMode === 'crypto_target' && $targetCrypto !== null
                ? $this->applySyncCrypto($from, $userRow, $targetCrypto, $asset, $buySvc)
                : $this->applySyncNaira($from, $userRow, (float) $payNgn, $asset, $buySvc);
        }

        $enq = $jobs->enqueue(
            $from,
            $quoteMode,
            $asset,
            $aptos,
            $payNgn,
            $targetCrypto,
            $inboundWamid
        );
        if (!$enq['success']) {
            if (!empty($enq['already_active'])) {
                $this->whatsapp->sendMessage(
                    $from,
                    "⏳ A rate is still loading.\n\nType *cancel* to stop, then try your buy again."
                );

                return ['outcome' => 'stay', 'meta' => ['action' => 'buy_quote_already_queued']];
            }
            error_log('Sendra buy enqueue failed ' . $from . ': ' . ($enq['error'] ?? ''));

            return $quoteMode === 'crypto_target' && $targetCrypto !== null
                ? $this->applySyncCrypto($from, $userRow, $targetCrypto, $asset, $buySvc)
                : $this->applySyncNaira($from, $userRow, (float) $payNgn, $asset, $buySvc);
        }

        $jobId = (string) ($enq['job_id'] ?? '');
        $patch = [
            'fiat' => 'NGN',
            'quote_mode' => $quoteMode,
            'quote_job_id' => $jobId,
            'asset' => $asset,
            'aptos_v2' => true,
            'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
        ];
        if ($quoteMode === 'naira') {
            $patch['pay_ngn'] = $payNgn;
        } else {
            $patch['target_crypto'] = $targetCrypto;
        }
        $jobs->markProgressSent($jobId);
        $this->whatsapp->sendMessage($from, '⏳ Getting a rate…');

        return [
            'outcome' => 'transition',
            'flow' => 'buy',
            'step' => 'quote_pending',
            'patch' => $patch,
            'meta' => ['action' => 'buy_quote_queued'],
        ];
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    private function applySyncDaya(
        string $from,
        array $userRow,
        string $mode,
        ?float $payNgn,
        ?float $targetCrypto,
        string $asset,
    ): array {
        $this->whatsapp->sendMessage($from, '⏳ Getting a rate…');
        $svc = new \App\Services\SendraDayaAptosService();
        $quote = $svc->quoteBuy($mode, $payNgn, $targetCrypto, $asset);
        if (empty($quote['success'])) {
            $this->whatsapp->sendMessage(
                $from,
                (string) ($quote['user_message'] ?? '❌ No buy rate available right now.')
            );

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_quote_failed']];
        }

        $this->sendQuoteConfirmButtons(
            $from,
            new SendraBuyRampService(),
            (float) $quote['pay_ngn'],
            $asset,
            $targetCrypto,
            (string) $quote['receive_crypto_display'],
            $mode === 'crypto_target',
            'aptos',
            null
        );

        $patch = $this->quoteStatePatch(
            $userRow,
            $asset,
            (float) $quote['pay_ngn'],
            (string) $quote['receive_crypto_display'],
            $quote['receive_crypto'] ?? null,
            null,
            'aptos',
            null,
            $targetCrypto
        );
        $patch['ramp'] = 'daya';
        $patch['daya_rate_id'] = (string) ($quote['rate_id'] ?? '');
        $patch['daya_rate'] = (float) ($quote['rate'] ?? 0);
        $patch['instruct_ngn'] = (float) ($quote['instruct_ngn'] ?? $quote['pay_ngn']);
        $patch['fee_hint'] = (string) ($quote['fee_hint'] ?? '');
        $patch['rate_hint'] = (string) ($quote['rate_hint'] ?? '');

        return [
            'outcome' => 'transition',
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'patch' => $patch,
            'meta' => ['action' => 'buy_daya_quote'],
        ];
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    private function applySyncCrypto(
        string $from,
        array $userRow,
        float $targetCrypto,
        string $asset,
        SendraBuyRampService $buySvc,
        string $destChain = 'aptos',
        ?string $solanaDestination = null,
    ): array {
        $this->whatsapp->sendMessage($from, '⏳ Getting a rate…');
        $quote = $buySvc->quoteCryptoBuy(
            $targetCrypto,
            $asset,
            (string) ($userRow['aptos_address'] ?? ''),
            $destChain === 'stellar',
            $destChain === 'solana',
            $destChain === 'arc'
        );
        if (!$quote['success']) {
            $err = (string) ($quote['error'] ?? 'quote_failed');
            error_log("Sendra buy crypto quote failed {$from}: {$err}");
            $body = "❌ No buy rate available for *{$targetCrypto} {$asset}* right now.";
            if (preg_match('/no liquidity|no rail|no route|liquidity provider/i', $err)) {
                $body .= "\n\nThe NGN→crypto corridor may be temporarily down. Try again later.";
            } elseif ($err === 'min_ngn') {
                $body .= "\n\nTry a larger amount (minimum spend is about *₦100*).";
            } else {
                $body .= "\n\nTry again shortly.";
            }
            $this->whatsapp->sendMessage($from, $body);

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_quote_failed']];
        }

        $payNgn = (float) ($quote['pay_ngn'] ?? 0);
        if ($payNgn < 100) {
            $this->whatsapp->sendMessage($from, "❌ Amount too small. Try at least *0.01 {$asset}* or use *buy 1000*.");

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_quote_too_small']];
        }

        $this->sendQuoteConfirmButtons(
            $from,
            $buySvc,
            $payNgn,
            $asset,
            $targetCrypto,
            (string) $quote['receive_crypto_display'],
            true,
            $destChain,
            $solanaDestination
        );

        return [
            'outcome' => 'transition',
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'patch' => $this->quoteStatePatch(
                $userRow,
                $asset,
                $payNgn,
                (string) $quote['receive_crypto_display'],
                $quote['centiiv_usdc'] ?? null,
                is_array($quote['oneclick_dry_quote_data'] ?? null) ? $quote['oneclick_dry_quote_data'] : null,
                $destChain,
                $solanaDestination,
                $targetCrypto
            ),
            'meta' => ['action' => 'buy_crypto_quote'],
        ];
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    private function applySyncNaira(
        string $from,
        array $userRow,
        float $payNgn,
        string $asset,
        SendraBuyRampService $buySvc,
        string $destChain = 'aptos',
        ?string $solanaDestination = null,
    ): array {
        $this->whatsapp->sendMessage($from, '⏳ Getting a rate…');
        $quote = $buySvc->quoteNairaBuy(
            $payNgn,
            $asset,
            (string) ($userRow['aptos_address'] ?? ''),
            $destChain === 'stellar',
            $destChain === 'solana',
            $destChain === 'arc'
        );
        if (!$quote['success']) {
            $err = (string) ($quote['error'] ?? 'quote_failed');
            error_log("Sendra buy quote failed {$from}: {$err}");
            $body = '❌ No buy rate available right now.';
            if (preg_match('/no liquidity|no rail|no route|liquidity provider/i', $err)) {
                $body .= "\n\nThe NGN→crypto corridor may be temporarily down. Try again later.";
            } else {
                $body .= "\n\nTry again shortly.";
            }
            $this->whatsapp->sendMessage($from, $body);

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_quote_failed']];
        }

        $this->sendQuoteConfirmButtons(
            $from,
            $buySvc,
            $payNgn,
            $asset,
            null,
            (string) $quote['receive_crypto_display'],
            false,
            $destChain,
            $solanaDestination
        );

        return [
            'outcome' => 'transition',
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'patch' => $this->quoteStatePatch(
                $userRow,
                $asset,
                $payNgn,
                (string) $quote['receive_crypto_display'],
                $quote['centiiv_usdc'] ?? null,
                is_array($quote['oneclick_dry_quote_data'] ?? null) ? $quote['oneclick_dry_quote_data'] : null,
                $destChain,
                $solanaDestination,
                null
            ),
            'meta' => ['action' => 'buy_quote'],
        ];
    }

    /**
     * @param array<string, mixed> $userRow
     * @param mixed $centiivUsdc
     * @param array<string, mixed>|null $oneclickDry
     * @return array<string, mixed>
     */
    private function quoteStatePatch(
        array $userRow,
        string $asset,
        float $payNgn,
        string $receiveDisplay,
        mixed $centiivUsdc,
        ?array $oneclickDry,
        string $destChain,
        ?string $solanaDestination,
        ?float $targetCrypto,
    ): array {
        $patch = [
            'fiat' => 'NGN',
            'pay_ngn' => $payNgn,
            'asset' => $asset,
            'receive_crypto_display' => $receiveDisplay,
            'centiiv_usdc' => $centiivUsdc,
            'oneclick_dry_quote_data' => $oneclickDry,
            'dest_chain' => in_array($destChain, ['stellar', 'solana', 'arc'], true) ? $destChain : 'aptos',
            'stellar_deposit_memo' => (string) ($userRow['stellar_deposit_memo'] ?? ''),
            'stellar_address' => (string) ($userRow['stellar_address'] ?? ''),
            'arc_address' => (string) ($userRow['arc_address'] ?? ''),
            'aptos_v2' => true,
            'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
        ];
        if ($targetCrypto !== null) {
            $patch['target_crypto'] = $targetCrypto;
        }
        if ($destChain === 'solana' && $solanaDestination !== null && $solanaDestination !== '') {
            $patch['solana_destination'] = $solanaDestination;
        }

        return $patch;
    }

    private function sendQuoteConfirmButtons(
        string $from,
        SendraBuyRampService $buySvc,
        float $payNgn,
        string $asset,
        ?float $targetCrypto,
        string $receiveDisplay,
        bool $cryptoTargetMode,
        string $destChain = 'aptos',
        ?string $solanaDestination = null,
    ): void {
        // Fee/rate breakdown is kept off the user-facing quote; pay ≈ receive is enough.
        if ($destChain === 'solana') {
            $short = $solanaDestination !== null && $solanaDestination !== ''
                ? $this->shortSolana($solanaDestination)
                : 'the address you pasted';
            $bridgeNote = "\n\nAfter you pay Naira, Sendra sends USDC on *Solana* to `{$short}`.\nSendra does not hold a Solana wallet for you.";
        } elseif ($destChain === 'stellar') {
            $bridgeNote = "\n\nCrypto is paid to Sendra on Stellar with *your unique memo*, then moved to your wallet.";
        } elseif ($destChain === 'arc') {
            $bridgeNote = "\n\nUSDC is paid straight to your *Arc* Sendra wallet after the Naira transfer.";
        } elseif (\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $bridgeNote = "\n\nCrypto is paid straight to your *Aptos* Sendra wallet after the Naira transfer.";
        } else {
            $bridgeNote = $buySvc->isOneClickBridgeConfigured()
                ? "\n\nFunds are routed via our payout network to your Aptos wallet."
                : '';
        }

        $payDisplay = number_format($payNgn, 0, '.', ',');
        if ($cryptoTargetMode && $targetCrypto !== null) {
            $targetDisplay = rtrim(rtrim(number_format($targetCrypto, 6, '.', ''), '0'), '.');
            $body = "🛒 *Buy *{$targetDisplay} {$asset}*\n\n" .
                "Pay *≈ ₦{$payDisplay}* to receive *≈ {$receiveDisplay} {$asset}* (indicative).{$bridgeNote}\n\n" .
                'Continue to get a *virtual bank account*?';
        } else {
            $body = "🛒 *Buy with ₦{$payDisplay}*\n\n" .
                "You receive *≈ {$receiveDisplay} {$asset}* (indicative).{$bridgeNote}\n\n" .
                'Continue to get a *virtual bank account*?';
        }

        $this->whatsapp->sendButtonMessage($from, $body, [
            'buy_quote_yes' => '✅ Yes',
            'buy_quote_change' => '✏️ Different amount',
        ]);
    }

    private function shortSolana(string $addr): string
    {
        $addr = trim($addr);
        if (strlen($addr) <= 12) {
            return $addr;
        }

        return substr($addr, 0, 4) . '…' . substr($addr, -4);
    }

    private function quoteJobs(): ?SendraBuyQuoteJobService
    {
        return new SendraBuyQuoteJobService($this->db);
    }
}
