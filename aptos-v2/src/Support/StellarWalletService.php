<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;
use App\Services\WhatsAppService;

final class StellarWalletService
{
    public function __construct(
        private User $users,
        private StellarServiceClient $client = new StellarServiceClient(),
    ) {
    }

    public static function hasAddress(array $user): bool
    {
        return StellarAddress::fromUser($user) !== null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{success: bool, stellar_address?: string, stellar_deposit_memo?: string, error?: string, already?: bool}
     */
    public function provision(string $phone, array $user): array
    {
        $existing = StellarAddress::fromUser($user);
        if ($existing !== null) {
            $memo = StellarReceive::assignMemo($this->users, $phone, $user);

            return [
                'success' => true,
                'stellar_address' => $existing,
                'stellar_deposit_memo' => $memo,
                'already' => true,
            ];
        }

        $response = $this->client->createAccount($phone);
        if (empty($response['success']) || empty($response['stellar_address']) || empty($response['encrypted_stellar_private_key'])) {
            error_log('stellar provision failed for ' . $phone . ': ' . json_encode($response));

            return [
                'success' => false,
                'error' => (string) ($response['error'] ?? 'Could not create Stellar wallet'),
            ];
        }

        $addr = (string) $response['stellar_address'];
        $this->users->update($phone, [
            'stellar_address' => $addr,
            'encrypted_stellar_private_key' => (string) $response['encrypted_stellar_private_key'],
        ]);
        $fresh = $this->users->findByPhone($phone);
        $memo = StellarReceive::assignMemo($this->users, $phone, is_array($fresh) ? $fresh : []);

        return [
            'success' => true,
            'stellar_address' => $addr,
            'stellar_deposit_memo' => $memo,
        ];
    }

    public function promptCreate(WhatsAppService $whatsapp, string $phone): void
    {
        $whatsapp->sendButtonMessage(
            $phone,
            "You don't currently have a Stellar wallet on Sendra.\n\nTap below to create one — Sendra pays the network fees. You never need XLM.",
            ['create_stellar_wallet' => 'Create Stellar']
        );
    }
}
