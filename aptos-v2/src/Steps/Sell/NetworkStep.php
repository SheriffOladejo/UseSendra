<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Services\SendraCentiivRampService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * MoMo network selection (Moov, MTN, Yas for Togo; MTN, Moov for Benin).
 */
final class NetworkStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'sell_cancelled']);
        }

        $fiat = strtoupper((string) $ctx->stateValue('fiat', 'XOF'));
        $country = strtoupper((string) $ctx->stateValue('xof_country', 'TG'));
        $account = (string) $ctx->stateValue('preset_account', '');
        if ($account !== '') {
            if (str_starts_with($account, '229')) {
                $country = 'BJ';
            } elseif (str_starts_with($account, '228')) {
                $country = 'TG';
            }
        }

        $networkMap = $country === 'BJ'
            ? ['sell_xof_net_mtn' => 'MTN', 'sell_xof_net_moov' => 'MOOV']
            : ['sell_xof_net_tmoney' => 'TMONEY', 'sell_xof_net_moov' => 'MOOV', 'sell_xof_net_mixx' => 'MIXX'];

        $matchedNetwork = null;
        $msgLower = $ctx->messageLower;
        $msgUpper = strtoupper(trim($ctx->message));

        foreach ($networkMap as $btnKey => $netCode) {
            if ($msgLower === $btnKey || $msgUpper === $netCode || $msgLower === strtolower($netCode)) {
                $matchedNetwork = $netCode;
                break;
            }
        }

        if ($matchedNetwork === null) {
            // Check common aliases and legacy keys
            if (in_array($msgLower, ['tmoney', 't-money', 'togocom', 'sell_xof_net_tmoney'], true)) {
                $matchedNetwork = 'TMONEY';
            } elseif (in_array($msgLower, ['mixx', 'miix', 'yas', 'mixx by yas', 'sell_xof_net_mixx', 'sell_xof_net_yas'], true)) {
                $matchedNetwork = 'MIXX';
            } elseif (in_array($msgLower, ['moov', 'flooz', 'sell_xof_net_moov'], true)) {
                $matchedNetwork = 'MOOV';
            } elseif (in_array($msgLower, ['mtn', 'momo', 'sell_xof_net_mtn'], true)) {
                $matchedNetwork = 'MTN';
            }
        }

        if ($matchedNetwork === null) {
            $countryLabel = $country === 'BJ' ? 'Benin' : 'Togo';
            $btnLabels = $country === 'BJ'
                ? ['sell_xof_net_mtn' => 'MTN', 'sell_xof_net_moov' => 'Moov']
                : ['sell_xof_net_tmoney' => 'TMoney', 'sell_xof_net_moov' => 'Moov', 'sell_xof_net_mixx' => 'Mixx by Yas'];

            $this->buttons(
                $ctx,
                "Which MoMo network is the number on? ({$countryLabel})\n\nType *cancel* to stop.",
                $btnLabels
            );

            return StepResult::stay(['action' => 'sell_network_reprompt']);
        }

        $networkDisplay = match ($matchedNetwork) {
            'TMONEY' => 'TMoney',
            'MOOV' => 'Moov',
            'MIXX', 'YAS' => 'Mixx by Yas',
            'MTN' => 'MTN',
            default => $matchedNetwork,
        };

        $bankDisplay = $networkDisplay . ' MoMo';
        $targetNgn = (float) $ctx->stateValue('target_receive_ngn', 0);
        $cryptoAmount = (string) $ctx->stateValue('crypto_amount', '');
        $asset = (string) $ctx->stateValue('asset', '');

        // If this came from a fixed fiat cash-out command (`send 700 xof to ...`)
        if ($targetNgn > 0 && $account !== '') {
            $sym = SendraCentiivRampService::currencySymbol($fiat);
            $n = number_format($targetNgn, 0, '.', ',');

            $this->buttons(
                $ctx,
                "Send *≈ {$sym}{$n}* to `{$account}` (*{$bankDisplay}*).\n\nWhich balance should we use?",
                [
                    'sell_pick_usdt' => 'USDT',
                    'sell_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::transition('sell', 'asset', [
                'fiat' => $fiat,
                'target_receive_ngn' => $targetNgn,
                'preset_account' => $account,
                'preset_bank_guess' => $bankDisplay,
                'bank_code' => $matchedNetwork,
                'bank_name' => $bankDisplay,
                'xof_country' => $country,
            ], ['action' => 'sell_send_fiat_pick']);
        }

        // If this came from crypto-first sell (`sell 5 usdc` or `sell`)
        $countryFlag = $country === 'BJ' ? '🇧🇯' : '🇹🇬';
        $examplePhone = $country === 'BJ' ? '22990123456' : '22890123456';
        $countryName = $country === 'BJ' ? 'Benin' : 'Togo';

        $this->reply(
            $ctx,
            "{$countryFlag} *{$networkDisplay} MoMo payout*\n\n" .
            "Enter your MoMo phone number (with country code).\n" .
            "Example: `{$examplePhone}` ({$countryName})\n\n" .
            "Type *cancel* to stop."
        );

        return StepResult::transition('sell', 'bank', [
            'fiat' => $fiat,
            'crypto_amount' => $cryptoAmount,
            'asset' => $asset,
            'bank_code' => $matchedNetwork,
            'bank_name' => $bankDisplay,
            'preset_bank_guess' => $bankDisplay,
            'xof_country' => $country,
        ], ['action' => 'sell_bank_prompt']);
    }
}
