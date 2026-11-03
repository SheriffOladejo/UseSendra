<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Models\User;
use App\Services\WhatsAppService;

/**
 * Shared Sendra Stellar G + per-user memo (Centiiv / CEX-style receive).
 * User C-address stays the spend wallet; indexer/webhook sweeps G+memo → C.
 */
final class StellarReceive
{
    public static function sharedAddress(): string
    {
        foreach (['SENDRA_STELLAR_RECEIVE_G', 'STELLAR_OPERATOR_PUBLIC_KEY'] as $key) {
            $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
            if ($v !== '' && StellarAddress::isClassic($v)) {
                return $v;
            }
        }

        return 'GCYX7HLYWGA3AORIHLZIRP2EV7HYDFHG5LHACGKLT4AF7TAHZRTUUACI';
    }

    public static function memoFromUser(array $user): ?string
    {
        $memo = trim((string) ($user['stellar_deposit_memo'] ?? ''));

        return $memo !== '' ? $memo : null;
    }

    /**
     * Unique Stellar memo_id (uint64 decimal). Centiiv destinationMemoType defaults to id.
     */
    public static function assignMemo(User $users, string $phone, array $user): string
    {
        $existing = self::memoFromUser($user);
        if ($existing !== null) {
            return $existing;
        }

        for ($i = 0; $i < 8; $i++) {
            $memo = (string) random_int(100_000_000_000, 900_000_000_000);
            try {
                $users->update($phone, ['stellar_deposit_memo' => $memo]);
                $fresh = $users->findByPhone($phone);
                if (is_array($fresh) && self::memoFromUser($fresh) === $memo) {
                    return $memo;
                }
            } catch (\Throwable $e) {
                error_log('StellarReceive::assignMemo collision: ' . $e->getMessage());
            }
        }

        throw new \RuntimeException('Could not assign a unique Stellar deposit memo');
    }

    /**
     * Address and memo each in their own bubble so tap-and-hold copies only that value.
     */
    public static function sendCopyableDeposit(WhatsAppService $whatsapp, string $to, ?string $memo): void
    {
        $g = self::sharedAddress();
        $memoLine = $memo !== null && $memo !== '' ? $memo : '—';

        $whatsapp->sendMessage($to, '📍 *Stellar address* — tap and hold to copy:');
        usleep(250000);
        $whatsapp->sendMessage($to, $g);
        usleep(250000);
        $whatsapp->sendMessage($to, '🔢 *Memo (required)* — tap and hold to copy:');
        usleep(250000);
        $whatsapp->sendMessage($to, $memoLine);
        usleep(250000);
        $whatsapp->sendMessage(
            $to,
            "Send *USDC or USDT* on Stellar to that address *and include the memo*.\n" .
            'Without the memo we cannot credit your wallet. Memo *text* or memo *ID* both work (paste the number).'
        );
    }
}
