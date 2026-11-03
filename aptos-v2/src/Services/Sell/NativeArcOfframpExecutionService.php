<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Models\User;
use App\Services\SendraCentiivRampService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\ArcServiceClient;

/**
 * Native Arc USDC → Centiiv pay-in after PIN (no 1Click).
 */
final class NativeArcOfframpExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private CentiivOfframpOrderService $centiivOrders,
        private ArcServiceClient $arcClient,
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
        $sellCtx['centiiv_network'] = 'ARC';

        $amount = (float) ($pending['amount'] ?? 0);
        $arcAddr = trim((string) ($user['arc_address'] ?? ''));
        $arcKey = (string) ($user['encrypted_arc_private_key'] ?? '');
        if ($arcAddr === '' || $arcKey === '') {
            $this->fail($from, $pendingId, 'No Arc wallet on file.');

            return ['success' => false, 'error' => 'missing_arc_wallet'];
        }
        if (strtoupper((string) ($pending['currency'] ?? 'USDC')) !== 'USDC') {
            $this->fail($from, $pendingId, 'Arc cash-out is USDC only.');

            return ['success' => false, 'error' => 'arc_usdc_only'];
        }

        $sellCtx['refund_address'] = $arcAddr;
        $agg = new SendraCentiivRampService();
        $created = $this->centiivOrders->createFromMeta(
            $from,
            $sellCtx,
            'USDC',
            $amount,
            'Sendra WhatsApp Arc sell ' . $from,
            [
                'sendra_ramp_kind' => 'arc_offramp',
                'sendra_user_arc' => $arcAddr,
                'sendra_dest_chain' => 'arc',
            ],
            $agg
        );
        if (empty($created['success'])) {
            $msg = (string) ($created['error'] ?? 'Could not open payout order.');
            $this->fail($from, $pendingId, $msg);

            return ['success' => false, 'error' => $msg];
        }

        $payin = SendraCentiivRampService::extractEvmDepositAddress($created['data']);
        if ($payin === null || $payin === '') {
            $this->fail($from, $pendingId, 'Payout partner did not return an Arc deposit address.');

            return ['success' => false, 'error' => 'missing_payin'];
        }

        if ($pendingId !== '') {
            $this->pendingTx->mergeMeta($pendingId, [
                'centiiv_order_id' => $created['order_id'],
                'centiiv_payin_address' => $payin,
                'chain' => 'arc',
            ]);
            $this->pendingTx->updateRecipient($pendingId, $payin);
        }

        $xfer = $this->arcClient->execute([
            'sender_address' => $arcAddr,
            'encrypted_arc_private_key' => $arcKey,
            'recipient' => $payin,
            'amount' => (string) $pending['amount'],
            'currency' => 'USDC',
        ]);

        $this->userModel->clearPendingTransaction($from);

        if (empty($xfer['success'])) {
            $err = (string) ($xfer['error'] ?? 'Arc transfer failed');
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'failed');
            }
            $this->whatsapp->sendMessage($from, "❌ Cash-out transfer failed: " . $this->userTransferError($err) . "\n\nCheck your Arc balance before trying again, so the same USDC is not sent twice.");

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
            'currency' => 'USDC',
            'chain' => 'arc',
            'status' => 'confirmed',
            'aptos_address' => $arcAddr,
            'counterparty' => $payin,
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);

        $body = "✅ *Arc payout sent*\n\n"
            . "Debited *{$pending['amount']} USDC* on Arc.\n"
            . "You'll get a bank receipt when the transfer completes.";
        if ($explorer !== '') {
            $this->whatsapp->sendCTAUrlButton($from, $body, 'View on Arc', $explorer);
        } else {
            $this->whatsapp->sendMessage($from, $body);
        }

        return ['success' => true];
    }

    private function userTransferError(string $err): string
    {
        if (preg_match('/took too long|timed out|timeout|eth_sendRawTransaction|0x02f/i', $err) === 1 || strlen($err) > 180) {
            return 'The Arc network was slow to confirm. Your balance may already have been debited.';
        }

        return $err;
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
