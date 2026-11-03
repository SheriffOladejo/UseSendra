<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use App\Services\SendraCentiivRampService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Commands\CommandSupport;

/**
 * Direct Bank Offramp — collects/verifies payout bank account details.
 */
final class BankDetailsStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $network = (string) ($ctx->state['deposit_network'] ?? 'Solana');
        $asset = (string) ($ctx->state['deposit_asset'] ?? 'USDC');
        $amount = (float) ($ctx->state['deposit_amount'] ?? 0);
        $savedAcctNum = (string) ($ctx->state['saved_acct_num'] ?? '');
        $savedBankCode = (string) ($ctx->state['saved_bank_code'] ?? '');
        $savedAcctName = (string) ($ctx->state['saved_acct_name'] ?? '');
        $savedBankName = (string) ($ctx->state['saved_bank_name'] ?? '');

        $rawMsg = trim($ctx->message);

        $acctNum = null;
        $bankQuery = null;
        $bankName = null;

        if (strtolower($rawMsg) === 'yes' && $savedAcctNum !== '') {
            $acctNum = $savedAcctNum;
            $bankCode = $savedBankCode;
            $acctName = $savedAcctName;
            $bankName = $savedBankName;
        } elseif (preg_match('/^(\d{10})\s+(.+)$/u', $rawMsg, $m) === 1) {
            $acctNum = $m[1];
            $bankQuery = trim($m[2]);
        }

        if ($acctNum === null) {
            $this->reply(
                $ctx,
                "Please enter a valid 10-digit NGN account number and bank name.\n\n" .
                "Example: *0227912723 GTBank* or *0123456789 Kuda*\n\n" .
                "*cancel* stops."
            );

            return StepResult::stay(['action' => 'deposit_bank_details_invalid']);
        }

        $centiiv = new SendraCentiivRampService();

        // Resolve bank if new bank details passed
        if ($acctName === null || $bankCode === null) {
            $this->reply($ctx, "🔍 Verifying bank account details...");

            $banksRes = $centiiv->getBanks();
            if (!$banksRes['success'] || !is_array($banksRes['data'] ?? null)) {
                $this->reply($ctx, "❌ Bank lookup service unavailable. Please try again.");

                return StepResult::stay(['action' => 'deposit_bank_lookup_failed']);
            }

            $matchedBank = SendraCentiivRampService::findBankRowByNameOrCode($banksRes['data'], $bankQuery);
            if ($matchedBank === null) {
                $this->reply($ctx, "❌ Could not match bank name '{$bankQuery}'. Please specify bank clearly (e.g. *0227912723 GTBank* or *0123456789 Access*).");

                return StepResult::stay(['action' => 'deposit_bank_not_found']);
            }

            $bankCode = (string) ($matchedBank['code'] ?? $matchedBank['bankCode'] ?? '');
            $bankName = (string) ($matchedBank['name'] ?? $bankQuery);

            // NIBSS live bank account verification ($bankCode first, $acctNum second)
            $verifyRes = $centiiv->verifyBankAccount($bankCode, $acctNum);
            $vData = $verifyRes['data']['data'] ?? $verifyRes['data'] ?? [];
            $acctName = trim((string) ($vData['accountName'] ?? $vData['account_name'] ?? ''));
            $acctName = preg_replace('/\s+/', ' ', $acctName);

            if (empty($verifyRes['success']) || $acctName === '') {
                $errDetail = $verifyRes['error'] ?? 'Account number not found on this bank';
                $this->reply(
                    $ctx,
                    "❌ Could not verify account number *{$acctNum}* with *{$bankName}*.\n\n" .
                    "Reason: {$errDetail}\n\n" .
                    "Please check your account number and bank name (e.g. *0227912723 GTBank* or *0123456789 Kuda*)."
                );

                return StepResult::stay(['action' => 'deposit_bank_verify_failed']);
            }
        }

        $this->reply($ctx, "⏳ Generating deposit address for bank payout to *{$acctName}* ({$bankName})...");

        // Estimate Naira payout
        $quoteRes = $centiiv->getQuote([
            'fromAsset' => $asset,
            'toAsset' => 'NGN',
            'amount' => $amount,
        ]);

        $estNairaStr = '0';
        if (!empty($quoteRes['success']) && is_array($quoteRes['data'] ?? null)) {
            $sum = SendraCentiivRampService::summarizeQuoteNgn($quoteRes['data'], $amount);
            $estNairaStr = number_format((float) ($sum['receive_ngn'] ?? 0), 0, '.', ',');
        }

        $refundAddress = match (strtolower($network)) {
            'solana', 'sol' => (string) ($_ENV['SENDRA_SOLANA_REFUND_ADDRESS'] ?? getenv('SENDRA_SOLANA_REFUND_ADDRESS') ?: 'Dr2PgL5XTSjBsG8RjJE82dceaMKdxZFica3CrXbT3o8z'),
            'stellar' => (string) ($_ENV['SENDRA_STELLAR_REFUND_ADDRESS'] ?? getenv('SENDRA_STELLAR_REFUND_ADDRESS') ?: 'GBAZ5QM4W2MGGCTWAMUN6L3MDIR2BY2JSGPZFVSGJ5ZVVVFYNPCB764U'),
            'base', 'arbitrum', 'arb' => (string) ($_ENV['SENDRA_EVM_REFUND_ADDRESS'] ?? getenv('SENDRA_EVM_REFUND_ADDRESS') ?: '0xc0020ef44160fabaeee6ae40a26eb3760af58c3f'),
            default => '0xc0020ef44160fabaeee6ae40a26eb3760af58c3f',
        };

        // Create order with native network specified (Centiiv natively handles Base, Solana, Arbitrum, Stellar)
        $orderBody = [
            'fromAsset' => $asset,
            'toAsset' => 'NGN',
            'amount' => $amount,
            'network' => strtoupper($network),
            'refundAddress' => $refundAddress,
            'description' => "Sendra Direct Cashout {$amount} {$asset} on {$network}",
            'beneficiary' => SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
                $acctNum,
                $bankCode,
                $acctName,
                $bankName,
                null,
                null,
                SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($ctx->from)
            ),
            'metadata' => [
                'user_phone' => $ctx->from,
                'source' => 'sendra_whatsapp',
                'deposit_network' => strtolower($network),
            ],
        ];

        $orderRes = $centiiv->createConversionOrder($orderBody);
        $orderData = $orderRes['data'] ?? [];

        $centiivDepositAddress = $orderData['temporaryWallet']['publicAddress']
            ?? $orderData['depositAddress']
            ?? $orderData['address']
            ?? null;

        if ($centiivDepositAddress === null || $centiivDepositAddress === '') {
            $this->reply($ctx, "❌ Direct bank cash-out address is currently unavailable. Please try Sendra Wallet deposit instead.");

            return StepResult::complete(['action' => 'deposit_bank_order_failed']);
        }

        $depositAddress = $centiivDepositAddress;
        $depositMemo = $orderData['temporaryWallet']['memo']
            ?? $orderData['memo']
            ?? $orderData['destinationTag']
            ?? null;

        // If network is Aptos (Sendra native), bridge Aptos -> Centiiv deposit address via 1Click
        if (strtolower($network) === 'aptos') {
            $oneclick = new SendraOneClickService();
            $decimals = 6;
            $amountSmallest = (string) (int) round(((float) $amount) * (10 ** $decimals));

            $quoteBody = SendraOneClickService::buildCrossChainToStellarQuoteBody(
                'aptos',
                $asset,
                $amountSmallest,
                $centiivDepositAddress
            );

            $oneclickRes = $oneclick->requestQuote($quoteBody);
            $qData = $oneclickRes['data']['quote'] ?? $oneclickRes['data'] ?? [];
            $oneclickAddress = $qData['depositAddress'] ?? null;
            $oneclickMemo = $qData['depositMemo'] ?? null;

            if (!empty($oneclickRes['success']) && $oneclickAddress !== null && $oneclickAddress !== '') {
                $depositAddress = $oneclickAddress;
                $depositMemo = $oneclickMemo;
            } else {
                error_log("BankDetailsStep: 1Click quote failed for network {$network}: " . json_encode($oneclickRes));
            }
        }

        // Save pending transaction
        try {
            $stmt = $ctx->db->prepare("
                INSERT INTO pending_transactions 
                (user_id, tx_type, amount, currency, status, created_at, human_description)
                VALUES (?, 'deposit_centiiv_direct_offramp', ?, ?, 'pending', NOW(), ?)
            ");
            $stmt->execute([
                $ctx->user['Id'] ?? $ctx->user['id'],
                $amount,
                $asset,
                json_encode([
                    'network' => $network,
                    'deposit_address' => $depositAddress,
                    'deposit_memo' => $depositMemo,
                    'account_number' => $acctNum,
                    'bank_code' => $bankCode,
                    'account_name' => $acctName,
                    'bank_name' => $bankName,
                    'estimated_naira' => $estNairaStr,
                    'aptos_v2' => true,
                    'origin_whatsapp_phone_id' => $ctx->phoneId,
                ]),
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to save deposit_centiiv_direct_offramp pending row: ' . $e->getMessage());
        }

        // Summary message
        $summaryText =
            "🏦 *Direct Bank Cash-out*\n\n" .
            "Send *{$amount} {$asset}* on *{$network}* directly to the deposit address below.\n\n" .
            "👤 *Payout Account:* {$bankName} — {$acctNum} ({$acctName})\n" .
            "💰 *You'll Receive:* ~₦{$estNairaStr}\n" .
            "⏱️ *Order Window:* 30 minutes";

        $this->reply($ctx, $summaryText);

        // Standalone Address Message for 1-Tap Copy
        $this->reply($ctx, $depositAddress);

        // Standalone Memo Message for 1-Tap Copy (if memo exists)
        if ($depositMemo !== null && $depositMemo !== '') {
            $this->reply($ctx, "📌 *Memo / Destination Tag:*");
            $this->reply($ctx, $depositMemo);
        }

        // Instructions message
        $instructionsText =
            "⚠️ *Important:* Send only *{$asset}* on *{$network}*, and send the *exact amount* (*{$amount} {$asset}*) to the address above.\n" .
            "A different token or amount may not credit.\n\n" .
            "📋 *Instructions:*\n" .
            "• Copy the address above\n" .
            "• Transfer on the *{$network} Network* only\n" .
            "• Bank payout will process automatically once transfer is confirmed";

        $this->reply($ctx, $instructionsText);

        return StepResult::complete(['action' => 'deposit_bank_order_created', 'address' => $depositAddress]);
    }
}
