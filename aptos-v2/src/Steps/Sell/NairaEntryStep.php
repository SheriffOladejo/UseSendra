<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Kyc\Gate as KycGate;

/**
 * Entry for fixed-NGN cash-out: `send 1000 naira to 0123456789 GTBank`.
 */
final class NairaEntryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $parsed = NairaIntentParser::parse($ctx->message);
        if ($parsed === null) {
            $this->reply(
                $ctx,
                "Bank cash-out needs at least *₦100* in this form:\n`send 1000 naira to 0123456789 GTBank`"
            );

            return StepResult::complete(['action' => 'sell_naira_format_error']);
        }

        $guessUpper = strtoupper(trim((string) ($parsed['bank_guess'] ?? '')));

        if (!Guard::oneclickCentiivRampConfigured() && !\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $missing = Guard::completeIfOneclickMissing($ctx, $ctx->whatsapp());

            return $missing ?? StepResult::complete(['action' => 'sell_oneclick_not_configured']);
        }

        $fiat = strtoupper((string) ($parsed['fiat'] ?? 'NGN'));
        if ($fiat === 'NGN' && str_starts_with($parsed['account'], '229')) {
            $fiat = 'XOF';
        } elseif ($fiat === 'NGN' && str_starts_with($parsed['account'], '228')) {
            $fiat = 'XOF';
        }

        if ($fiat === 'XOF') {
            $country = str_starts_with($parsed['account'], '229') ? 'BJ' : 'TG';
            $validNets = $country === 'BJ' ? ['MTN', 'MOOV'] : ['TMONEY', 'MOOV', 'MIXX', 'MIIX', 'YAS', 'TOGOCOM', 'MTN'];

            $matchedNet = null;
            if (in_array($guessUpper, $validNets, true)) {
                if ($guessUpper === 'TOGOCOM' || $guessUpper === 'T-MONEY') {
                    $matchedNet = 'TMONEY';
                } elseif ($guessUpper === 'YAS' || $guessUpper === 'MIIX') {
                    $matchedNet = 'MIXX';
                } else {
                    $matchedNet = $guessUpper;
                }
            } elseif (in_array($guessUpper, ['TMONEY MOMO', 'MOOV MOMO', 'MIXX MOMO', 'YAS MOMO', 'MTN MOMO'], true)) {
                $matchedNet = explode(' ', $guessUpper)[0];
                if ($matchedNet === 'YAS') {
                    $matchedNet = 'MIXX';
                }
            }

            if ($matchedNet === null) {
                $countryFlag = $country === 'BJ' ? '🇧🇯' : '🇹🇬';
                $countryLabel = $country === 'BJ' ? 'Benin' : 'Togo';
                $btnLabels = $country === 'BJ'
                    ? ['sell_xof_net_mtn' => 'MTN', 'sell_xof_net_moov' => 'Moov']
                    : ['sell_xof_net_tmoney' => 'TMoney', 'sell_xof_net_moov' => 'Moov', 'sell_xof_net_mixx' => 'Mixx by Yas'];

                $this->buttons(
                    $ctx,
                    "{$countryFlag} *XOF MoMo payout* — {$countryLabel}\n\nWhich MoMo network is *{$parsed['account']}* on?\n\nType *cancel* to stop.",
                    $btnLabels
                );

                return StepResult::transition('sell', 'network', [
                    'fiat' => 'XOF',
                    'target_receive_ngn' => $parsed['amount_ngn'],
                    'preset_account' => $parsed['account'],
                    'xof_country' => $country,
                ], ['action' => 'sell_xof_network_prompt']);
            }

            $parsed['bank_guess'] = ucfirst(strtolower($matchedNet)) . ' MoMo';
        }

        if ($kycBlock = KycGate::blockIfNeeded($ctx, (float) $parsed['amount_ngn'], [
            'flow' => 'sell',
            'step' => 'asset',
            'fiat' => $fiat,
            'target_receive_ngn' => $parsed['amount_ngn'],
            'preset_account' => $parsed['account'],
            'preset_bank_guess' => $parsed['bank_guess'],
        ])) {
            return $kycBlock;
        }

        $sym = match ($fiat) {
            'XOF' => 'CFA ',
            'KES' => 'KSh ',
            default => '₦',
        };

        $n = number_format((float) $parsed['amount_ngn'], 0, '.', ',');
        $this->buttons(
            $ctx,
            "Send *≈ {$sym}{$n}* to `{$parsed['account']}` (*{$parsed['bank_guess']}*).\n\nWhich balance should we use?",
            [
                'sell_pick_usdt' => 'USDT',
                'sell_pick_usdc' => 'USDC',
            ]
        );

        return StepResult::transition('sell', 'asset', [
            'fiat' => $fiat,
            'target_receive_ngn' => $parsed['amount_ngn'],
            'preset_account' => $parsed['account'],
            'preset_bank_guess' => $parsed['bank_guess'],
        ], ['action' => 'sell_send_naira_pick']);
    }
}
