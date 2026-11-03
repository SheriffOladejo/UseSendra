<?php

declare(strict_types=1);

namespace AptosV2\Services\Voucher;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\SendraVoucherService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\AptosServiceClient;
use AptosV2\Support\PinPromptService;

/**
 * Web voucher claim (`/voucher/claim`) — escrow release or legacy pending transfer.
 */
final class VoucherClaimService
{
    public function __construct(
        private \PDO $db,
        private AuthController $auth,
        private User $userModel,
        private WhatsAppService $whatsapp,
        private SendraVoucherService $vouchers,
        private TransactionHistoryRepository $txHistory,
        private AptosServiceClient $aptosClient,
        private PinPromptService $pinPrompts,
    ) {
    }

    /**
     * @return array{success: bool, message?: string, tx_hash?: string}
     */
    public function execute(string $token, string $recipientPhone, ?string $username = null): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vouchers
            WHERE token = ?
              AND status IN ('pending', 'escrowed')
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $voucher = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($voucher)) {
            return ['success' => false, 'message' => 'Voucher not found or expired'];
        }

        if ($voucher['recipient_phone'] !== $recipientPhone) {
            return ['success' => false, 'message' => 'Phone number does not match voucher'];
        }

        if (($voucher['status'] ?? '') === 'escrowed') {
            return $this->claimEscrowed($voucher, $recipientPhone, $username, $token);
        }

        return $this->claimLegacyPending($voucher, $recipientPhone, $username);
    }

    /**
     * @param array<string, mixed> $voucher
     * @return array{success: bool, message?: string}
     */
    private function claimEscrowed(array $voucher, string $recipientPhone, ?string $username, string $token): array
    {
        $ensured = $this->ensureRecipientAccount($recipientPhone, $username);
        if ($ensured['error'] !== null) {
            return ['success' => false, 'message' => $ensured['error']];
        }
        $recipientUser = $ensured['user'];
        if ($recipientUser === null) {
            return ['success' => false, 'message' => 'Recipient account error'];
        }

        $claim = $this->vouchers->claimEscrowedForUser($recipientUser, $token);
        if (empty($claim['success'])) {
            return ['success' => false, 'message' => (string) ($claim['message'] ?? 'Voucher claim failed.')];
        }

        if ($ensured['is_new']) {
            $this->whatsapp->sendMessage(
                $recipientPhone,
                "✅ *Your Sendra wallet is ready!*\n\n" .
                "📍 *Address:*\n`" . (string) ($recipientUser['aptos_address'] ?? '') . '`'
            );
            $this->pinPrompts->sendSetupPrompt($recipientPhone);
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $voucher
     * @return array{success: bool, message?: string, tx_hash?: string}
     */
    private function claimLegacyPending(array $voucher, string $recipientPhone, ?string $username): array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE phone_number = ? LIMIT 1');
        $stmt->execute([$voucher['created_by']]);
        $sender = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($sender) || empty($sender['encrypted_private_key'])) {
            return ['success' => false, 'message' => 'Sender account error'];
        }

        $this->db->prepare('
            UPDATE vouchers SET status = \'claimed\', claimed_by = ?, claimed_at = NOW() WHERE id = ?
        ')->execute([$recipientPhone, $voucher['id']]);

        $ensured = $this->ensureRecipientAccount($recipientPhone, $username);
        if ($ensured['error'] !== null) {
            $this->db->prepare('UPDATE vouchers SET status = \'pending\', claimed_by = NULL, claimed_at = NULL WHERE id = ?')
                ->execute([$voucher['id']]);

            return ['success' => false, 'message' => $ensured['error']];
        }
        $recipientUser = $ensured['user'];
        $recipientAddress = (string) ($recipientUser['aptos_address'] ?? '');
        if ($recipientAddress === '') {
            $this->db->prepare('UPDATE vouchers SET status = \'pending\', claimed_by = NULL, claimed_at = NULL WHERE id = ?')
                ->execute([$voucher['id']]);

            return ['success' => false, 'message' => 'Recipient account error'];
        }

        $result = $this->aptosClient->executeTransfer([
            'sender_address' => $sender['aptos_address'],
            'encrypted_private_key' => $sender['encrypted_private_key'],
            'recipient' => $recipientAddress,
            'amount' => $voucher['amount'],
            'currency' => $voucher['currency'],
        ]);

        if (empty($result['success'])) {
            $this->db->prepare('
                UPDATE vouchers SET status = \'pending\', claimed_by = NULL, claimed_at = NULL WHERE id = ?
            ')->execute([$voucher['id']]);
            $error = (string) ($result['error'] ?? 'Transfer failed');
            error_log("Voucher transfer failed: {$voucher['id']} error={$error}");

            return ['success' => false, 'message' => $error];
        }

        $hash = (string) $result['tx_hash'];
        $explorerUrl = $this->aptosClient->explorerUrlForTransaction($result, $hash);
        $this->db->prepare('UPDATE vouchers SET tx_hash = ? WHERE id = ?')->execute([$hash, $voucher['id']]);

        $this->txHistory->record([
            'user_phone' => $voucher['created_by'],
            'direction' => 'out',
            'amount' => $voucher['amount'],
            'currency' => $voucher['currency'],
            'chain' => 'aptos',
            'status' => 'confirmed',
            'aptos_address' => $sender['aptos_address'],
            'counterparty' => $recipientAddress,
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);
        $this->txHistory->record([
            'user_phone' => $recipientPhone,
            'direction' => 'in',
            'amount' => $voucher['amount'],
            'currency' => $voucher['currency'],
            'chain' => 'aptos',
            'status' => 'confirmed',
            'aptos_address' => $recipientAddress,
            'counterparty' => $sender['aptos_address'],
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $senderName = $sender['username'] ?? $voucher['created_by'];
        $recipientName = $recipientUser['username'] ?? $recipientPhone;

        $this->whatsapp->sendMessage(
            $voucher['created_by'],
            "✅ *Voucher Claimed!*\n\n" .
            "*{$recipientName}* signed up and received your " .
            "*{$voucher['amount']} {$voucher['currency']}*.\n\n" .
            "🔗 {$explorerUrl}"
        );
        $this->whatsapp->sendMessage(
            $recipientPhone,
            "🎉 *You received a voucher!*\n\n" .
            "*{$senderName}* sent you *{$voucher['amount']} {$voucher['currency']}* " .
            "and it has been credited to your wallet.\n\n" .
            "💰 Type *balance* to check your wallet.\n" .
            '📋 Type *history* to see your transactions.'
        );

        if ($ensured['is_new']) {
            $this->whatsapp->sendMessage(
                $recipientPhone,
                "✅ *Your Sendra wallet is ready!*\n\n" .
                "📍 *Address:*\n`{$recipientAddress}`"
            );
            $this->pinPrompts->sendSetupPrompt($recipientPhone);
        }

        return ['success' => true, 'tx_hash' => $hash];
    }

    /**
     * @return array{user: ?array<string, mixed>, is_new: bool, error: ?string}
     */
    private function ensureRecipientAccount(string $recipientPhone, ?string $username): array
    {
        $existing = $this->userModel->findByPhone($recipientPhone);
        if (is_array($existing)) {
            return ['user' => $existing, 'is_new' => false, 'error' => null];
        }

        $aptosResponse = $this->aptosClient->createAccount($recipientPhone);
        if (empty($aptosResponse['success'])) {
            error_log('Voucher claim: aptos-service failed for ' . $recipientPhone . ': ' . json_encode($aptosResponse));

            return ['user' => null, 'is_new' => false, 'error' => 'Could not create wallet. Please try again.'];
        }

        $defaultUsername = $username ?? ('user_' . substr($recipientPhone, -4));
        $this->auth->signup(
            $recipientPhone,
            $defaultUsername,
            (string) $aptosResponse['aptos_address'],
            (string) $aptosResponse['encrypted_private_key'],
            'sendra'
        );
        $this->userModel->update($recipientPhone, ['awaiting_pin_setup' => 1]);
        $recipientUser = $this->userModel->findByPhone($recipientPhone);

        return ['user' => is_array($recipientUser) ? $recipientUser : null, 'is_new' => true, 'error' => null];
    }
}
