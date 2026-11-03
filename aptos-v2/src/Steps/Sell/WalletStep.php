<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\AptosBalanceService;
use AptosV2\Support\ArcBalanceService;
use AptosV2\Support\ArcWalletService;
use AptosV2\Support\StellarBalanceService;
use AptosV2\Support\StellarWalletService;
use AptosV2\Support\WalletChoice;

final class WalletStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, 'Cancelled. Type *menu* for options.');

            return StepResult::complete(['action' => 'sell_cancelled']);
        }

        $chain = WalletChoice::parse($ctx->messageLower);
        if ($chain === WalletChoice::SOLANA || $chain === WalletChoice::POLYGON) {
            $chain = null;
        }
        if ($chain === null) {
            WalletChoice::prompt($ctx, 'Cash out from which wallet?');

            return StepResult::stay(['action' => 'sell_wallet_reprompt']);
        }

        $amountStr = (string) $ctx->stateValue('crypto_amount', '');
        $asset = (string) $ctx->stateValue('asset', '');
        $fiat = (string) $ctx->stateValue('fiat', 'NGN');

        if ($chain === WalletChoice::ARC) {
            if (!ArcWalletService::hasAddress($ctx->user)) {
                return WalletChoice::missingArc($ctx);
            }
            if (strtoupper($asset) !== 'USDC') {
                $this->reply($ctx, 'Arc cash-out is *USDC* only. Try that, or cash out from Aptos or Stellar.');

                return StepResult::complete(['action' => 'sell_arc_usdc_only']);
            }
            /** @var ArcBalanceService $arcBal */
            $arcBal = $ctx->services->get('arc_balance');
            if ($fail = Guard::validateArcAmount($ctx, $ctx->whatsapp(), $arcBal, $amountStr, $asset)) {
                return $fail;
            }
        } elseif ($chain === WalletChoice::STELLAR) {
            if (!StellarWalletService::hasAddress($ctx->user)) {
                return WalletChoice::missingStellar($ctx);
            }
            if (strtoupper($asset) !== 'USDC' && strtoupper($asset) !== 'USDT') {
                $this->reply($ctx, 'Stellar cash-out currently supports *USDC* or *USDT*. Try that, or cash out from Aptos.');

                return StepResult::complete(['action' => 'sell_stellar_usdc_only']);
            }
            /** @var StellarBalanceService $stellarBal */
            $stellarBal = $ctx->services->get('stellar_balance');
            if ($fail = Guard::validateStellarAmount($ctx, $ctx->whatsapp(), $stellarBal, $amountStr, $asset)) {
                return $fail;
            }
        } else {
            /** @var AptosBalanceService $balances */
            $balances = $ctx->services->get('aptos_balance');
            if ($fail = Guard::validateCryptoAmount($ctx, $ctx->whatsapp(), $balances, $amountStr, $asset)) {
                return $fail;
            }
            if ($missing = Guard::completeIfOneclickMissing($ctx, $ctx->whatsapp())) {
                return $missing;
            }
        }

        if ($fiat === 'XOF') {
            $country = (string) $ctx->stateValue('xof_country', 'TG');
            $countryFlag = $country === 'BJ' ? '🇧🇯' : '🇹🇬';
            $countryLabel = $country === 'BJ' ? 'Benin' : 'Togo';
            $btnLabels = $country === 'BJ'
                ? ['sell_xof_net_mtn' => 'MTN', 'sell_xof_net_moov' => 'Moov']
                : ['sell_xof_net_tmoney' => 'TMoney', 'sell_xof_net_moov' => 'Moov', 'sell_xof_net_mixx' => 'Mixx by Yas'];

            $this->buttons(
                $ctx,
                "{$countryFlag} *XOF MoMo payout* — {$countryLabel}\n\nCashing out *{$amountStr} {$asset}* from *" .
                WalletChoice::label($chain) . "*.\n\nWhich MoMo network?\n\nType *cancel* to stop.",
                $btnLabels
            );

            return StepResult::transition('sell', 'network', [
                'fiat' => 'XOF',
                'crypto_amount' => $amountStr,
                'asset' => $asset,
                'xof_country' => $country,
                'wallet_chain' => $chain,
            ], ['action' => 'sell_xof_network_prompt']);
        }

        $formatExample = $fiat === 'KES'
            ? "Reply in this format:\n`0758901726 Mpesa` or `0758901726 KCB`\nExample: `0758901726 Mpesa`"
            : "Reply in this format:\n`0123456789 BankName`\nExample: `0123456789 GTBank`";

        $this->reply(
            $ctx,
            "🏦 Cashing out *{$amountStr} {$asset}* from *" . WalletChoice::label($chain) . "*.\n\n" .
            "{$formatExample}\n\nType *cancel* to stop."
        );

        return StepResult::transition('sell', 'bank', [
            'fiat' => $fiat,
            'crypto_amount' => $amountStr,
            'asset' => $asset,
            'wallet_chain' => $chain,
        ], ['action' => 'sell_bank_prompt']);
    }
}
