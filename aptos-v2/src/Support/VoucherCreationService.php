<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;
use App\Services\WhatsAppService;

/**
 * Queue voucher escrow funding when sending to a phone number not yet on Sendra.
 */
final class VoucherCreationService
{
    public function __construct(
        private \PDO $db,
        private WhatsAppService $whatsapp,
        private User $userModel,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array{success: bool, pending_id?: string, reason?: string}
     */
    public function create(string $from, array $user, string $recipientPhone, string $amount, string $currency): array
    {
        if (empty($user['encrypted_private_key'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ *{$recipientPhone}* is not on Sendra yet.\n\n" .
                "Your account uses an external wallet, so vouchers are not supported.\n" .
                'Ask them to sign up and send directly.'
            );

            return ['success' => false, 'reason' => 'petra_no_voucher'];
        }

        if (!$this->isEscrowConfigured()) {
            $this->whatsapp->sendMessage(
                $from,
                "❌ Voucher escrow is not configured yet. Please contact support."
            );

            return ['success' => false, 'reason' => 'voucher_escrow_not_configured'];
        }

        if (!empty($user['pending_tx_id'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*."
            );

            return ['success' => false, 'reason' => 'pending_tx_exists'];
        }

        if (empty($user['pin_hash'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ You haven't set a PIN yet.\nSet one now to enable vouchers:"
            );
            $this->userModel->update($from, ['awaiting_pin_setup' => 1]);
            $this->sendSetupPrompt($from);

            return ['success' => false, 'reason' => 'no_pin'];
        }

        $pendingId = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + 300);
        $humanDescription = json_encode([
            'kind' => 'voucher_escrow_fund',
            'aptos_v2' => true,
            'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
            'recipient_phone' => $recipientPhone,
            'summary' => "Fund {$amount} {$currency} voucher escrow for {$recipientPhone}",
        ], JSON_UNESCAPED_UNICODE);

        try {
            $stmt = $this->db->prepare('
                INSERT INTO pending_transactions
                    (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
                VALUES
                    (:id, :user_phone, :tx_type, :amount, :currency, :recipient_address, :human_description, :expires_at, \'pending\', NOW())
            ');
            $stmt->execute([
                'id' => $pendingId,
                'user_phone' => $from,
                'tx_type' => 'voucher_escrow_fund',
                'amount' => $amount,
                'currency' => $currency,
                'recipient_address' => $this->escrowAddress(),
                'human_description' => $humanDescription,
                'expires_at' => $expiresAt,
            ]);
        } catch (\PDOException $e) {
            error_log('VoucherCreationService: ' . $e->getMessage());
            $this->whatsapp->sendMessage($from, '❌ Could not create voucher. Please try again.');

            return ['success' => false];
        }

        $this->userModel->setPendingTransaction($from, $pendingId);
        $url = V2PinConfirmUrls::buildConfirmUrl($pendingId, $from, time() + 300);

        $this->whatsapp->sendCTAUrlButton(
            $from,
            "🎟️ *Confirm voucher escrow*\n\n" .
            "Recipient: *{$recipientPhone}*\n" .
            "Amount: *{$amount} {$currency}*\n\n" .
            "Sendra will lock these funds in escrow. If the voucher is not claimed within *48 hours*, we will return it to your wallet automatically.\n\n" .
            "🔐 Tap *Enter PIN* below to fund the voucher securely.\n\n" .
            'Type *cancel* to abort.',
            'Enter PIN',
            $url
        );

        error_log("Voucher escrow queued: pending={$pendingId} phone={$recipientPhone} amount={$amount} {$currency} by={$from}");

        return ['success' => true, 'pending_id' => $pendingId];
    }

    private function escrowAddress(): string
    {
        return trim((string) ($_ENV['SENDRA_VOUCHER_ESCROW_ADDRESS'] ?? getenv('SENDRA_VOUCHER_ESCROW_ADDRESS') ?: ''));
    }

    private function isEscrowConfigured(): bool
    {
        return $this->escrowAddress() !== '';
    }

    private function sendSetupPrompt(string $phone): void
    {
        $url = \App\Services\PinConfirmTokenService::buildSetupUrl($phone, time() + 30 * 60);

        $this->whatsapp->sendCTAUrlButton(
            $phone,
            "🔐 *Set your transaction PIN*\n\n" .
            "Choose a *6-digit PIN* you'll use to confirm sends and vouchers.\n\n" .
            'Tap *Set PIN* below to open the secure page.',
            'Set PIN',
            $url
        );
    }
}
