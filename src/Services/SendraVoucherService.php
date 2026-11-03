<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use AptosV2\Support\AptosServiceClient;

/**
 * Escrow-backed voucher lifecycle: fund escrow, release to recipient, refund on expiry.
 */
final class SendraVoucherService
{
    public function __construct(
        private \PDO $db,
        private WhatsAppService $whatsapp,
        private User $userModel,
        private AptosServiceClient $aptosClient,
    ) {
    }

    public function isEscrowConfigured(): bool
    {
        return $this->escrowAddress() !== '' && $this->escrowEncryptedPrivateKey() !== '';
    }

    public function pendingEscrowVoucherCount(string $phone): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vouchers
             WHERE recipient_phone = ?
               AND status = 'escrowed'
               AND expires_at > NOW()"
        );
        $stmt->execute([$phone]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Convert a PIN-confirmed pending transaction into an escrow-funded voucher.
     *
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $pending
     * @return array{success: bool, voucher_id?: string, tx_hash?: string, explorer_url?: string, error?: string}
     */
    public function finalizeEscrowFunding(array $sender, array $pending): array
    {
        if (!$this->isEscrowConfigured()) {
            return ['success' => false, 'error' => 'Voucher escrow wallet is not configured.'];
        }

        $meta = $this->decodeMeta($pending);
        $recipientPhone = trim((string) ($meta['recipient_phone'] ?? ''));
        if ($recipientPhone === '') {
            return ['success' => false, 'error' => 'Voucher recipient phone is missing.'];
        }

        $transfer = $this->executeTransfer(
            (string) ($sender['aptos_address'] ?? ''),
            (string) ($sender['encrypted_private_key'] ?? ''),
            $this->escrowAddress(),
            (string) ($pending['amount'] ?? ''),
            (string) ($pending['currency'] ?? '')
        );
        if (empty($transfer['success'])) {
            return ['success' => false, 'error' => (string) ($transfer['error'] ?? 'Escrow funding failed.')];
        }

        $hash = (string) ($transfer['tx_hash'] ?? '');
        $explorerUrl = $this->aptosClient->explorerUrlForTransaction($transfer, $hash);
        $token = bin2hex(random_bytes(32));
        $voucherId = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + (48 * 3600));
        $currency = strtoupper((string) ($pending['currency'] ?? ''));
        $amount = (string) ($pending['amount'] ?? '');
        $createdBy = (string) ($pending['user_phone'] ?? '');

        $stmt = $this->db->prepare("
            INSERT INTO vouchers
                (id, token, created_by, creator_address, amount, currency, recipient_phone,
                 funding_pending_id, escrow_address, status, escrow_funding_tx_hash,
                 funded_at, expires_at, created_at)
            VALUES
                (:id, :token, :created_by, :creator_address, :amount, :currency, :recipient_phone,
                 :funding_pending_id, :escrow_address, 'escrowed', :escrow_funding_tx_hash,
                 NOW(), :expires_at, NOW())
        ");
        $stmt->execute([
            'id' => $voucherId,
            'token' => $token,
            'created_by' => $createdBy,
            'creator_address' => (string) ($sender['aptos_address'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'recipient_phone' => $recipientPhone,
            'funding_pending_id' => (string) ($pending['id'] ?? ''),
            'escrow_address' => $this->escrowAddress(),
            'escrow_funding_tx_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        $this->recordTransaction([
            'user_phone' => $createdBy,
            'direction' => 'out',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'confirmed',
            'aptos_address' => (string) ($sender['aptos_address'] ?? ''),
            'counterparty' => $this->escrowAddress(),
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $voucherUrl = $this->buildVoucherUrl($token, $recipientPhone);
        $this->whatsapp->sendMessage(
            $createdBy,
            "🎟️ *Voucher funded and locked in escrow*\n\n" .
            "Recipient: *{$recipientPhone}*\n" .
            "Amount: *{$amount} {$currency}*\n\n" .
            "👇 *Forward this link to them:*\n{$voucherUrl}\n\n" .
            "If it is not claimed within *48 hours*, Sendra will return it to your wallet automatically."
        );

        return [
            'success' => true,
            'voucher_id' => $voucherId,
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ];
    }

    /**
     * Claim one specific escrowed voucher or all escrowed vouchers for a user.
     *
     * @param array<string, mixed> $recipientUser
     * @return array{success: bool, claimed_count: int, failed_count: int, message?: string}
     */
    public function claimEscrowedForUser(array $recipientUser, ?string $token = null): array
    {
        $phone = trim((string) ($recipientUser['phone_number'] ?? ''));
        $recipientAddress = trim((string) ($recipientUser['aptos_address'] ?? ''));
        if ($phone === '' || $recipientAddress === '') {
            return ['success' => false, 'claimed_count' => 0, 'failed_count' => 0, 'message' => 'Recipient account is incomplete.'];
        }

        $candidateRows = $token !== null && $token !== ''
            ? $this->findCandidateByToken($token)
            : $this->findCandidatesByPhone($phone);

        if ($candidateRows === []) {
            $message = $token !== null && $token !== '' ? 'Voucher not found or expired.' : 'No escrowed vouchers found.';

            return ['success' => false, 'claimed_count' => 0, 'failed_count' => 0, 'message' => $message];
        }

        $claimed = 0;
        $failed = 0;

        foreach ($candidateRows as $candidate) {
            $locked = $this->lockVoucher((string) $candidate['id'], 'escrowed', 'claiming');
            if ($locked === null) {
                continue;
            }

            if ((string) ($locked['recipient_phone'] ?? '') !== $phone) {
                $this->restoreVoucherStatus((string) $locked['id'], 'escrowed');
                if ($token !== null && $token !== '') {
                    return [
                        'success' => false,
                        'claimed_count' => $claimed,
                        'failed_count' => $failed + 1,
                        'message' => 'Phone number does not match voucher.',
                    ];
                }
                $failed++;
                continue;
            }

            if (strtotime((string) ($locked['expires_at'] ?? '')) <= time()) {
                $this->restoreVoucherStatus((string) $locked['id'], 'escrowed');
                if ($token !== null && $token !== '') {
                    return [
                        'success' => false,
                        'claimed_count' => $claimed,
                        'failed_count' => $failed + 1,
                        'message' => 'Voucher not found or expired.',
                    ];
                }
                $failed++;
                continue;
            }

            $release = $this->releaseLockedVoucher($locked, $recipientUser);
            if (!empty($release['success'])) {
                $claimed++;
            } else {
                $failed++;
                $this->restoreVoucherStatus((string) $locked['id'], 'escrowed');
                if ($token !== null && $token !== '') {
                    return [
                        'success' => false,
                        'claimed_count' => $claimed,
                        'failed_count' => $failed,
                        'message' => (string) ($release['error'] ?? 'Voucher claim failed.'),
                    ];
                }
            }
        }

        if ($claimed === 0) {
            return [
                'success' => false,
                'claimed_count' => 0,
                'failed_count' => $failed,
                'message' => $token !== null && $token !== '' ? 'Voucher claim failed.' : 'No vouchers were released.',
            ];
        }

        return ['success' => true, 'claimed_count' => $claimed, 'failed_count' => $failed];
    }

    /**
     * Refund escrowed vouchers that have expired.
     *
     * @return array{success: bool, scanned: int, refunded: int, failed: int}
     */
    public function refundExpired(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        $stmt = $this->db->prepare("
            SELECT id
            FROM vouchers
            WHERE status = 'escrowed'
              AND expires_at <= NOW()
            ORDER BY expires_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $refunded = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $locked = $this->lockVoucher((string) $row['id'], 'escrowed', 'refunding');
            if ($locked === null) {
                continue;
            }

            $senderPhone = trim((string) ($locked['created_by'] ?? ''));
            $sender = $senderPhone !== '' ? $this->userModel->findByPhone($senderPhone) : false;
            $senderAddress = trim((string) ($sender['aptos_address'] ?? ''));
            if ($sender === false || $senderAddress === '') {
                $failed++;
                $this->restoreVoucherStatus((string) $locked['id'], 'escrowed');
                error_log('voucher refund skipped: sender account missing for voucher ' . (string) $locked['id']);
                continue;
            }

            $refund = $this->executeTransfer(
                $this->escrowAddress(),
                $this->escrowEncryptedPrivateKey(),
                $senderAddress,
                (string) ($locked['amount'] ?? ''),
                (string) ($locked['currency'] ?? '')
            );
            if (empty($refund['success'])) {
                $failed++;
                $this->restoreVoucherStatus((string) $locked['id'], 'escrowed');
                error_log('voucher refund failed: ' . (string) ($refund['error'] ?? 'unknown error'));
                continue;
            }

            $hash = (string) ($refund['tx_hash'] ?? '');
            $explorerUrl = $this->aptosClient->explorerUrlForTransaction($refund, $hash);
            $update = $this->db->prepare("
                UPDATE vouchers
                SET status = 'refunded',
                    refunded_at = NOW(),
                    refund_tx_hash = :refund_tx_hash
                WHERE id = :id
            ");
            $update->execute([
                'refund_tx_hash' => $hash,
                'id' => (string) $locked['id'],
            ]);

            $this->recordTransaction([
                'user_phone' => $senderPhone,
                'direction' => 'in',
                'amount' => (string) ($locked['amount'] ?? ''),
                'currency' => strtoupper((string) ($locked['currency'] ?? '')),
                'status' => 'confirmed',
                'aptos_address' => $senderAddress,
                'counterparty' => $this->escrowAddress(),
                'tx_hash' => $hash,
                'explorer_url' => $explorerUrl,
            ]);

            $this->whatsapp->sendMessage(
                $senderPhone,
                "↩️ *Voucher refunded*\n\n" .
                "Your voucher for *" . (string) ($locked['amount'] ?? '') . ' ' . strtoupper((string) ($locked['currency'] ?? '')) . "* " .
                'to *' . (string) ($locked['recipient_phone'] ?? '') . "* was not claimed within 48 hours, so the funds have been returned to your wallet."
            );

            $refunded++;
        }

        return [
            'success' => true,
            'scanned' => count($rows),
            'refunded' => $refunded,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<string, mixed> $voucher
     * @param array<string, mixed> $recipientUser
     * @return array{success: bool, tx_hash?: string, explorer_url?: string, error?: string}
     */
    private function releaseLockedVoucher(array $voucher, array $recipientUser): array
    {
        $senderPhone = trim((string) ($voucher['created_by'] ?? ''));
        $sender = $senderPhone !== '' ? $this->userModel->findByPhone($senderPhone) : false;
        $senderAddress = trim((string) ($sender['aptos_address'] ?? ''));
        $recipientAddress = trim((string) ($recipientUser['aptos_address'] ?? ''));
        if ($recipientAddress === '') {
            return ['success' => false, 'error' => 'Recipient wallet address missing.'];
        }

        $transfer = $this->executeTransfer(
            $this->escrowAddress(),
            $this->escrowEncryptedPrivateKey(),
            $recipientAddress,
            (string) ($voucher['amount'] ?? ''),
            (string) ($voucher['currency'] ?? '')
        );
        if (empty($transfer['success'])) {
            return ['success' => false, 'error' => (string) ($transfer['error'] ?? 'Voucher payout failed.')];
        }

        $hash = (string) ($transfer['tx_hash'] ?? '');
        $explorerUrl = $this->aptosClient->explorerUrlForTransaction($transfer, $hash);
        $update = $this->db->prepare("
            UPDATE vouchers
            SET status = 'claimed',
                claimed_by = :claimed_by,
                claimed_at = NOW(),
                recipient_address = :recipient_address,
                claim_tx_hash = :claim_tx_hash,
                tx_hash = :tx_hash
            WHERE id = :id
        ");
        $update->execute([
            'claimed_by' => (string) ($recipientUser['phone_number'] ?? ''),
            'recipient_address' => $recipientAddress,
            'claim_tx_hash' => $hash,
            'tx_hash' => $hash,
            'id' => (string) ($voucher['id'] ?? ''),
        ]);

        $this->recordTransaction([
            'user_phone' => (string) ($recipientUser['phone_number'] ?? ''),
            'direction' => 'in',
            'amount' => (string) ($voucher['amount'] ?? ''),
            'currency' => strtoupper((string) ($voucher['currency'] ?? '')),
            'status' => 'confirmed',
            'aptos_address' => $recipientAddress,
            'counterparty' => $senderAddress !== '' ? $senderAddress : $this->escrowAddress(),
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $senderName = trim((string) ($sender['username'] ?? $senderPhone));
        $recipientName = trim((string) ($recipientUser['username'] ?? $recipientUser['phone_number'] ?? ''));
        if ($senderPhone !== '') {
            $this->whatsapp->sendMessage(
                $senderPhone,
                "✅ *Voucher claimed!*\n\n" .
                "*{$recipientName}* received your *" . (string) ($voucher['amount'] ?? '') . ' ' . strtoupper((string) ($voucher['currency'] ?? '')) . "* voucher."
            );
        }

        $this->whatsapp->sendMessage(
            (string) ($recipientUser['phone_number'] ?? ''),
            "🎉 *You received a voucher!*\n\n" .
            "*{$senderName}* sent you *" . (string) ($voucher['amount'] ?? '') . ' ' . strtoupper((string) ($voucher['currency'] ?? '')) . "* " .
            "and it has been credited to your wallet.\n\n" .
            "💰 Type *balance* to check your wallet.\n" .
            "📋 Type *history* to see your transactions."
        );

        return ['success' => true, 'tx_hash' => $hash, 'explorer_url' => $explorerUrl];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(array $pending): array
    {
        $meta = json_decode((string) ($pending['human_description'] ?? ''), true);

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCandidatesByPhone(string $phone): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM vouchers
            WHERE recipient_phone = ?
              AND status = 'escrowed'
              AND expires_at > NOW()
            ORDER BY created_at ASC
        ");
        $stmt->execute([$phone]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCandidateByToken(string $token): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM vouchers
            WHERE token = ?
              AND status = 'escrowed'
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [$row] : [];
    }

    /**
     * Atomically move a voucher from one state to another.
     *
     * @return array<string, mixed>|null
     */
    private function lockVoucher(string $voucherId, string $expectedStatus, string $nextStatus): ?array
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM vouchers WHERE id = ? FOR UPDATE');
            $stmt->execute([$voucherId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) ($row['status'] ?? '') !== $expectedStatus) {
                $this->db->rollBack();

                return null;
            }

            $u = $this->db->prepare('UPDATE vouchers SET status = ? WHERE id = ?');
            $u->execute([$nextStatus, $voucherId]);
            $this->db->commit();

            $row['status'] = $nextStatus;

            return $row;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('SendraVoucherService::lockVoucher: ' . $e->getMessage());

            return null;
        }
    }

    private function restoreVoucherStatus(string $voucherId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE vouchers SET status = ? WHERE id = ?');
        $stmt->execute([$status, $voucherId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeTransfer(
        string $senderAddress,
        string $encryptedPrivateKey,
        string $recipientAddress,
        string $amount,
        string $currency
    ): array {
        if ($senderAddress === '' || $encryptedPrivateKey === '' || $recipientAddress === '' || $amount === '' || $currency === '') {
            return ['success' => false, 'error' => 'Transfer payload is incomplete.'];
        }

        return $this->aptosClient->executeGasless([
            'sender_address' => $senderAddress,
            'encrypted_private_key' => $encryptedPrivateKey,
            'recipient' => $recipientAddress,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ], 150);
    }

    /**
     * INSERT IGNORE mirrors the rest of Sendra transaction history.
     *
     * @param array<string, mixed> $data
     */
    private function recordTransaction(array $data): void
    {
        try {
            $explorer = (string) ($data['explorer_url'] ?? '');
            $chain = \AptosV2\Support\ChainNetwork::resolve(
                isset($data['chain']) ? (string) $data['chain'] : 'aptos',
                $explorer
            );
            $stmt = $this->db->prepare("
                INSERT INTO transaction_history
                    (id, user_phone, direction, amount, currency, chain, status,
                     aptos_address, counterparty, tx_hash, explorer_url, created_at)
                VALUES
                    (:id, :user_phone, :direction, :amount, :currency, :chain, :status,
                     :aptos_address, :counterparty, :tx_hash, :explorer_url, NOW())
                ON DUPLICATE KEY UPDATE id = id
            ");
            $stmt->execute([
                'id' => bin2hex(random_bytes(16)),
                'user_phone' => (string) ($data['user_phone'] ?? ''),
                'direction' => (string) ($data['direction'] ?? 'out'),
                'amount' => $data['amount'] ?? 0,
                'currency' => (string) ($data['currency'] ?? 'USDC'),
                'chain' => $chain,
                'status' => (string) ($data['status'] ?? 'confirmed'),
                'aptos_address' => (string) ($data['aptos_address'] ?? ''),
                'counterparty' => (string) ($data['counterparty'] ?? ''),
                'tx_hash' => (string) ($data['tx_hash'] ?? ''),
                'explorer_url' => $explorer,
            ]);
        } catch (\PDOException $e) {
            error_log('SendraVoucherService::recordTransaction: ' . $e->getMessage());
        }
    }

    private function buildVoucherUrl(string $token, string $recipientPhone): string
    {
        $message = sprintf(
            'Hi Sendra, I want to claim my voucher for %s.',
            $recipientPhone
        );

        return PinConfirmTokenService::whatsappChatUrl() . '?text=' . rawurlencode($message);
    }

    private function escrowAddress(): string
    {
        return trim((string) ($_ENV['SENDRA_VOUCHER_ESCROW_ADDRESS'] ?? getenv('SENDRA_VOUCHER_ESCROW_ADDRESS') ?: ''));
    }

    private function escrowEncryptedPrivateKey(): string
    {
        return trim((string) ($_ENV['SENDRA_VOUCHER_ESCROW_ENCRYPTED_PRIVATE_KEY'] ?? getenv('SENDRA_VOUCHER_ESCROW_ENCRYPTED_PRIVATE_KEY') ?: ''));
    }
}
