<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\SendraCentiivRampService;
use App\Services\SendraVoucherService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Services\Sell\NativeArcOfframpExecutionService;
use AptosV2\Services\Sell\NativeStellarOfframpExecutionService;
use AptosV2\Services\Send\ClaimedTransferExecutionService;
use AptosV2\Services\Swap\ClaimedSwapExecutionService;

/**
 * Web PIN confirmation: verify PIN, claim pending row, execute or spawn off-ramp worker.
 */
final class PostPinExecutionService
{
    private const V2_OFFRAMP_TX_TYPE = 'offramp_oneclick_centiiv';

    public function __construct(
        private User $userModel,
        private AuthController $auth,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private OneClickOfframpExecutionService $oneClickOfframp,
        private ClaimedTransferExecutionService $transfers,
        private ClaimedSwapExecutionService $swaps,
        private SendraVoucherService $vouchers,
        private NativeStellarOfframpExecutionService $stellarOfframp,
        private NativeArcOfframpExecutionService $arcOfframp,
        private DayaOfframpExecutionService $dayaOfframp,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingForWeb(string $pendingId): ?array
    {
        return $this->pendingTx->findById($pendingId);
    }

    /**
     * @param array<string, mixed> $pending
     * @return array{title: string, subtitle: string, rows: list<array{label: string, value: string}>}
     */
    public function describeForWeb(array $pending): array
    {
        return PinConfirmSummary::describe($pending);
    }

    public function confirmWithPin(
        string $phone,
        string $pin,
        string $pendingId,
        bool $notifyWhatsAppOnWrongPin = false,
        bool $deferPostSendPoll = true,
        bool $backgroundExecution = true,
    ): PostPinResult {
        error_log("PIN_CONFIRM: phone={$phone} pending_id={$pendingId}");

        $userResult = $this->auth->getUser($phone);
        $user = $userResult['user'] ?? null;
        if (!$user) {
            return PostPinResult::fail('Account not found.');
        }

        if (!$this->userModel->verifyPin($phone, $pin)) {
            error_log('PIN_CONFIRM: pin_valid=NO');
            if ($notifyWhatsAppOnWrongPin) {
                $this->whatsapp->sendMessage(
                    $phone,
                    "❌ Wrong PIN. Please try again, or type *cancel* to abort."
                );
            }

            return PostPinResult::fail('Wrong PIN. Please try again.');
        }

        error_log('PIN_CONFIRM: pin_valid=YES');

        $pendingId = trim($pendingId);
        if ($pendingId === '') {
            $this->whatsapp->sendMessage($phone, '⚠️ No pending transaction found. Please start again.');

            return PostPinResult::fail('No pending transaction found.');
        }

        $pending = $this->pendingTx->findActivePending($pendingId);
        if ($pending === null) {
            $existing = $this->pendingTx->findById($pendingId);
            if ($existing !== null && $this->pendingTx->isAlreadyClaimedOrDone($existing)) {
                error_log(
                    'PIN_CONFIRM: duplicate skipped pending_id=' . $pendingId
                    . ' status=' . ($existing['status'] ?? '')
                );
                $this->whatsapp->sendMessage(
                    $phone,
                    "⏳ That transaction is already processing or finished. Check *balance* or your explorer link."
                );
                $this->userModel->clearPendingTransaction($phone);

                return PostPinResult::fail(
                    'That transaction is already processing or finished.',
                    PostPinResult::REASON_DUPLICATE_PIN
                );
            }

            $this->whatsapp->sendMessage($phone, '⚠️ No pending transaction found. Please start again.');
            $this->userModel->clearPendingTransaction($phone);

            return PostPinResult::fail('No pending transaction found.');
        }

        if (($pending['user_phone'] ?? '') !== $phone) {
            return PostPinResult::fail('Transaction unavailable.');
        }

        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->whatsapp->sendMessage(
                $phone,
                '⏰ Transaction expired (5 min limit). Please start again.'
            );
            $this->userModel->clearPendingTransaction($phone);

            return PostPinResult::fail('Transaction expired.');
        }

        if (!$this->pendingTx->claimForExecution($pendingId)) {
            error_log("PIN_CONFIRM: claim lost race pending_id={$pendingId}");
            $this->whatsapp->sendMessage(
                $phone,
                "⏳ That transaction is already processing. You'll get a result message shortly."
            );
            $this->userModel->clearPendingTransaction($phone);

            return PostPinResult::fail(
                'That transaction is already processing.',
                PostPinResult::REASON_DUPLICATE_PIN
            );
        }

        $this->userModel->clearPendingTransaction($phone);

        if (empty($user['encrypted_private_key'])) {
            $accountType = $user['account_type'] ?? 'unknown';
            if ($accountType === 'petra') {
                $this->whatsapp->sendMessage(
                    $phone,
                    "⚠️ Your account uses Petra wallet.\n\n" .
                    'To send funds, use the send command and approve through your Petra wallet on our website.'
                );
            } else {
                $this->whatsapp->sendMessage(
                    $phone,
                    "⚠️ Your account was created before the current wallet system.\n\n" .
                    'Please type *hi* and create a new Sendra account to enable in-chat transactions.'
                );
            }

            return PostPinResult::fail(
                'Account cannot sign transactions.',
                PostPinResult::REASON_NO_PRIVATE_KEY
            );
        }

        $pending = $this->pendingTx->findById($pendingId) ?? $pending;
        $txType = (string) ($pending['tx_type'] ?? '');

        if ($this->isOfframpTxType($txType)) {
            if ($deferPostSendPoll) {
                $this->sendOfframpPinAcceptedMessage($phone, $pending);
            }
            if ($backgroundExecution) {
                OfframpAfterPinWorker::spawn($pendingId, $phone);

                return PostPinResult::ok();
            }

            $result = $this->runClaimedOfframp($phone, $pendingId);

            return !empty($result['success'])
                ? PostPinResult::ok()
                : PostPinResult::fail((string) ($result['error'] ?? 'Confirmation failed.'));
        }

        $result = $this->runClaimedNonOfframp($phone, $user, $pending);

        return !empty($result['success'])
            ? PostPinResult::ok()
            : PostPinResult::fail((string) ($result['error'] ?? 'Confirmation failed.'));
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function runClaimedOfframp(string $phone, string $pendingId): array
    {
        $userResult = $this->auth->getUser($phone);
        $user = $userResult['user'] ?? null;
        if (!$user) {
            return ['success' => false, 'error' => 'Account not found.'];
        }

        $pending = $this->pendingTx->findById($pendingId);
        if ($pending === null) {
            return ['success' => false, 'error' => 'Pending transaction not found.'];
        }
        if (($pending['user_phone'] ?? '') !== $phone) {
            return ['success' => false, 'error' => 'Phone mismatch.'];
        }

        $txType = (string) ($pending['tx_type'] ?? '');
        if ($txType === self::V2_OFFRAMP_TX_TYPE || $txType === 'offramp_oneclick_centiiv') {
            return $this->oneClickOfframp->execute($phone, $user, $pending, true);
        }
        if ($txType === 'offramp_arc_centiiv') {
            return $this->arcOfframp->execute($phone, $user, $pending);
        }
        if ($txType === 'offramp_stellar_centiiv') {
            return $this->stellarOfframp->execute($phone, $user, $pending);
        }
        if ($txType === 'offramp_daya_aptos') {
            return $this->dayaOfframp->execute($phone, $user, $pending);
        }

        error_log("PIN_CONFIRM: unsupported offramp tx_type={$txType} pending_id={$pendingId}");
        $this->pendingTx->updateStatus($pendingId, 'cancelled');
        $this->userModel->clearPendingTransaction($phone);
        $this->whatsapp->sendMessage(
            $phone,
            "❌ This cash-out used an old path that's no longer available.\n\nYour wallet was not debited. Type *sell* to start again."
        );

        return ['success' => false, 'error' => 'Unsupported off-ramp type. Start again with sell.'];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    public function runClaimedNonOfframp(string $phone, array $user, array $pending): array
    {
        $meta = $this->pendingTx->decodeMeta($pending);
        if (empty($meta['pin_claimed'])) {
            return ['success' => false, 'error' => 'PIN not claimed for this transaction.'];
        }

        $txType = (string) ($pending['tx_type'] ?? '');
        if ($txType === 'panora_wallet_swap') {
            return $this->swaps->execute($phone, $user, $pending);
        }
        if ($txType === 'transfer') {
            return $this->transfers->execute($phone, $user, $pending);
        }
        if ($txType === 'voucher_escrow_fund') {
            return $this->executeVoucherEscrow($phone, $user, $pending);
        }

        return ['success' => false, 'error' => 'Unsupported transaction type.'];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    private function executeVoucherEscrow(string $from, array $user, array $pending): array
    {
        $this->whatsapp->sendMessage($from, '⏳ Funding your voucher escrow...');
        $result = $this->vouchers->finalizeEscrowFunding($user, $pending);
        $this->userModel->clearPendingTransaction($from);
        $pendingId = (string) ($pending['id'] ?? '');

        if (!empty($result['success'])) {
            $hash = (string) ($result['tx_hash'] ?? '');
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
            $this->pendingTx->mergeMeta($pendingId, [
                'voucher_id' => $result['voucher_id'] ?? null,
                'transfer_tx_hash' => $hash,
                'transfer_completed_at' => gmdate('c'),
            ]);

            return ['success' => true];
        }

        $this->pendingTx->updateStatus($pendingId, 'failed');
        $this->whatsapp->sendMessage(
            $from,
            '❌ Voucher creation failed: ' . (string) ($result['error'] ?? 'Unknown error') . "\n\nPlease try again."
        );

        return ['success' => false, 'error' => (string) ($result['error'] ?? 'Voucher creation failed.')];
    }

    private function isOfframpTxType(string $txType): bool
    {
        return in_array($txType, [
            self::V2_OFFRAMP_TX_TYPE,
            'offramp_panora_oneclick_centiiv',
            'offramp_centiiv',
            'offramp_stellar_centiiv',
            'offramp_arc_centiiv',
            'offramp_daya_aptos',
        ], true);
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function sendOfframpPinAcceptedMessage(string $phone, array $pending): void
    {
        $meta = $this->pendingTx->decodeMeta($pending);
        $ngn = (string) ($meta['receive_ngn_display'] ?? '—');
        $name = (string) ($meta['account_name'] ?? '');
        $acct = (string) ($meta['account_number'] ?? '');
        $amount = (string) ($pending['amount'] ?? '');
        $currency = (string) ($pending['currency'] ?? '');
        $bank = (string) ($meta['bank_name'] ?? '');

        $fiat = strtoupper((string) ($meta['fiat'] ?? 'NGN'));
        $sym = SendraCentiivRampService::currencySymbol($fiat);

        $lines = ['✅ *PIN confirmed*', ''];
        if ($ngn !== '' && $ngn !== '—') {
            $lines[] = "Your *≈ {$sym}{$ngn}* payout is processing.";
        } else {
            $lines[] = 'Your payout is processing.';
        }
        if ($name !== '' && $acct !== '') {
            $bankBit = $bank !== '' ? " · *{$bank}*" : '';
            $lines[] = "To: *{$name}* · `{$acct}`{$bankBit}";
        }
        if ($amount !== '' && $currency !== '') {
            $lines[] = "Wallet debit: *{$amount} {$currency}*";
        }
        $lines[] = '';
        $lines[] = "You'll get a receipt on WhatsApp when the bank transfer completes.";
        $lines[] = '';
        $lines[] = 'Need help?';
        $lines[] = 'support@usesendra.com';

        $this->whatsapp->sendMessage($phone, implode("\n", $lines));
    }
}
