<?php

declare(strict_types=1);

namespace AptosV2\Support;

use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class WalletChoice
{
    public const APTOS = 'aptos';
    public const STELLAR = 'stellar';
    public const ARC = 'arc';
    public const SOLANA = 'solana';
    public const POLYGON = 'polygon';

    public static function parse(string $messageLower): ?string
    {
        if (in_array($messageLower, ['wallet_solana', 'dep_net_solana', 'solana'], true)
            || str_contains($messageLower, 'solana')) {
            return self::SOLANA;
        }
        if (in_array($messageLower, ['wallet_polygon', 'dep_net_polygon', 'polygon', 'polymarket'], true)
            || str_contains($messageLower, 'polygon')) {
            return self::POLYGON;
        }
        if (in_array($messageLower, ['wallet_arc', 'dep_net_arc', 'arc', 'create_arc_wallet'], true)
            || preg_match('/\barc\b/', $messageLower) === 1) {
            return self::ARC;
        }
        if (in_array($messageLower, ['wallet_aptos', 'dep_net_aptos', 'aptos'], true)
            || str_contains($messageLower, 'aptos')) {
            return self::APTOS;
        }
        if (in_array($messageLower, ['wallet_stellar', 'dep_net_stellar', 'stellar', 'create_stellar_wallet'], true)
            || str_contains($messageLower, 'stellar')) {
            return self::STELLAR;
        }

        return null;
    }

    /**
     * @param array<string, string> $extraButtons
     */
    public static function prompt(
        StepContext $ctx,
        string $body,
        array $extraButtons = [],
        bool $recommendStellar = false,
        bool $includeSolana = false,
        bool $includePolygon = false,
    ): void {
        if ($recommendStellar) {
            $body = rtrim($body) . "\n\n⭐ *Stellar* is recommended — usually faster and cheaper.";
        }
        if ($includePolygon) {
            $body = rtrim($body) . "\nPolygon lands *USDT* only (not USDC) at an address you paste (Polymarket works).";
        }
        $buttons = $recommendStellar
            ? [
                'wallet_stellar' => '⭐ Stellar',
                'wallet_aptos' => 'Aptos',
                'wallet_arc' => 'Arc',
            ]
            : [
                'wallet_aptos' => 'Aptos',
                'wallet_stellar' => 'Stellar',
                'wallet_arc' => 'Arc',
            ];
        if ($includeSolana) {
            $buttons['wallet_solana'] = 'Solana';
        }
        if ($includePolygon) {
            $buttons['wallet_polygon'] = 'Polygon';
        }
        $ctx->whatsapp()->sendButtonMessage(
            $ctx->from,
            $body,
            array_merge($buttons, $extraButtons)
        );
    }

    public static function missingStellar(StepContext $ctx): StepResult
    {
        /** @var StellarWalletService $svc */
        $svc = $ctx->services->get('stellar_wallet');
        $svc->promptCreate($ctx->whatsapp(), $ctx->from);

        return StepResult::complete(['action' => 'stellar_wallet_missing']);
    }

    public static function missingArc(StepContext $ctx): StepResult
    {
        /** @var ArcWalletService $svc */
        $svc = $ctx->services->get('arc_wallet');
        $svc->promptCreate($ctx->whatsapp(), $ctx->from);

        return StepResult::complete(['action' => 'arc_wallet_missing']);
    }

    public static function label(string $chain): string
    {
        return match ($chain) {
            self::STELLAR => 'Stellar',
            self::ARC => 'Arc',
            self::SOLANA => 'Solana',
            self::POLYGON => 'Polygon',
            default => 'Aptos',
        };
    }
}
