<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Normalize / infer on-chain network labels for history + admin inspector.
 */
final class ChainNetwork
{
    public const APTOS = 'aptos';
    public const STELLAR = 'stellar';
    public const ARC = 'arc';
    public const SOLANA = 'solana';
    public const POLYGON = 'polygon';

    /** @var list<string> */
    public const KNOWN = [
        self::APTOS,
        self::STELLAR,
        self::ARC,
        self::SOLANA,
        self::POLYGON,
    ];

    public static function normalize(?string $chain): string
    {
        $c = strtolower(trim((string) $chain));
        if ($c === '' || !in_array($c, self::KNOWN, true)) {
            return self::APTOS;
        }

        return $c;
    }

    /**
     * Infer chain from explorer URL (and optional free-text hints).
     */
    public static function infer(?string $explorerUrl, ?string $hint = null): string
    {
        $hay = strtolower(trim((string) $explorerUrl) . ' ' . trim((string) $hint));
        foreach ([self::STELLAR, self::ARC, self::SOLANA, self::POLYGON, self::APTOS] as $name) {
            if ($hay !== '' && str_contains($hay, $name)) {
                return $name;
            }
        }

        return self::APTOS;
    }

    public static function resolve(?string $explicit, ?string $explorerUrl = null, ?string $hint = null): string
    {
        $c = strtolower(trim((string) $explicit));
        if ($c !== '' && in_array($c, self::KNOWN, true)) {
            return $c;
        }

        return self::infer($explorerUrl, $hint);
    }

    public static function explorerUrlForHash(string $chain, string $hash): string
    {
        $hash = trim($hash);
        if ($hash === '') {
            return '';
        }
        return match (self::normalize($chain)) {
            self::STELLAR => 'https://stellar.expert/explorer/public/tx/' . $hash,
            self::ARC => 'https://explorer.arc.io/tx/' . $hash,
            self::SOLANA => 'https://solscan.io/tx/' . $hash,
            default => 'https://explorer.aptoslabs.com/txn/' . $hash,
        };
    }

    public static function label(string $chain): string
    {
        return match (self::normalize($chain)) {
            self::STELLAR => 'Stellar',
            self::ARC => 'Arc',
            self::SOLANA => 'Solana',
            self::POLYGON => 'Polygon',
            default => 'Aptos',
        };
    }
}
