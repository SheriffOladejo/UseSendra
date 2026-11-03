<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Commands\CommandSupport;
use AptosV2\Support\StellarAddress;
use AptosV2\Support\WalletChoice;

/**
 * Native Aptos/Stellar receive, or 1Click from Solana/Base/Arbitrum.
 */
final class NetworkStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if ($ctx->messageLower === 'wallet_aptos' || $ctx->messageLower === 'dep_net_aptos'
            || $ctx->messageLower === 'aptos') {
            return $this->showAptosAddress($ctx);
        }

        if ($ctx->messageLower === 'wallet_arc' || $ctx->messageLower === 'dep_net_arc'
            || $ctx->messageLower === 'arc') {
            return $this->showArcAddress($ctx);
        }

        if ($ctx->messageLower === 'wallet_stellar' || $ctx->messageLower === 'dep_net_stellar'
            || $ctx->messageLower === 'stellar') {
            return $this->showStellarAddress($ctx);
        }

        $network = null;
        if ($ctx->messageLower === 'dep_net_other' || $ctx->messageLower === 'other' || str_contains($ctx->messageLower, 'other network')) {
            $this->buttons(
                $ctx,
                'Which network would you like to deposit from?',
                [
                    'dep_net_solana'   => 'Solana',
                    'dep_net_base'     => 'Base',
                    'dep_net_arbitrum' => 'Arbitrum',
                ]
            );

            return StepResult::stay(['action' => 'deposit_other_network']);
        }
        if ($ctx->messageLower === 'dep_net_solana' || str_contains($ctx->messageLower, 'solana')) {
            $network = 'Solana';
        } elseif ($ctx->messageLower === 'dep_net_base' || str_contains($ctx->messageLower, 'base')) {
            $network = 'Base';
        } elseif ($ctx->messageLower === 'dep_net_arbitrum' || str_contains($ctx->messageLower, 'arbitrum')) {
            $network = 'Arbitrum';
        }

        if ($network === null) {
            $this->buttons(
                $ctx,
                'Which wallet do you want to use?',
                [
                    'wallet_aptos' => 'Aptos',
                    'wallet_stellar' => 'Stellar',
                    'wallet_arc' => 'Arc',
                    'dep_net_other' => 'Other network',
                ]
            );

            return StepResult::stay(['action' => 'deposit_network_reprompt']);
        }

        $this->buttons(
            $ctx,
            "Where should your *{$network}* deposit go?",
            [
                'dep_dest_wallet' => '💳 Sendra Wallet',
                'dep_dest_bank'   => '🏦 Bank Account',
            ]
        );

        return StepResult::transition(
            'deposit',
            'destination',
            ['deposit_network' => $network],
            ['action' => 'deposit_destination_prompt', 'network' => $network]
        );
    }

    private function showAptosAddress(StepContext $ctx): StepResult
    {
        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found. Type *hi* to create one.");

            return StepResult::complete(['action' => 'deposit_receive', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, '💳 *Your Aptos Deposit Address*');
        usleep(300000);
        $this->reply($ctx, $wallet);
        usleep(300000);
        $this->reply(
            $ctx,
            "*📋 Instructions:*\n" .
            "• Copy the address above\n" .
            "• Share it with your sender\n" .
            "• Send on the *Aptos network* only (USDC/USDT)\n" .
            "• You'll be notified here when funds arrive"
        );

        return StepResult::complete(['action' => 'deposit_receive']);
    }

    private function showStellarAddress(StepContext $ctx): StepResult
    {
        $wallet = StellarAddress::fromUser($ctx->user);
        if ($wallet === null) {
            return WalletChoice::missingStellar($ctx);
        }

        $memo = \AptosV2\Support\StellarReceive::memoFromUser($ctx->user);
        if ($memo === null) {
            /** @var \AptosV2\Support\StellarWalletService $svc */
            $svc = $ctx->services->get('stellar_wallet');
            $out = $svc->provision($ctx->from, $ctx->user);
            $memo = isset($out['stellar_deposit_memo']) ? (string) $out['stellar_deposit_memo'] : null;
        }

        $this->reply($ctx, '💳 *Your Stellar deposit*');
        usleep(250000);
        \AptosV2\Support\StellarReceive::sendCopyableDeposit($ctx->whatsapp(), $ctx->from, $memo);

        return StepResult::complete(['action' => 'deposit_receive_stellar']);
    }

    private function showArcAddress(StepContext $ctx): StepResult
    {
        $wallet = \AptosV2\Support\ArcAddress::fromUser($ctx->user);
        if ($wallet === null) {
            /** @var \AptosV2\Support\ArcWalletService $svc */
            $svc = $ctx->services->get('arc_wallet');
            $out = $svc->provision($ctx->from, $ctx->user);
            $wallet = isset($out['arc_address']) ? (string) $out['arc_address'] : '';
            if ($wallet === '' || empty($out['success'])) {
                return WalletChoice::missingArc($ctx);
            }
        }

        $this->reply($ctx, '💳 *Your Arc Deposit Address*');
        usleep(250000);
        $this->reply($ctx, $wallet);
        usleep(250000);
        $this->reply(
            $ctx,
            "*📋 Instructions:*\n" .
            "• Copy the address above\n" .
            "• Send *USDC on Arc* only\n" .
            "• Wrong network = lost funds\n" .
            "• You'll be notified here when funds arrive"
        );

        return StepResult::complete(['action' => 'deposit_receive_arc']);
    }
}
