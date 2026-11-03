<?php

declare(strict_types=1);

use AptosV2\Support\ChangePinIntent;
use AptosV2\Support\GreetingMatcher;

/**
 * Flow-entry intents (the command layer in front of the step router).
 *
 * First match wins. Unmatched messages fall through to the legacy controller.
 */

return [
    // ── Onboarding (guest users — Kernel remaps greetings here) ───────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'create_stellar_wallet';
        },
        'flow' => 'cmd',
        'step' => 'create_stellar',
    ],
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'create_arc_wallet';
        },
        'flow' => 'cmd',
        'step' => 'create_arc',
    ],
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'create_account' || $messageLower === 'create_logistics_account';
        },
        'flow' => 'onboard',
        'step' => 'create',
    ],
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'import_account';
        },
        'flow' => 'onboard',
        'step' => 'welcome',
    ],

    // ── Cancel (pending tx or in-flight legacy buy/sell context) ─────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'cancel' || str_starts_with($messageLower, 'cancel_');
        },
        'flow' => 'cmd',
        'step' => 'cancel',
    ],

    // ── Help ─────────────────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/help' || $messageLower === 'help';
        },
        'flow' => 'cmd',
        'step' => 'help',
    ],

    // ── Change transaction PIN ─────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return ChangePinIntent::matches($message);
        },
        'flow' => 'cmd',
        'step' => 'change_pin',
    ],

    // ── Balance ────────────────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/balance' || $messageLower === 'balance';
        },
        'flow' => 'cmd',
        'step' => 'balance',
    ],

    // ── Live FX rate card ──────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'rate'
                || $messageLower === 'rates'
                || $messageLower === '/rate'
                || $messageLower === '/rates';
        },
        'flow' => 'cmd',
        'step' => 'rate',
    ],

    // ── Deposit (fund wallet) ──────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/deposit'
                || $messageLower === 'deposit'
                || $messageLower === '/receive'
                || $messageLower === 'receive'
                || str_starts_with($messageLower, 'deposit')
                || str_starts_with($messageLower, 'receive');
        },
        'flow' => 'deposit',
        'step' => 'entry',
    ],

    // ── Receive (on-chain deposit address) ───────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/receive' || $messageLower === 'receive';
        },
        'flow' => 'cmd',
        'step' => 'receive',
    ],

    // ── Deposit (menu button + command) ──────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'deposit' || $messageLower === '/deposit';
        },
        'flow' => 'deposit',
        'step' => 'entry',
    ],

    // ── Withdraw (menu button + command) ───────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'withdraw' || $messageLower === '/withdraw';
        },
        'flow' => 'withdraw',
        'step' => 'entry',
    ],

    // ── Transaction history ────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/history' || $messageLower === 'history';
        },
        'flow' => 'cmd',
        'step' => 'history',
    ],

    // ── Menu (explicit) ──────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === '/menu' || $messageLower === 'menu';
        },
        'flow' => 'cmd',
        'step' => 'menu',
    ],

    // ── Greetings → main menu ──────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return GreetingMatcher::isGreeting($messageLower);
        },
        'flow' => 'cmd',
        'step' => 'menu',
    ],

    // ── Send-naira cash-out (`send 1000 naira to …`) ─────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return \AptosV2\Steps\Sell\NairaIntentParser::parse($message) !== null;
        },
        'flow' => 'sell',
        'step' => 'naira',
    ],

    // ── Send (crypto P2P only — naira bank cash-out uses sell:naira above) ───
    [
        'match' => static function (string $message, string $messageLower): bool {
            return \AptosV2\Steps\Send\IntentParser::isCryptoSendIntent($messageLower);
        },
        'flow' => 'send',
        'step' => 'start',
    ],

    // ── Swap (USDT <-> USDC) ─────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'swap'
                || str_starts_with($messageLower, 'swap ')
                || str_starts_with($messageLower, 'convert ');
        },
        'flow' => 'swap',
        'step' => 'start',
    ],

    // ── Polymarket (NGN → Polygon USDT via Daya) ──────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'polymarket'
                || $messageLower === '/polymarket'
                || $messageLower === 'fund polymarket'
                || str_starts_with($messageLower, 'polymarket ')
                || str_starts_with($messageLower, 'fund polymarket');
        },
        'flow' => 'polymarket',
        'step' => 'entry',
    ],

    // ── Buy (NGN on-ramp) ─────────────────────────────────────────────────────
    [
        'match' => static function (string $message, string $messageLower): bool {
            return $messageLower === 'buy'
                || $messageLower === 'buy_fiat_ngn'
                || str_starts_with($messageLower, 'buy ');
        },
        'flow' => 'buy',
        'step' => 'entry',
    ],

    // ── Sell (crypto-first USDC/USDT in v2; send-naira sizing phased in v2) ───
    [
        'match' => static function (string $message, string $messageLower): bool {
            if ($messageLower === 'sell' || str_starts_with($messageLower, 'sell_fiat_')) {
                return true;
            }
            if (!str_starts_with($messageLower, 'sell ')) {
                return false;
            }

            return !preg_match('/\b(naira|ngn)\b/i', $message);
        },
        'flow' => 'sell',
        'step' => 'entry',
    ],
];
