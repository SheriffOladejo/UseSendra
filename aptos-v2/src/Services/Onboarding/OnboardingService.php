<?php

declare(strict_types=1);

namespace AptosV2\Services\Onboarding;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\SendraVoucherService;
use App\Services\WhatsAppService;
use AptosV2\Support\PinPromptService;
use AptosV2\Support\AptosServiceClient;
use AptosV2\Support\ArcWalletService;
use AptosV2\Support\StellarWalletService;

/**
 * Sendra-native account creation (ported from legacy MessageController).
 */
final class OnboardingService
{
    public function __construct(
        private \PDO $db,
        private AuthController $auth,
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PinPromptService $pinPrompts,
    ) {
    }

    /**
     * @return array{success: bool, reason?: string}
     */
    public function createAccount(string $phone, string $username, bool $isLogistics = false): array
    {
        if ($this->userModel->exists($phone)) {
            $this->whatsapp->sendMessage(
                $phone,
                "✅ You already have a Sendra account!\nType *menu* to see what you can do."
            );

            return ['success' => false, 'reason' => 'already_exists'];
        }

        $this->whatsapp->sendMessage(
            $phone,
            $isLogistics ? '⏳ Setting up your logistics account...' : '⏳ Setting up your Sendra account...'
        );

        $response = $this->callAptosServiceCreate($phone);
        if ($response === null || empty($response['success'])) {
            error_log('aptos-v2 onboarding: account creation failed for ' . $phone . ': ' . json_encode($response));
            $this->whatsapp->sendMessage($phone, '❌ Something went wrong creating your account. Please try again.');

            return ['success' => false, 'reason' => 'aptos_service_failed'];
        }

        $result = $this->auth->signup(
            $phone,
            $username,
            (string) $response['aptos_address'],
            (string) $response['encrypted_private_key'],
            'sendra',
            $isLogistics
        );

        if (empty($result['success'])) {
            error_log('aptos-v2 onboarding: DB signup failed for ' . $phone . ': ' . ($result['message'] ?? ''));
            $this->whatsapp->sendMessage($phone, '❌ Could not save your account. Please try again.');

            return ['success' => false, 'reason' => 'db_signup_failed'];
        }

        if ($isLogistics) {
            $this->whatsapp->sendMessage(
                $phone,
                "🚚 Your logistics account has been created!\n\n" .
                'You can now get delivery orders through our platform.'
            );

            return ['success' => true, 'account_type' => 'logistics'];
        }

        $this->userModel->update($phone, ['awaiting_pin_setup' => 1]);

        // Provision Stellar/Arc up front; deposit addresses are shown only when user types *receive*
        $freshForStellar = $this->userModel->findByPhone($phone);
        if (is_array($freshForStellar)) {
            $stellar = (new StellarWalletService($this->userModel))->provision($phone, $freshForStellar);
            if (empty($stellar['success']) || empty($stellar['stellar_address'])) {
                error_log('onboarding stellar provision failed for ' . $phone . ': ' . json_encode($stellar));
            }
            $freshForArc = $this->userModel->findByPhone($phone);
            if (is_array($freshForArc)) {
                $arc = (new ArcWalletService($this->userModel))->provision($phone, $freshForArc);
                if (empty($arc['success']) || empty($arc['arc_address'])) {
                    error_log('onboarding arc provision failed for ' . $phone . ': ' . json_encode($arc));
                }
            }
        }

        $this->whatsapp->sendMessage(
            $phone,
            "✅ *Your Sendra wallets are ready!*\n\n" .
            "Type *receive* to get your deposit address for:\n\n" .
            "• Aptos\n" .
            "• Stellar\n" .
            "• Arc"
        );
        $freshUser = $this->userModel->findByPhone($phone);
        if (is_array($freshUser)) {
            $claim = $this->voucherService()->claimEscrowedForUser($freshUser);
            if (!empty($claim['claimed_count'])) {
                $count = (int) $claim['claimed_count'];
                $plural = $count === 1 ? '' : 's';
                $this->whatsapp->sendMessage(
                    $phone,
                    "🎟️ We found *{$count}* escrowed voucher{$plural} for your phone number and released it to your wallet."
                );
            }
        }
        $this->pinPrompts->sendSetupPrompt($phone);

        return ['success' => true, 'account_type' => 'sendra'];
    }

    public function pendingEscrowVoucherCount(string $phone): int
    {
        return $this->voucherService()->pendingEscrowVoucherCount($phone);
    }

    /**
     * Users mid-onboarding from the old backup-key step — move them to PIN setup.
     *
     * @param array<string, mixed> $user
     */
    public function skipLegacyKeyConfirmToPinSetup(string $phone, array $user): void
    {
        $wamid = $user['key_message_wamid'] ?? null;
        if (is_string($wamid) && $wamid !== '') {
            $deleted = $this->whatsapp->deleteMessage($wamid);
            error_log("aptos-v2 legacy key message delete for {$phone} (wamid={$wamid}): " . ($deleted ? 'success' : 'failed'));
        }

        $this->userModel->update($phone, [
            'awaiting_key_confirm' => 0,
            'key_message_wamid' => null,
            'awaiting_pin_setup' => 1,
        ]);
        $this->pinPrompts->sendSetupPrompt($phone);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function callAptosServiceCreate(string $phone): ?array
    {
        $ch = curl_init('http://127.0.0.1:3001/accounts/create');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['phone_number' => $phone]),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function aptosServiceAuthHeaders(): array
    {
        $secret = trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));

        return [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ];
    }

    private function voucherService(): SendraVoucherService
    {
        return new SendraVoucherService(
            $this->db,
            $this->whatsapp,
            $this->userModel,
            new AptosServiceClient(),
        );
    }
}
