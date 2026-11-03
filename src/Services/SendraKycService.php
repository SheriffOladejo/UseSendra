<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sendra NGN ramp KYC tiers and transaction limits.
 *
 * Tier 0 (free): below ₦50,000
 * Tier 1: ₦50,000 – ₦200,000 (NIN + full name)
 * Tier 2: ₦201,000 – ₦2,000,000 (Tier 1 + BVN + liveness)
 * Tier 3: ₦2,000,001 – ₦50,000,000 (Tier 2 + proof of address)
 */
final class SendraKycService
{
    public const TIER_FREE = 0;
    public const TIER_BVN = 1;
    public const TIER_NIN = 2;
    public const TIER_ADDRESS = 3;

    /** @var array<int, float> Max NGN per transaction by tier. */
    private const TIER_MAX_NGN = [
        self::TIER_FREE => 49_999.0,
        self::TIER_BVN => 200_000.0,
        self::TIER_NIN => 2_000_000.0,
        self::TIER_ADDRESS => 50_000_000.0,
    ];

    /** @var array<int, float> Min NGN requiring that tier (inclusive). */
    private const TIER_MIN_NGN = [
        self::TIER_BVN => 50_000.0,
        self::TIER_NIN => 201_000.0,
        self::TIER_ADDRESS => 2_000_001.0,
    ];

    /**
     * @param array<string, mixed> $userRow
     */
    public function getUserTier(array $userRow): int
    {
        $explicit = isset($userRow['kyc_tier']) ? (int) $userRow['kyc_tier'] : null;
        if ($explicit !== null && $explicit >= self::TIER_ADDRESS) {
            return self::TIER_ADDRESS;
        }

        if ($this->hasTier3($userRow)) {
            return self::TIER_ADDRESS;
        }
        if ($this->hasTier2($userRow)) {
            return self::TIER_NIN;
        }
        if ($this->hasTier1($userRow)) {
            return self::TIER_BVN;
        }

        return self::TIER_FREE;
    }

    public function maxNgnForTier(int $tier): float
    {
        $tier = max(self::TIER_FREE, min(self::TIER_ADDRESS, $tier));

        return self::TIER_MAX_NGN[$tier];
    }

    public function requiredTierForAmount(float $ngnAmount): int
    {
        if ($ngnAmount >= self::TIER_MIN_NGN[self::TIER_ADDRESS]) {
            return self::TIER_ADDRESS;
        }
        if ($ngnAmount >= self::TIER_MIN_NGN[self::TIER_NIN]) {
            return self::TIER_NIN;
        }
        if ($ngnAmount >= self::TIER_MIN_NGN[self::TIER_BVN]) {
            return self::TIER_BVN;
        }

        return self::TIER_FREE;
    }

    /**
     * @param array<string, mixed> $userRow
     */
    public function canTransactNgn(array $userRow, float $ngnAmount): bool
    {
        if ($ngnAmount <= 0) {
            return true;
        }

        $userTier = $this->getUserTier($userRow);
        $required = $this->requiredTierForAmount($ngnAmount);

        return $userTier >= $required;
    }

    /**
     * @param array<string, mixed> $userRow
     */
    public function nextTierToVerify(array $userRow): int
    {
        $current = $this->getUserTier($userRow);

        return min(self::TIER_ADDRESS, $current + 1);
    }

    public function tierLabel(int $tier): string
    {
        return match ($tier) {
            self::TIER_BVN => 'Tier 1',
            self::TIER_NIN => 'Tier 2',
            self::TIER_ADDRESS => 'Tier 3',
            default => 'Tier 0',
        };
    }

    public function tierUpgradePrompt(int $requiredTier): string
    {
        $tierName = $this->tierLabel($requiredTier);
        $maxAfter = number_format($this->maxNgnForTier($requiredTier), 0, '.', ',');

        $body = "To sell or buy above your current limit, you need to upgrade your tier by completing your KYC. "
            . "This allows us to provide you with the best service while remaining compliant.\n\n";

        $body .= "*{$tierName}*\n";
        $body .= 'Requirements: ' . $this->tierRequirementsText($requiredTier) . "\n";
        $body .= $this->tierBenefitsText($requiredTier);
        $body .= "\n\nWe only use your information for verification; we do not store your full BVN or NIN (last 4 digits only).\n";
        $body .= "Your BVN/NIN phone number must match your Sendra WhatsApp number.\n";
        $body .= "Would you like to start verification now?\n\n";
        $body .= "_After *{$tierName}* is approved you can transact up to ₦{$maxAfter} per transaction._";

        return $body;
    }

    public function tierApprovedMessage(int $tier): string
    {
        $label = $this->tierLabel($tier);
        $max = number_format($this->maxNgnForTier($tier), 0, '.', ',');

        return "━━━━━━━━━━━━━━━━━━━━\n"
            . "✅ Your *{$label}* verification is complete! You can now transact up to *₦{$max}* per transaction.";
    }

    public function tierRequirementsText(int $tier): string
    {
        return match ($tier) {
            self::TIER_BVN => 'NIN + Full Name',
            self::TIER_NIN => 'Tier 1 + BVN + Liveness Check',
            self::TIER_ADDRESS => 'Tier 2 + Proof of Address',
            default => 'Wallet setup',
        };
    }

    private function tierBenefitsText(int $tier): string
    {
        $max = number_format($this->maxNgnForTier($tier), 0, '.', ',');
        $min = match ($tier) {
            self::TIER_BVN => '₦50,000',
            self::TIER_NIN => '₦201,000',
            self::TIER_ADDRESS => '₦2,000,001',
            default => '₦0',
        };

        return "- Naira deposits/withdrawals from {$min} to ₦{$max} per transaction.\n"
            . "- Unlimited USDC/USDT deposits/withdrawals\n"
            . '- Unlimited account balance.';
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function hasTier1(array $userRow): bool
    {
        $tier = (int) ($userRow['kyc_tier'] ?? 0);
        if ($tier >= self::TIER_BVN) {
            return true;
        }

        $fullName = trim((string) ($userRow['kyc_full_name'] ?? ''));
        if ($fullName === '') {
            return !empty($userRow['kyc_verified']);
        }

        if (!empty($userRow['nin_last4']) || !empty($userRow['nin'])) {
            return true;
        }

        // Legacy: Tier 1 was BVN-only before liveness.
        return !empty($userRow['bvn_last4']) || !empty($userRow['bvn']);
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function hasTier2(array $userRow): bool
    {
        $tier = (int) ($userRow['kyc_tier'] ?? 0);
        if ($tier >= self::TIER_NIN) {
            return true;
        }

        if (empty($userRow['liveness_verified'])) {
            return false;
        }

        if (!$this->hasTier1($userRow)) {
            return false;
        }

        $hasNin = !empty($userRow['nin_last4']) || !empty($userRow['nin']);
        $hasBvn = !empty($userRow['bvn_last4']) || !empty($userRow['bvn']);

        return $hasNin && $hasBvn;
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function hasTier3(array $userRow): bool
    {
        $tier = (int) ($userRow['kyc_tier'] ?? 0);
        if ($tier >= self::TIER_ADDRESS) {
            return true;
        }

        return (!empty($userRow['address_verified']) || !empty($userRow['smiled_verified']))
            && $this->hasTier2($userRow);
    }
}
