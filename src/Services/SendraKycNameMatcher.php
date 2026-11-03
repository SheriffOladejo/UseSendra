<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Compare KYC names: same name parts in any order; partial names do not match.
 */
final class SendraKycNameMatcher
{
    public function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z\s]/', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /**
     * True when both names contain the same words (order ignored), with no extras or omissions.
     */
    public function matches(string $recordName, string $submittedName): bool
    {
        $recordTokens = $this->tokens($this->normalize($recordName));
        $submittedTokens = $this->tokens($this->normalize($submittedName));

        if ($recordTokens === [] || $submittedTokens === []) {
            return false;
        }

        if (count($recordTokens) !== count($submittedTokens)) {
            return false;
        }

        sort($recordTokens);
        sort($submittedTokens);

        return $recordTokens === $submittedTokens;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalizedName): array
    {
        if ($normalizedName === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $normalizedName, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }
}
