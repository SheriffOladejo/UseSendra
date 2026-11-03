<?php

declare(strict_types=1);

namespace AptosV2\Steps\Swap;

use App\Models\User;
use AptosV2\Support\V2PinConfirmUrls;
use App\Services\SendraPanoraService;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Support\AptosBalanceService;

final class ExecutionService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private User $userModel,
        private SendraPanoraService $panora,
        private AptosBalanceService $balances,
        private PendingTransactionRepository $pendingTx,
    ) {
    }

    public function executeAfterConfirm(StepContext $ctx): StepResult
    {
        $from = $ctx->from;
        $user = $ctx->user;

        if (!empty($user['pending_tx_id'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*."
            );

            return StepResult::complete(['action' => 'swap_pending_exists']);
        }

        $amount = (float) $ctx->stateValue('amount', 0);
        $fromAsset = (string) $ctx->stateValue('from_asset', '');
        $toAsset = (string) $ctx->stateValue('to_asset', '');
        $direction = (string) $ctx->stateValue('direction', '');
        $amountStr = (string) $ctx->stateValue('amount_str', '');
        if ($amountStr === '') {
            $amountStr = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        }

        if ($fromAsset === '' || $toAsset === '' || $direction === '' || $amount <= 0) {
            $this->whatsapp->sendMessage($from, '❌ Swap session expired. Start again with e.g. `swap 1 USDT to USDC`.');

            return StepResult::complete(['action' => 'swap_state_invalid']);
        }

        $wallet = (string) ($user['aptos_address'] ?? '');
        if ($wallet === '') {
            $this->whatsapp->sendMessage($from, '❌ No wallet on file. Type *hi* to get started.');

            return StepResult::complete(['action' => 'swap_no_wallet']);
        }

        $balanceRow = $this->balances->fetch($wallet);
        if ($balanceRow === null) {
            $this->whatsapp->sendMessage($from, '❌ Could not read your balance. Try again.');

            return StepResult::complete(['action' => 'swap_balance_fetch_failed']);
        }

        $avail = (float) str_replace(',', '', $balanceRow[$fromAsset] ?? '0');
        if ($avail + 1e-9 < $amount) {
            $this->whatsapp->sendMessage(
                $from,
                '❌ Not enough *' . $fromAsset . "*.\nYou have *" . number_format($avail, 6, '.', ',') . "* {$fromAsset}."
            );

            return StepResult::complete(['action' => 'swap_insufficient_balance']);
        }

        $this->whatsapp->sendMessage($from, '⏳ Getting a *' . $fromAsset . ' → ' . $toAsset . '* quote…');

        $quote = $this->panora->requestSwapQuoteForDirection($direction, $amountStr, $wallet);
        if (!$quote['success'] || !is_array($quote['data'] ?? null)) {
            $err = (string) ($quote['error'] ?? 'Quote failed');
            error_log("Panora wallet swap quote failed {$from}: {$err}");
            $this->whatsapp->sendMessage(
                $from,
                '❌ ' . \App\Utils\SwapUserMessageSanitizer::quoteFailure($err)
            );

            return StepResult::complete(['action' => 'swap_quote_failed']);
        }

        $parsedQuote = SendraPanoraService::parseSwapResponse($quote['data']);
        $estOut = $parsedQuote['to_token_amount'];
        $minOut = $parsedQuote['min_to_token_amount'] ?? $estOut;
        if ($estOut === null || $estOut <= 0) {
            $this->whatsapp->sendMessage($from, '❌ Swap quote incomplete. Try again.');

            return StepResult::complete(['action' => 'swap_quote_incomplete']);
        }

        $estFmt = rtrim(rtrim(number_format($estOut, 6, '.', ''), '0'), '.');
        $minFmt = ($minOut !== null && $minOut > 0)
            ? rtrim(rtrim(number_format($minOut, 6, '.', ''), '0'), '.')
            : $estFmt;

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'panora_wallet_swap',
            'amount' => $amountStr,
            'currency' => $fromAsset,
            'recipient_address' => $wallet,
            'human_description' => json_encode([
                'kind' => 'panora_wallet_swap',
                'aptos_v2' => true,
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'direction' => $direction,
                'from_asset' => $fromAsset,
                'to_asset' => $toAsset,
                'expected_out' => $estOut,
                'min_out' => $minOut,
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 5 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        $this->sendPinConfirmPrompt(
            $from,
            $pendingId,
            "🔐 *Confirm swap*\n\n" .
            "Swap *{$amountStr} {$fromAsset}* → *≈ {$estFmt} {$toAsset}*\n" .
            "(minimum *{$minFmt} {$toAsset}* after slippage)\n\n" .
            'Funds stay in your Sendra wallet.'
        );

        return StepResult::complete([
            'action' => 'swap_pin_queued',
            'pending_id' => $pendingId,
        ]);
    }

    private function sendPinConfirmPrompt(string $from, string $pendingId, string $bodyText): void
    {
        $row = $this->pendingTx->findById($pendingId);
        $expiresAt = $row ? strtotime((string) $row['expires_at']) : false;
        if ($expiresAt === false || $expiresAt <= 0) {
            $expiresAt = time() + 300;
        }

        $url = V2PinConfirmUrls::buildConfirmUrl($pendingId, $from, (int) $expiresAt);

        $this->whatsapp->sendCTAUrlButton(
            $from,
            $bodyText . "\n\n" .
                "🔐 Tap *Enter PIN* below to confirm securely.\n\n" .
                'Type *cancel* to abort.',
            'Enter PIN',
            $url
        );
    }
}
