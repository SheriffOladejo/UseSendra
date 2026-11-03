<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;
use App\Services\PinConfirmTokenService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Services\Sell\PinConfirmSummary;

/**
 * Web-only PIN prompts (setup, confirm, change). Rejects in-chat 6-digit PIN entry.
 */
final class PinPromptService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private User $userModel,
        private PendingTransactionRepository $pendingTx,
    ) {
    }

    public function sendSetupPrompt(string $phone): void
    {
        $url = PinConfirmTokenService::buildSetupUrl($phone, time() + 30 * 60);

        $this->whatsapp->sendCTAUrlButton(
            $phone,
            "🔐 *Set your transaction PIN*\n\n" .
            "Choose a *6-digit PIN* you'll use to confirm sends and payouts.\n\n" .
            'Tap *Set PIN* below to open the secure page.',
            'Set PIN',
            $url
        );
    }

    public function sendChangePrompt(string $phone): void
    {
        $url = PinConfirmTokenService::buildChangeUrl($phone, time() + 15 * 60);

        $this->whatsapp->sendCTAUrlButton(
            $phone,
            "🔐 *Change your transaction PIN*\n\n" .
            'Enter your *current PIN*, then choose a *new PIN* on the secure page.',
            'Change PIN',
            $url
        );
    }

    /**
     * @return array{success: false, reason: string}
     */
    public function rejectInChatSetup(string $phone): array
    {
        $this->whatsapp->sendMessage(
            $phone,
            "🔒 For your security, don't type your PIN in WhatsApp.\n\n" .
            'Use the *Set PIN* button from our last message instead.'
        );
        $this->sendSetupPrompt($phone);

        return ['success' => false, 'reason' => 'pin_setup_use_web'];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{success: false, reason: string}
     */
    public function rejectInChatConfirm(string $phone, array $user): array
    {
        $this->whatsapp->sendMessage(
            $phone,
            "🔒 For your security, don't type your PIN in WhatsApp.\n\n" .
            'Use the *Enter PIN* button from our last message instead.'
        );
        $this->resendConfirmLink($phone, $user);

        return ['success' => false, 'reason' => 'pin_use_web'];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function resendConfirmLink(string $phone, array $user): void
    {
        $pendingId = trim((string) ($user['pending_tx_id'] ?? ''));
        if ($pendingId === '') {
            return;
        }

        $pending = $this->pendingTx->findById($pendingId);
        if ($pending === null) {
            $this->whatsapp->sendMessage($phone, '⚠️ No pending transaction found. Please start again.');
            $this->userModel->clearPendingTransaction($phone);

            return;
        }

        $summary = PinConfirmSummary::describe($pending);
        $lines = array_map(
            static fn (array $row): string => "*{$row['label']}:* {$row['value']}",
            $summary['rows']
        );

        $url = V2PinConfirmUrls::buildConfirmUrl(
            $pendingId,
            $phone,
            strtotime((string) ($pending['expires_at'] ?? '')) ?: (time() + 300)
        );

        $this->whatsapp->sendCTAUrlButton(
            $phone,
            "⏳ *{$summary['title']}*\n\n" . implode("\n", $lines) . "\n\n" .
            "🔐 Tap *Enter PIN* below to confirm securely.\n\n" .
            'Type *cancel* to abort.',
            'Enter PIN',
            $url
        );
    }
}
