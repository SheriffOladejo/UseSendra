<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOneClickService;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\AptosBalanceService;
use AptosV2\Support\WalletChoice;

/**
 * Shared sell entry guards (disabled flag, PIN, ramp health, etc.).
 */
final class Guard
{
    /**
     * Returns a StepResult when the flow must stop; null to continue.
     */
    public static function entryBlock(StepContext $ctx, WhatsAppService $whatsapp): ?StepResult
    {
        if (self::sellFlowDisabled()) {
            $whatsapp->sendMessage($ctx->from, self::sellDisabledMessage());

            return StepResult::complete(['action' => 'sell_disabled']);
        }

        if (!self::prerequisitesOk($ctx, $whatsapp)) {
            return StepResult::complete(['action' => 'sell_blocked']);
        }

        $agg = new SendraCentiivRampService();
        $dayaOk = \App\Services\SendraAptosRamp::usesDayaForAptos();
        if (!$agg->isConfigured() && !$dayaOk) {
            $whatsapp->sendMessage(
                $ctx->from,
                "❌ Cash-out isn't available right now. Try again later or email support@usesendra.com"
            );

            return StepResult::complete(['action' => 'sell_unconfigured']);
        }

        if (!empty($ctx->user['pending_tx_id'])) {
            $whatsapp->sendMessage(
                $ctx->from,
                "⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*."
            );

            return StepResult::complete(['action' => 'sell_pending_exists']);
        }

        if ($agg->isConfigured() && !\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $health = self::rampHealthGateIfDown($ctx->from, $whatsapp, $agg);
            if ($health !== null) {
                return $health;
            }
        }

        return null;
    }

    /**
     * Validates amount + balance for USDC/USDT cash-out. Returns StepResult on failure.
     */
    public static function validateCryptoAmount(
        StepContext $ctx,
        WhatsAppService $whatsapp,
        AptosBalanceService $balances,
        string $amountStr,
        string $asset
    ): ?StepResult {
        $amount = (float) $amountStr;
        if ($amount < 0.01) {
            $whatsapp->sendMessage($ctx->from, "⚠️ Minimum amount is *0.01* {$asset}.");

            return StepResult::stay(['action' => 'sell_amount_too_small']);
        }

        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            $whatsapp->sendMessage($ctx->from, '⚠️ Only *USDC* or *USDT* on Aptos are supported.');

            return StepResult::stay(['action' => 'sell_invalid_asset']);
        }

        if ($asset === 'USDT' && !self::oneclickCentiivRampConfigured() && !\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $whatsapp->sendMessage($ctx->from, "❌ USDT isn't available for this flow right now. Try *USDC*.");

            return StepResult::stay(['action' => 'sell_usdt_unavailable']);
        }

        $wallet = (string) ($ctx->user['aptos_address'] ?? '');
        if ($wallet === '') {
            $whatsapp->sendMessage($ctx->from, '❌ No wallet on file. Type *hi* to get started.');

            return StepResult::complete(['action' => 'sell_no_wallet']);
        }

        $balanceRow = $balances->fetch($wallet);
        if ($balanceRow === null) {
            $whatsapp->sendMessage($ctx->from, '❌ Could not read your balance. Try again.');

            return StepResult::stay(['action' => 'sell_balance_fetch_failed']);
        }

        $avail = (float) str_replace(',', '', $balanceRow[$asset] ?? '0');
        if ($avail + 1e-9 < $amount) {
            $display = (string) ($balanceRow[$asset] ?? '0');
            $whatsapp->sendMessage(
                $ctx->from,
                "❌ Insufficient *{$asset}* balance.\nYou have: *{$display}*\nNeeded: *{$amountStr}*"
            );

            return StepResult::stay(['action' => 'sell_insufficient_balance']);
        }

