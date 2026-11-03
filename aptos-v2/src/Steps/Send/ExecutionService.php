<?php

declare(strict_types=1);

namespace AptosV2\Steps\Send;

use App\Models\User;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\UserRepository;
use AptosV2\Support\PhoneNormalizer;
use AptosV2\Support\PinPromptService;
use AptosV2\Support\ArcAddress;
use AptosV2\Support\ArcWalletService;
use AptosV2\Support\StellarAddress;
use AptosV2\Support\V2PinConfirmUrls;
use AptosV2\Support\VoucherCreationService;
use AptosV2\Support\WalletChoice;

final class ExecutionService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private User $userModel,
        private UserRepository $users,
        private PendingTransactionRepository $pendingTx,
        private VoucherCreationService $vouchers,
        private PinPromptService $pinPrompts,
    ) {
    }

    /**
     * @param array{amount: string, currency: string, recipient: string, chain?: string} $parsed
     */
    public function initiate(StepContext $ctx, array $parsed): StepResult
    {
        $from = $ctx->from;
        $user = $ctx->user;

        if (!empty($user['pending_tx_id'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*."
            );

            return StepResult::complete(['action' => 'send_pending_exists']);
        }

        $amount = $parsed['amount'];
        $currency = $parsed['currency'];
        $recipient = trim((string) $parsed['recipient']);
        $chain = strtolower((string) ($parsed['chain'] ?? WalletChoice::APTOS));
        $recipientName = null;

        if ($chain === WalletChoice::ARC) {
            if (empty($user['arc_address']) || empty($user['encrypted_arc_private_key'])) {
                /** @var ArcWalletService $arc */
                $arc = $ctx->services->get('arc_wallet');
                $arc->promptCreate($this->whatsapp, $from);

                return StepResult::complete(['action' => 'send_arc_missing']);
            }
            if (strtoupper($currency) !== 'USDC') {
                $this->whatsapp->sendMessage($from, 'Arc sends are *USDC* only.');

                return StepResult::complete(['action' => 'send_arc_usdc_only']);
            }
            if (ArcAddress::isValid($recipient)) {
                $recipient = ArcAddress::normalize($recipient);
            }
        }

        if ($chain === WalletChoice::STELLAR) {
            if (empty($user['stellar_address']) || empty($user['encrypted_stellar_private_key'])) {
                /** @var \AptosV2\Support\StellarWalletService $stellar */
                $stellar = $ctx->services->get('stellar_wallet');
                $stellar->promptCreate($this->whatsapp, $from);

                return StepResult::complete(['action' => 'send_stellar_missing']);
            }
            if (strtoupper($currency) === 'USDT0') {
                $currency = 'USDT';
            }
            // Prefer external G/C address even if WhatsApp wrapped it with hyphens/spaces.
            if (StellarAddress::isValid($recipient)) {
                $recipient = StellarAddress::normalize($recipient);
            }
        }

        $lookupPhone = PhoneNormalizer::normalize($recipient);
        $stellarLike = (bool) preg_match('/^[GC]/i', preg_replace('/[\s\-]+/', '', $recipient) ?? $recipient);
        $looksLikePhone = !str_starts_with(strtolower($recipient), '0x')
            && !StellarAddress::isValid($recipient)
            && !$stellarLike
            && strlen($lookupPhone) >= 7 && strlen($lookupPhone) <= 15;

        if ($looksLikePhone) {
            $recipientUser = $this->users->findByPhone($lookupPhone);

            if ($chain === WalletChoice::ARC) {
                if ($recipientUser === null || empty($recipientUser['arc_address'])) {
                    $this->whatsapp->sendMessage(
                        $from,
                        "They don't have a Sendra Arc wallet yet.\nAsk them to open Sendra and tap *Create Arc*, or send to their Arc address."
                    );

                    return StepResult::complete(['action' => 'send_recipient_no_arc']);
                }
                $recipientName = is_string($recipientUser['username'] ?? null)
                    ? $recipientUser['username']
                    : $lookupPhone;
                $recipient = (string) $recipientUser['arc_address'];
            } elseif ($chain === WalletChoice::STELLAR) {
                if ($recipientUser === null || empty($recipientUser['stellar_address'])) {
                    $this->whatsapp->sendMessage(
                        $from,
                        "They don't have a Sendra Stellar wallet yet.\nAsk them to open Sendra and tap *Create Stellar*, or send to their Stellar address."
                    );

                    return StepResult::complete(['action' => 'send_recipient_no_stellar']);
                }
                $recipientName = is_string($recipientUser['username'] ?? null)
                    ? $recipientUser['username']
                    : $lookupPhone;
                $recipient = (string) $recipientUser['stellar_address'];
            } else {
                if ($recipientUser === null || empty($recipientUser['aptos_address'])) {
                    $result = $this->vouchers->create($from, $user, $lookupPhone, $amount, $currency);

                    return StepResult::complete(['action' => 'send_voucher'] + $result);
                }

                $recipientName = is_string($recipientUser['username'] ?? null)
                    ? $recipientUser['username']
                    : $lookupPhone;
                $recipient = (string) $recipientUser['aptos_address'];
                error_log("Send v2: resolved phone {$lookupPhone} to {$recipient} ({$recipientName})");
            }
        } elseif ($chain === WalletChoice::STELLAR) {
            if (!StellarAddress::isValid($recipient)) {
                $this->whatsapp->sendMessage(
                    $from,
                    "⚠️ Invalid Stellar recipient.\n\nSend to a *phone number* or a Stellar address (`G…` or `C…`).\n\nTip: paste the address on one line with no spaces or dashes."
                );

                return StepResult::complete(['action' => 'send_invalid_recipient']);
            }
            $recipient = StellarAddress::normalize($recipient);
        } elseif ($chain === WalletChoice::ARC) {
            if (!ArcAddress::isValid($recipient)) {
                $this->whatsapp->sendMessage(
                    $from,
                    "⚠️ Invalid Arc recipient.\n\nSend to a *phone number* or an Arc address (`0x` and 40 hex characters)."
                );

                return StepResult::complete(['action' => 'send_invalid_recipient']);
            }
            $recipient = ArcAddress::normalize($recipient);
        } elseif (!preg_match('/^0x[a-fA-F0-9]{60,64}$/', $recipient)) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ Invalid recipient.\n\n" .
                'Send to a *phone number* (e.g. `2348012345678`) or an *Aptos address* (e.g. `0x1234...`).'
            );

            return StepResult::complete(['action' => 'send_invalid_recipient']);
        }

        if ($chain === WalletChoice::APTOS && empty($user['encrypted_private_key'])) {
            return $this->petraRedirect($from, $amount, $currency, $recipient, $recipientName);
        }

        return $this->queueForPin($from, $user, $amount, $currency, $recipient, $recipientName, $chain);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function queueForPin(
        string $from,
        array $user,
        string $amount,
        string $currency,
        string $recipient,
        ?string $recipientName,
        string $chain = 'aptos'
    ): StepResult {
        if (empty($user['pin_hash'])) {
            $this->whatsapp->sendMessage(
                $from,
                "⚠️ You haven't set a PIN yet.\n" .
                'Set one now to enable transactions:'
            );
            $this->userModel->update($from, ['awaiting_pin_setup' => 1]);
            $this->pinPrompts->sendSetupPrompt($from);

            return StepResult::complete(['action' => 'send_no_pin']);
        }

        $shortAddr = substr($recipient, 0, 8) . '...' . substr($recipient, -4);
        $displayRecipient = $recipientName !== null ? "*{$recipientName}*" : "`{$shortAddr}`";
        $descRecipient = $recipientName !== null ? "{$recipientName} ({$shortAddr})" : $shortAddr;
        $chainLabel = WalletChoice::label($chain);

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'transfer',
            'amount' => $amount,
            'currency' => $currency,
            'recipient_address' => $recipient,
            'human_description' => json_encode([
                'kind' => 'transfer',
                'aptos_v2' => true,
                'chain' => $chain,
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'recipient_name' => $recipientName,
                'display_recipient' => $displayRecipient,
                'summary' => "Send {$amount} {$currency} ({$chainLabel}) to {$descRecipient}",
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 5 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        $url = V2PinConfirmUrls::buildConfirmUrl(
            $pendingId,
            $from,
            time() + 300
        );

        $this->whatsapp->sendCTAUrlButton(
            $from,
            "📤 *Confirm Transaction*\n\n" .
            "Send: *{$amount} {$currency}* on {$chainLabel}\n" .
            "To: {$displayRecipient}\n\n" .
            "🔐 Tap *Enter PIN* below to confirm securely.\n\n" .
            'Type *cancel* to abort.',
            'Enter PIN',
            $url
        );

        return StepResult::complete([
            'action' => 'send_pin_queued',
            'pending_id' => $pendingId,
        ]);
    }

    private function petraRedirect(
        string $from,
        string $amount,
        string $currency,
        string $recipient,
        ?string $recipientName
    ): StepResult {
        $url = 'https://usesendra.com/client?action=send'
            . '&amount=' . rawurlencode($amount)
            . '&currency=' . rawurlencode($currency)
            . '&recipient=' . rawurlencode($recipient)
            . '&sender=' . rawurlencode($from);

        $toLabel = $recipientName !== null ? "*{$recipientName}*" : "`{$recipient}`";
        $summary = "📤 *New Transaction*\n\n" .
            "Send: *{$amount} {$currency}*\n" .
            "To: {$toLabel}\n\n" .
            'Click below to approve with your Petra wallet.';

        $this->whatsapp->sendCTAUrlButton($from, $summary, 'Approve Transaction', $url);

        return StepResult::complete(['action' => 'send_petra', 'account_type' => 'petra']);
    }
}
