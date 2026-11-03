<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Models\User;
use App\Services\SendraDayaAptosService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\AptosServiceClient;

/**
 * After PIN: create Daya crypto funding account, gasless-send Aptos USDC/USDT.
 */
final class DayaOfframpExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private AptosServiceClient $aptosClient,
        private SendraDayaAptosService $daya = new SendraDayaAptosService(),
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    public function execute(string $from, array $user, array $pending): array
    {
        $pendingId = (string) ($pending['id'] ?? '');
        $meta = $this->pendingTx->decodeMeta($pending);
        if (empty($meta['pin_claimed'])) {
            return ['success' => false, 'error' => 'PIN not claimed for this transaction.'];
        }

        $amount = (string) ($pending['amount'] ?? $meta['crypto_amount'] ?? '');
        $asset = strtoupper((string) ($pending['currency'] ?? $meta['asset'] ?? 'USDC'));
        $meta['asset'] = $asset;
        $meta['crypto_amount'] = (float) $amount;

        $created = $this->daya->createSellFundingAccount($from, $user, $meta);
        if (empty($created['success'])) {
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'cancelled');
            }
            $this->userModel->clearPendingTransaction($from);
            $msg = (string) ($created['user_message'] ?? '❌ Payout setup failed. Try again.');
            $this->whatsapp->sendMessage($from, $msg);

            return ['success' => false, 'error' => $msg];
        }

        $deposit = (string) ($created['deposit_address'] ?? '');
        $patch = is_array($created['meta_patch'] ?? null) ? $created['meta_patch'] : [];
        if ($pendingId !== '') {
            $this->pendingTx->mergeMeta($pendingId, $patch);
            $this->pendingTx->updateRecipient($pendingId, $deposit);
        }

        $result = $this->aptosClient->executeGasless([
            'sender_address' => $user['aptos_address'],
            'encrypted_private_key' => $user['encrypted_private_key'],
            'recipient' => $deposit,
            'amount' => $amount,
            'currency' => $asset,
        ], 150);

        $this->userModel->clearPendingTransaction($from);

        if (empty($result['success'])) {
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'failed');
            }
            $err = (string) ($result['error'] ?? 'Wallet send failed');
            $this->whatsapp->sendMessage(
                $from,
                "❌ Could not send *{$amount} {$asset}* from your wallet.\n\n{$err}\n\nYour bank was not paid. Type *sell* to try again."
            );

            return ['success' => false, 'error' => $err];
        }

        $hash = (string) ($result['tx_hash'] ?? $result['hash'] ?? '');
        if ($pendingId !== '') {
            $this->pendingTx->updateStatus($pendingId, 'pending', $hash !== '' ? $hash : null);
            $this->pendingTx->mergeMeta($pendingId, [
                'aptos_tx_hash' => $hash,
                'daya_deposit_address' => $deposit,
            ]);
        }

        $explorer = (string) ($result['explorer_url'] ?? '');
        $this->txHistory->record([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $amount,
            'currency' => $asset,
            'chain' => 'aptos',
            'status' => 'confirmed',
            'aptos_address' => (string) ($user['aptos_address'] ?? ''),
            'counterparty' => $deposit,
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);

        $this->whatsapp->sendMessage(
            $from,
            "✅ *{$amount} {$asset}* left your Aptos wallet.\n\n" .
            "Sendra is converting to Naira. You'll get a receipt when the bank transfer completes."
        );

        return ['success' => true];
    }
}