        return null;
    }

    public static function validateStellarAmount(
        StepContext $ctx,
        WhatsAppService $whatsapp,
        \AptosV2\Support\StellarBalanceService $balances,
        string $amountStr,
        string $asset
    ): ?StepResult {
        $asset = \AptosV2\Support\StellarStable::display($asset);
        $amount = (float) $amountStr;
        if ($amount < 0.01) {
            $whatsapp->sendMessage($ctx->from, "⚠️ Minimum amount is *0.01* {$asset}.");

            return StepResult::stay(['action' => 'sell_amount_too_small']);
        }
        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            $whatsapp->sendMessage($ctx->from, 'Stellar cash-out currently supports *USDC* or *USDT*.');

            return StepResult::stay(['action' => 'sell_stellar_asset']);
        }
        $wallet = (string) ($ctx->user['stellar_address'] ?? '');
        if ($wallet === '') {
            return WalletChoice::missingStellar($ctx);
        }
        $balanceRow = $balances->fetch($wallet);
        if ($balanceRow === null) {
            $whatsapp->sendMessage($ctx->from, '❌ Could not read your Stellar balance. Try again.');

            return StepResult::stay(['action' => 'sell_balance_fetch_failed']);
        }
        $avail = (float) str_replace(',', '', $balanceRow[$asset] ?? '0');
        if ($avail + 1e-9 < $amount) {
            $display = (string) ($balanceRow[$asset] ?? '0');
            $whatsapp->sendMessage(
                $ctx->from,
                "❌ Insufficient *{$asset}* on Stellar.\nYou have: *{$display}*\nNeeded: *{$amountStr}*"
            );

            return StepResult::stay(['action' => 'sell_insufficient_balance']);
        }

        return null;
    }

    public static function validateArcAmount(
        StepContext $ctx,
        WhatsAppService $whatsapp,
        \AptosV2\Support\ArcBalanceService $balances,
        string $amountStr,
        string $asset
    ): ?StepResult {
        $asset = strtoupper($asset);
        $amount = (float) $amountStr;
        if ($asset !== 'USDC') {
            $whatsapp->sendMessage($ctx->from, 'Arc cash-out is *USDC* only.');

            return StepResult::stay(['action' => 'sell_arc_asset']);
        }
        if ($amount < 0.01) {
            $whatsapp->sendMessage($ctx->from, '⚠️ Minimum amount is *0.01* USDC.');

            return StepResult::stay(['action' => 'sell_amount_too_small']);
        }
        $wallet = (string) ($ctx->user['arc_address'] ?? '');
        if ($wallet === '') {
            return WalletChoice::missingArc($ctx);
        }
        $balanceRow = $balances->fetch($wallet);
        if ($balanceRow === null) {
            $whatsapp->sendMessage($ctx->from, '❌ Could not read your Arc balance. Try again.');

            return StepResult::stay(['action' => 'sell_balance_fetch_failed']);
        }
        $avail = (float) str_replace(',', '', $balanceRow['USDC'] ?? '0');
        $needed = $amount + 0.01;
        if ($avail + 1e-9 < $needed) {
            $display = (string) ($balanceRow['USDC'] ?? '0');
            $max = $avail - 0.01;
            if ($max < 0.01) {
                $whatsapp->sendMessage(
                    $ctx->from,
                    "❌ Insufficient *USDC* on Arc.\nYou have: *{$display}*\nArc needs a little USDC left over for the network fee, so this balance cannot be cashed out yet."
                );
            } else {
                $maxStr = rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.');
                $whatsapp->sendButtonMessage(
                    $ctx->from,
                    "❌ Insufficient *USDC* on Arc.\nYou have: *{$display}*\nArc keeps a little USDC for the network fee, so the most you can cash out is *{$maxStr} USDC*.",
                    ['sell ' . $maxStr . ' usdc' => 'Sell ' . $maxStr . ' USDC']
                );
            }

            return StepResult::stay(['action' => 'sell_insufficient_balance']);
        }

        return null;
    }

    public static function oneclickCentiivRampConfigured(): bool
    {
        return \App\Services\SendraOfframpBridge::oneclickRampConfigured();
    }

    public static function completeIfOneclickMissing(StepContext $ctx, WhatsAppService $whatsapp): ?StepResult
    {
        if (\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return null;
        }
        if (self::oneclickCentiivRampConfigured()) {
            return null;
        }

        $whatsapp->sendMessage(
            $ctx->from,
            "❌ Cash-out isn't available right now. Try again later or email support@usesendra.com"
        );

        return StepResult::complete(['action' => 'sell_oneclick_not_configured']);
    }

    private static function sellFlowDisabled(): bool
    {
        $raw = $_ENV['SENDRA_SELL_DISABLED'] ?? getenv('SENDRA_SELL_DISABLED');
        $v = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function sellDisabledMessage(): string
    {
        return "🛠️ *Cash-out is temporarily paused*\n\n"
            . "Selling USDT/USDC for cash is down for maintenance right now.\n"
            . 'Your funds are safe — please check back soon.';
    }

    private static function prerequisitesOk(StepContext $ctx, WhatsAppService $whatsapp): bool
    {
        if (empty($ctx->user['aptos_address'])) {
            $whatsapp->sendMessage($ctx->from, '❌ No wallet on file. Type *hi* to get started.');

            return false;
        }
        if (empty($ctx->user['pin_hash'])) {
            $whatsapp->sendMessage($ctx->from, "⚠️ Set a *6-digit PIN* first to use sell.");
            $userModel = $ctx->services->get('user_model');
            $userModel->update($ctx->from, ['awaiting_pin_setup' => 1]);
            $url = \App\Services\PinConfirmTokenService::buildSetupUrl($ctx->from, time() + 30 * 60);
            $whatsapp->sendCTAUrlButton(
                $ctx->from,
                "🔐 *Set your transaction PIN*\n\n" .
                "Choose a *6-digit PIN* you'll use to confirm sends and payouts.\n\n" .
                'Tap *Set PIN* below to open the secure page.',
                'Set PIN',
                $url
            );

            return false;
        }

        return true;
    }

    private static function rampHealthGateIfDown(
        string $from,
        WhatsAppService $whatsapp,
        SendraCentiivRampService $centiiv
    ): ?StepResult {
        if (!$centiiv->isConfigured()) {
            return null;
        }
        $gate = \App\Services\SendraRampHealthService::evaluateSellGate(true, self::oneclickCentiivRampConfigured());
        if (empty($gate['block'])) {
            return null;
        }
        $whatsapp->sendMessage($from, (string) ($gate['message'] ?? '⚠️ Cash-out is temporarily unavailable. Try again soon.'));

        return StepResult::complete(['action' => 'ramp_health_blocked']);
    }
}
