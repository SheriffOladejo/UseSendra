<?php

declare(strict_types=1);

namespace AptosV2\Services\Swap;

use App\Models\User;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\AptosServiceClient;

/**
 * Panora wallet swap after web PIN has claimed the pending row.
 */
final class ClaimedSwapExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private AptosServiceClient $aptosClient,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string, tx_hash?: string}
     */
    public function execute(string $from, array $user, array $pending): array
    {
        $meta = $this->pendingTx->decodeMeta($pending);
        $direction = (string) ($meta['direction'] ?? 'usdt_to_usdc');
        if ($direction !== 'usdc_to_usdt') {
            $direction = 'usdt_to_usdc';
        }
        $fromAsset = (string) ($meta['from_asset'] ?? $pending['currency'] ?? 'USDT');
        $toAsset = (string) ($meta['to_asset'] ?? ($direction === 'usdc_to_usdt' ? 'USDT' : 'USDC'));
        $amountStr = (string) ($pending['amount'] ?? '');
        $pendingId = (string) ($pending['id'] ?? '');

        $this->whatsapp->sendMessage(
            $from,
            "⏳ Swapping *{$amountStr} {$fromAsset}* → *{$toAsset}*…"
        );

        $result = $this->aptosClient->panoraSwap([
            'sender_address' => $user['aptos_address'],
            'encrypted_private_key' => $user['encrypted_private_key'],
            'from_token_amount' => $amountStr,
            'direction' => $direction,
        ]);
        $raw = (string) ($result['raw'] ?? json_encode($result));
        $curlErr = isset($result['curl_error']) ? (string) $result['curl_error'] : null;

        if ($curlErr || empty($result['success'])) {
            $err = (string) ($result['error'] ?? ($curlErr ?: 'Swap failed'));
            $this->pendingTx->updateStatus($pendingId, 'failed');
            $this->userModel->clearPendingTransaction($from);
            error_log("Panora wallet swap failed {$from}: {$err} raw={$raw}");
            $alreadyDone = $this->alreadySucceeded($pendingId);
            $hint = $alreadyDone
                ? "\n\nA swap for this request may have already completed (duplicate PIN). Check *balance*."
                : "\n\nYour *{$fromAsset}* balance should be unchanged.";
            $userMessage = \App\Utils\SwapUserMessageSanitizer::executionFailure($err, $fromAsset);
            $this->whatsapp->sendMessage(
                $from,
                "❌ Swap failed.\n\n{$userMessage}{$hint}"
            );

            return ['success' => false, 'error' => $err];
        }

        $panoraBlock = is_array($result['panora'] ?? null) ? $result['panora'] : [];
        $outAmt = null;
        foreach (['to_token_amount', 'min_to_token_amount'] as $k) {
            if (isset($panoraBlock[$k]) && is_numeric($panoraBlock[$k])) {
                $f = (float) $panoraBlock[$k];
                if ($f > 0) {
                    $outAmt = $f;
                    break;
                }
            }
        }
        if ($outAmt === null || $outAmt <= 0) {
            $outAmt = isset($meta['expected_out']) ? (float) $meta['expected_out'] : null;
        }
        $outFmt = $outAmt !== null && $outAmt > 0
            ? rtrim(rtrim(number_format($outAmt, 6, '.', ''), '0'), '.')
            : '—';

        $hash = (string) ($result['tx_hash'] ?? '');
        $explorer = $this->aptosClient->explorerUrlForTransaction($result, $hash);

        $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash !== '' ? $hash : null);
        $this->pendingTx->mergeMeta($pendingId, [
            'panora_swap_tx_hash' => $hash,
            'swap_completed_at' => gmdate('c'),
        ]);
        $this->txHistory->record([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $amountStr,
            'currency' => $fromAsset,
            'chain' => 'aptos',
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => 'panora_wallet_swap',
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);

        $swapBody = "✅ *Swap complete*\n\n" .
            "*{$amountStr} {$fromAsset}* → *≈ {$outFmt} {$toAsset}* in your wallet.\n\n" .
            ($hash !== '' ? "Tx: `{$hash}`\n\n" : '') .
            'Type *balance* to refresh.';
        if ($explorer !== '') {
            $this->whatsapp->sendCTAUrlButton($from, $swapBody, 'View in Explorer', $explorer);
        } else {
            $this->whatsapp->sendMessage($from, $swapBody);
        }

        return ['success' => true, 'tx_hash' => $hash];
    }

    private function alreadySucceeded(string $pendingId): bool
    {
        $row = $this->pendingTx->findById($pendingId);
        if ($row === null) {
            return false;
        }
        $meta = $this->pendingTx->decodeMeta($row);
        if (trim((string) ($meta['panora_swap_tx_hash'] ?? '')) !== '') {
            return true;
        }

        return (string) ($row['status'] ?? '') === 'confirmed' && !empty($row['tx_hash']);
    }
}
