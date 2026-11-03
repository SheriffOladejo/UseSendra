<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Collects bank cash-out amount and transitions to bank details verification.
 */
final class BankOfframpStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $network = (string) ($ctx->state['deposit_network'] ?? 'Solana');
        $asset = (string) ($ctx->state['deposit_asset'] ?? 'USDC');
        $rawMsg = trim($ctx->message);

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $rawMsg, $m) !== 1) {
            $this->reply(
                $ctx,
                "Please enter a valid numeric amount to convert to Naira (e.g. *50* or *300*).\n\n" .
                "*cancel* stops."
            );

            return StepResult::stay(['action' => 'deposit_bank_amount_invalid']);
        }

        $amount = (float) $m[1];
        if ($amount < 2.0) {
            $this->reply($ctx, "❌ Minimum bank cash-out amount is *2 {$asset}*. Please enter a larger amount.");

            return StepResult::stay(['action' => 'deposit_bank_amount_below_min']);
        }

        // Check if user has a saved bank account
        $bankRow = null;
        try {
            $stmt = $ctx->db->prepare("SELECT account_number, bank_code, account_name, bank_name FROM pending_offramps WHERE user_phone = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$ctx->from]);
            $bankRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('Failed to fetch user bank details for BankOfframpStep: ' . $e->getMessage());
        }

        $nextState = [
            'deposit_network' => $network,
            'deposit_asset' => $asset,
            'deposit_amount' => $amount,
        ];

        if (is_array($bankRow) && !empty($bankRow['account_number']) && !empty($bankRow['account_name'])) {
            $acctNum = (string) $bankRow['account_number'];
            $bankCode = (string) $bankRow['bank_code'];
            $acctName = (string) $bankRow['account_name'];
            $bankName = (string) $bankRow['bank_name'];

            $nextState['saved_acct_num'] = $acctNum;
            $nextState['saved_bank_code'] = $bankCode;
            $nextState['saved_acct_name'] = $acctName;
            $nextState['saved_bank_name'] = $bankName;

            $this->reply(
                $ctx,
                "🏦 *Payout Bank Account:*\n" .
                "{$bankName} — `{$acctNum}` ({$acctName})\n\n" .
                "Type *YES* to use this bank, or reply with your account number and bank name (e.g. *0123456789 Kuda*)."
            );
        } else {
            $this->reply(
                $ctx,
                "🏦 *Payout Bank Account:*\n" .
                "Please enter your 10-digit NGN account number and bank name for your payout.\n\n" .
                "Example: *0123456789 GTBank* or *0123456789 Kuda*\n\n" .
                "*cancel* stops."
            );
        }

        return StepResult::transition(
            'deposit',
            'bank_details',
            $nextState,
            ['action' => 'deposit_bank_details_prompt']
        );
    }
}
