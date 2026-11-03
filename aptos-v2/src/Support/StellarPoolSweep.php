<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;

/**
 * Move USDC from the shared Sendra G (Centiiv/memo deposits) to the user's C wallet.
 * SAC transfer from G spends the classic Circle USDC trustline 1:1 (no wrap fee).
 */
final class StellarPoolSweep
{
    public function __construct(private StellarServiceClient $client = new StellarServiceClient())
    {
    }

    /**
     * @return array{success: bool, tx_hash?: string, explorer_url?: string, error?: string, skipped?: bool}
     */
    public function sweepToUser(array $user, string $amount, string $currency = 'USDC'): array
    {
        $c = StellarAddress::fromUser($user);
        if ($c === null) {
            return ['success' => false, 'error' => 'missing_stellar_c'];
        }
        $amt = trim(str_replace(',', '', $amount));
        if ($amt === '' || !is_numeric($amt) || (float) $amt <= 0) {
            return ['success' => false, 'error' => 'invalid_amount'];
        }

        return $this->client->sweepToC([
            'recipient' => $c,
            'amount' => $amt,
            'currency' => $currency,
        ]);
    }

    public static function forPhone(\PDO $db, string $phone): ?array
    {
        $user = (new User($db))->findByPhone($phone);

        return is_array($user) ? $user : null;
    }
}
