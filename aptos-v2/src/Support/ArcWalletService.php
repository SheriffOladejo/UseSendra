<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;
use App\Services\WhatsAppService;

final class ArcWalletService
{
    public function __construct(
        private User $users,
        private ArcServiceClient $client = new ArcServiceClient(),
    ) {
    }

    public static function hasAddress(array $user): bool
    {
        return ArcAddress::fromUser($user) !== null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{success: bool, arc_address?: string, error?: string, already?: bool}
     */
    public function provision(string $phone, array $user): array
    {
        $existing = ArcAddress::fromUser($user);
        if ($existing !== null) {
            return [
                'success' => true,
                'arc_address' => $existing,
                'already' => true,
            ];
        }

        $response = $this->client->createAccount($phone);
        if (empty($response['success']) || empty($response['arc_address']) || empty($response['encrypted_arc_private_key'])) {
            error_log('arc provision failed for ' . $phone . ': ' . json_encode($response));

            return [
                'success' => false,
                'error' => (string) ($response['error'] ?? 'Could not create Arc wallet'),
            ];
        }

        $addr = (string) $response['arc_address'];
        $this->users->update($phone, [
            'arc_address' => $addr,
            'encrypted_arc_private_key' => (string) $response['encrypted_arc_private_key'],
        ]);

        return [
            'success' => true,
            'arc_address' => $addr,
        ];
    }

    public function promptCreate(WhatsAppService $whatsapp, string $phone): void
    {
        $whatsapp->sendButtonMessage(
            $phone,
            "You don't currently have an Arc wallet on Sendra.\n\nTap below to create one. Arc uses USDC only.",
            ['create_arc_wallet' => 'Create Arc']
        );
    }
}
