<?php

declare(strict_types=1);

namespace AptosV2\Services\Send;

use App\Models\User;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\AptosServiceClient;
use AptosV2\Support\ArcServiceClient;
use AptosV2\Support\StellarServiceClient;
use AptosV2\Support\PendingWhatsAppNoticeService;
use AptosV2\Support\WhatsAppBotResolver;

/**
 * P2P transfer after web PIN has claimed the pending row.
 */
final class ClaimedTransferExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private AptosServiceClient $aptosClient,
        private ?StellarServiceClient $stellarClient = null,
        private ?ArcServiceClient $arcClient = null,
    ) {
        $this->stellarClient = $stellarClient ?? new StellarServiceClient();
        $this->arcClient = $arcClient ?? new ArcServiceClient();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    public function execute(string $from, array $user, array $pending): array
    {
        $this->whatsapp->sendMessage($from, '⏳ Processing your transaction...');
        $meta = $this->pendingTx->decodeMeta($pending);
        $chain = strtolower((string) ($meta['chain'] ?? 'aptos'));

        if ($chain === 'arc') {
            return $this->executeArc($from, $user, $pending);
        }

        if ($chain === 'stellar') {
            return $this->executeStellar($from, $user, $pending);
        }

        if ($this->aptosClient->usesGaslessMethod2()) {
            $result = $this->aptosClient->executeGasless([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'function' => '0x1::aptos_account::transfer_fungible_assets',
                'recipient' => $pending['recipient_address'],
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
            ]);
            $curlError = isset($result['curl_error']) ? (string) $result['curl_error'] : null;
            $rawResponse = isset($result['raw']) ? (string) $result['raw'] : json_encode($result);
            error_log('[send] aptos-service execute-gasless used. submit_mode=' . (string) ($result['submit_mode'] ?? '') . ' function=' . (string) ($result['function'] ?? ''));
        } else {
            $result = $this->aptosClient->executeTransfer([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'recipient' => $pending['recipient_address'],
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
            ]);
            $curlError = isset($result['curl_error']) ? (string) $result['curl_error'] : null;
            $rawResponse = isset($result['raw']) ? (string) $result['raw'] : json_encode($result);
            error_log('[send] aptos-service execute used. transfer_mode=' . (string) ($result['transfer_mode'] ?? '') . ' force_smoothsend=' . (string) ($result['force_smoothsend'] ?? ''));
        }

        $this->userModel->clearPendingTransaction($from);
        $pendingId = (string) ($pending['id'] ?? '');

        if (!empty($result['success'])) {
            $hash = (string) $result['tx_hash'];
            $explorerUrl = $this->aptosClient->explorerUrlForTransaction($result, $hash);
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
            $pendingMeta = $this->pendingTx->decodeMeta($pending);
            if (!empty($pendingMeta['aptos_v2'])) {
                $this->pendingTx->mergeMeta($pendingId, [
                    'transfer_tx_hash' => $hash,
                    'transfer_completed_at' => gmdate('c'),
                ]);
            }
            $this->txHistory->record([
                'user_phone' => $from,
                'direction' => 'out',
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
                'chain' => 'aptos',
                'status' => 'confirmed',
                'aptos_address' => $user['aptos_address'],
                'counterparty' => $pending['recipient_address'],
                'tx_hash' => $hash,
                'explorer_url' => $explorerUrl,
            ]);
            $recipientAddress = (string) $pending['recipient_address'];
            $recipientUser = $this->userModel->findByAptosAddress($recipientAddress);
            if ((!$recipientUser || empty($recipientUser['username'])) && method_exists($this->userModel, 'findByStellarAddress')) {
                $stellarHit = $this->userModel->findByStellarAddress($recipientAddress);
                if (is_array($stellarHit)) {
                    $recipientUser = $stellarHit;
                }
            }
            if ($recipientUser && !empty($recipientUser['username'])) {
                $toLabel = "*{$recipientUser['username']}*";
            } else {
                $shortAddr = strlen($recipientAddress) > 20
                    ? substr($recipientAddress, 0, 8) . '...' . substr($recipientAddress, -4)
                    : $recipientAddress;
                $toLabel = "`{$shortAddr}`";
            }

            $confirmBody = "✅ *Transaction Confirmed!*\n\n" .
                "Sent: *{$pending['amount']} {$pending['currency']}*\n" .
                "To: {$toLabel}";

            if ($explorerUrl !== '') {
                $this->whatsapp->sendCTAUrlButton($from, $confirmBody, 'View in Explorer', $explorerUrl);
            } else {
                $this->whatsapp->sendMessage($from, $confirmBody);
            }

            $this->notifyRecipientIncoming(
                $from,
                $user,
                is_array($recipientUser) ? $recipientUser : null,
                $pending,
                $hash,
                $explorerUrl,
                'aptos'
            );

            return ['success' => true];
        }

        $this->pendingTx->updateStatus($pendingId, 'failed');
        $errorMsg = $result['error'] ?? ($curlError ? 'Service unreachable' : 'Unknown error');
        error_log("Transaction failed for {$from}: {$errorMsg} | raw: {$rawResponse}");
        $this->whatsapp->sendMessage(
            $from,
            "❌ Transaction failed: {$errorMsg}\n\nPlease try again or contact support."
        );

        return ['success' => false, 'error' => (string) $errorMsg];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    private function executeStellar(string $from, array $user, array $pending): array
    {
        $client = $this->stellarClient ?? new StellarServiceClient();
        $result = $client->executeGasless([
            'sender_address' => $user['stellar_address'],
            'encrypted_stellar_private_key' => $user['encrypted_stellar_private_key'],
            'recipient' => $pending['recipient_address'],
            'amount' => $pending['amount'],
            'currency' => $pending['currency'],
        ]);
        $this->userModel->clearPendingTransaction($from);
        $pendingId = (string) ($pending['id'] ?? '');

        if (!empty($result['success'])) {
            $hash = (string) ($result['tx_hash'] ?? '');
            $explorerUrl = (string) ($result['explorer_url'] ?? '');
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
            $this->txHistory->record([
                'user_phone' => $from,
                'direction' => 'out',
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
                'chain' => 'stellar',
                'status' => 'confirmed',
                'aptos_address' => $user['stellar_address'],
                'counterparty' => $pending['recipient_address'],
                'tx_hash' => $hash,
                'explorer_url' => $explorerUrl,
            ]);
            $recipientAddress = (string) $pending['recipient_address'];
            $recipientUser = $this->userModel->findByStellarAddress($recipientAddress);
            $toLabel = ($recipientUser && !empty($recipientUser['username']))
                ? "*{$recipientUser['username']}*"
                : '`' . (strlen($recipientAddress) > 20 ? substr($recipientAddress, 0, 8) . '...' . substr($recipientAddress, -4) : $recipientAddress) . '`';
            $confirmBody = "✅ *Stellar transfer confirmed!*\n\nSent: *{$pending['amount']} {$pending['currency']}*\nTo: {$toLabel}";
            if ($explorerUrl !== '') {
                $this->whatsapp->sendCTAUrlButton($from, $confirmBody, 'View on Stellar', $explorerUrl);
            } else {
                $this->whatsapp->sendMessage($from, $confirmBody);
            }

            $this->notifyRecipientIncoming(
                $from,
                $user,
                is_array($recipientUser) ? $recipientUser : null,
                $pending,
                $hash,
                $explorerUrl,
                'stellar'
            );

            return ['success' => true];
        }

        $this->pendingTx->updateStatus($pendingId, 'failed');
        $errorMsg = (string) ($result['error'] ?? 'Unknown error');
        $this->whatsapp->sendMessage($from, "❌ Stellar transfer failed: {$errorMsg}\n\nPlease try again or contact support.");

        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    private function executeArc(string $from, array $user, array $pending): array
    {
        $client = $this->arcClient ?? new ArcServiceClient();
        $result = $client->execute([
            'sender_address' => $user['arc_address'],
            'encrypted_arc_private_key' => $user['encrypted_arc_private_key'],
            'recipient' => $pending['recipient_address'],
            'amount' => $pending['amount'],
            'currency' => 'USDC',
        ]);
        $this->userModel->clearPendingTransaction($from);
        $pendingId = (string) ($pending['id'] ?? '');

        if (!empty($result['success'])) {
            $hash = (string) ($result['tx_hash'] ?? '');
            $explorerUrl = (string) ($result['explorer_url'] ?? '');
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
            $this->txHistory->record([
                'user_phone' => $from,
                'direction' => 'out',
                'amount' => $pending['amount'],
                'currency' => 'USDC',
                'chain' => 'arc',
                'status' => 'confirmed',
                'aptos_address' => $user['arc_address'],
                'counterparty' => $pending['recipient_address'],
                'tx_hash' => $hash,
                'explorer_url' => $explorerUrl,
            ]);
            $recipientAddress = (string) $pending['recipient_address'];
            $recipientUser = $this->userModel->findByArcAddress($recipientAddress);
            $toLabel = ($recipientUser && !empty($recipientUser['username']))
                ? "*{$recipientUser['username']}*"
                : '`' . (strlen($recipientAddress) > 12 ? substr($recipientAddress, 0, 6) . '...' . substr($recipientAddress, -4) : $recipientAddress) . '`';
            $confirmBody = "✅ *Arc transfer confirmed!*\n\nSent: *{$pending['amount']} USDC*\nTo: {$toLabel}";
            if ($explorerUrl !== '') {
                $this->whatsapp->sendCTAUrlButton($from, $confirmBody, 'View on Arc', $explorerUrl);
            } else {
                $this->whatsapp->sendMessage($from, $confirmBody);
            }

            $this->notifyRecipientIncoming(
                $from,
                $user,
                is_array($recipientUser) ? $recipientUser : null,
                $pending,
                $hash,
                $explorerUrl,
                'arc'
            );

            return ['success' => true];
        }

        $this->pendingTx->updateStatus($pendingId, 'failed');
        $errorMsg = (string) ($result['error'] ?? 'Unknown error');
        $this->whatsapp->sendMessage($from, "❌ Arc transfer failed: {$errorMsg}\n\nPlease try again or contact support.");

        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Credit ping + inbound history when the recipient is a known Sendra user.
     *
     * @param array<string, mixed> $senderUser
     * @param array<string, mixed>|null $recipientUser
     * @param array<string, mixed> $pending
     */
    private function notifyRecipientIncoming(
        string $senderPhone,
        array $senderUser,
        ?array $recipientUser,
        array $pending,
        string $txHash,
        string $explorerUrl,
        string $chain
    ): void {
        if ($recipientUser === null) {
            return;
        }

        $toPhone = trim((string) ($recipientUser['phone_number'] ?? ''));
        if ($toPhone === '' || $toPhone === $senderPhone) {
            return;
        }

        $amount = (string) ($pending['amount'] ?? '');
        $currency = (string) ($pending['currency'] ?? '');
        $recipientWallet = match ($chain) {
            'stellar' => (string) ($recipientUser['stellar_address'] ?? $pending['recipient_address'] ?? ''),
            'arc' => (string) ($recipientUser['arc_address'] ?? $pending['recipient_address'] ?? ''),
            default => (string) ($recipientUser['aptos_address'] ?? $pending['recipient_address'] ?? ''),
        };
        $senderWallet = match ($chain) {
            'stellar' => (string) ($senderUser['stellar_address'] ?? ''),
            'arc' => (string) ($senderUser['arc_address'] ?? ''),
            default => (string) ($senderUser['aptos_address'] ?? ''),
        };

        $this->txHistory->record([
            'user_phone' => $toPhone,
            'direction' => 'in',
            'amount' => $amount,
            'currency' => $currency,
            'chain' => $chain,
            'status' => 'confirmed',
            'aptos_address' => $recipientWallet,
            'counterparty' => $senderWallet !== '' ? $senderWallet : $senderPhone,
            'tx_hash' => $txHash,
            'explorer_url' => $explorerUrl,
        ]);

        $fromLabel = !empty($senderUser['username'])
            ? '*' . trim((string) $senderUser['username']) . '*'
            : 'a Sendra user';

        $body = "💰 *You received {$amount} {$currency}*\n\nFrom: {$fromLabel}";
        $notices = new PendingWhatsAppNoticeService($this->txHistory->pdo());
        $noticeId = $notices->queue($toPhone, $body);

        try {
            $pendingMeta = $this->pendingTx->decodeMeta($pending);
            $phoneId = WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
                $this->txHistory->pdo(),
                $toPhone,
                is_array($pendingMeta) ? $pendingMeta : null,
                $this->whatsapp->getPhoneNumberId()
            );
            $token = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
            $wa = ($phoneId !== '' && $phoneId !== $this->whatsapp->getPhoneNumberId() && $token !== '')
                ? new WhatsAppService($token, $phoneId)
                : $this->whatsapp;

            $cta = match ($chain) {
                'stellar' => 'View on Stellar',
                'arc' => 'View on Arc',
                default => 'View in Explorer',
            };
            $result = $explorerUrl !== ''
                ? $wa->sendCTAUrlButton($toPhone, $body, $cta, $explorerUrl)
                : $wa->sendMessage($toPhone, $body);
            $notices->recordAttempt($noticeId, is_array($result) ? $result : null);
        } catch (\Throwable $e) {
            $notices->recordAttempt($noticeId, null);
            error_log('ClaimedTransferExecutionService recipient notify failed: ' . $e->getMessage());
        }
    }
}
