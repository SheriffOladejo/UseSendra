<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Models\User;
use App\Services\SendraCentiivRampService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\StellarServiceClient;

/**
 * Native Stellar USDC → Centiiv pay-in after PIN (no 1Click).
 */
final class NativeStellarOfframpExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private CentiivOfframpOrderService $centiivOrders,
        private StellarServiceClient $stellarClient,
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
        $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];
        $sellCtx['centiiv_network'] = 'STELLAR';

        $amount = (float) ($pending['amount'] ?? 0);
        $stellarAddr = trim((string) ($user['stellar_address'] ?? ''));
        $stellarKey = (string) ($user['encrypted_stellar_private_key'] ?? '');
        if ($stellarAddr === '' || $stellarKey === '') {
            $this->fail($from, $pendingId, 'No Stellar wallet on file.');

            return ['success' => false, 'error' => 'missing_stellar_wallet'];
        }

        $agg = new SendraCentiivRampService();
        $ledgerAsset = \AptosV2\Support\StellarStable::display((string) ($pending['currency'] ?? 'USDC'));
        $created = $this->centiivOrders->createFromMeta(
            $from,
            $sellCtx,
            \AptosV2\Support\StellarStable::centiivAsset($ledgerAsset),
            $amount,
            'Sendra WhatsApp Stellar sell ' . $from,
            [
                'sendra_ramp_kind' => 'stellar_offramp',
                'sendra_user_stellar' => $stellarAddr,
            ],
            $agg
        );
        if (empty($created['success'])) {
            $msg = (string) ($created['error'] ?? 'Could not open payout order.');
            $this->fail($from, $pendingId, $msg);

            return ['success' => false, 'error' => $msg];
        }

        $payin = SendraCentiivRampService::extractStellarDepositAddress($created['data']);
        if ($payin === null || $payin === '') {
            $payin = $this->centiivOrders->resolvePayin($created['data'], $from);
        }
        if ($payin === null || $payin === '') {
            $this->fail($from, $pendingId, 'Payout partner did not return a Stellar deposit address.');

            return ['success' => false, 'error' => 'missing_payin'];
        }

        if ($pendingId !== '') {
            $this->pendingTx->mergeMeta($pendingId, [
                'centiiv_order_id' => $created['order_id'],
                'centiiv_payin_address' => $payin,
                'chain' => 'stellar',
            ]);
            $this->pendingTx->updateRecipient($pendingId, $payin);
        }

        $xfer = $this->stellarClient->executeGasless([
            'sender_address' => $stellarAddr,
            'encrypted_stellar_private_key' => $stellarKey,
            'recipient' => $payin,
            'amount' => (string) $pending['amount'],
            'currency' => \AptosV2\Support\StellarStable::serviceCurrency($ledgerAsset),
        ]);

        $this->userModel->clearPendingTransaction($from);

        if (empty($xfer['success'])) {
            $err = (string) ($xfer['error'] ?? 'Stellar transfer failed');
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'failed');
            }
            $this->whatsapp->sendMessage($from, "❌ Cash-out transfer failed: {$err}\n\nYour Stellar balance may be unchanged. Try again or contact support@usesendra.com");

            return ['success' => false, 'error' => $err];
        }

        $hash = (string) ($xfer['tx_hash'] ?? '');
        $explorer = (string) ($xfer['explorer_url'] ?? '');
        if ($pendingId !== '') {
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
        }
        $this->txHistory->record([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $pending['amount'],
            'currency' => $pending['currency'] ?? 'USDC',
            'chain' => 'stellar',
            'status' => 'confirmed',
            'aptos_address' => $stellarAddr,
            'counterparty' => $payin,
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);

        $body = "✅ *Stellar payout sent*\n\n"
            . "Debited *{$pending['amount']} {$ledgerAsset}* on Stellar.\n"
            . "You'll get a bank receipt when the transfer completes.";
        if ($explorer !== '') {
            $this->whatsapp->sendCTAUrlButton($from, $body, 'View on Stellar', $explorer);
        } else {
            $this->whatsapp->sendMessage($from, $body);
        }

        return ['success' => true];
    }

    private function fail(string $from, string $pendingId, string $msg): void
    {
        if ($pendingId !== '') {
            $this->pendingTx->updateStatus($pendingId, 'cancelled');
        }
        $this->userModel->clearPendingTransaction($from);
        $this->whatsapp->sendMessage($from, "❌ {$msg}\n\nYour wallet was not debited.");
    }
}
