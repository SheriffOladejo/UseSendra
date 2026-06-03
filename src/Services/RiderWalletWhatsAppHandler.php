<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LogisticsDriver;
use App\Models\RiderWalletTransaction;
use App\Models\Session;
use App\Utils\NameMatchHelper;
use PDO;

/**
 * Rider wallet commands: balance, fund, withdraw (WhatsApp logistics bot).
 */
final class RiderWalletWhatsAppHandler
{
    private RiderWalletService $wallet;
    private CentiivDirectPayService $directPay;
    private LogisticsRiderCentiivService $centiivBank;

    public function __construct(
        private PDO $db,
        private WhatsAppService $whatsapp,
        private Session $sessionModel
    ) {
        $driverModel = new LogisticsDriver($db);
        $this->wallet = new RiderWalletService($db, $driverModel, new RiderWalletTransaction($db));
        $this->directPay = new CentiivDirectPayService();
        $this->centiivBank = new LogisticsRiderCentiivService();
    }

    public function tryHandle(string $phone, string $messageLower, array $session, array $driver): bool
    {
        $step = (string) ($session['current_step'] ?? '');

        if (in_array($step, ['rider_wallet_fund_amount', 'rider_wallet_withdraw_amount', 'rider_wallet_withdraw_bank'], true)) {
            return $this->handleWalletStep($phone, trim($messageLower), $session, $driver);
        }

        if (
            in_array($messageLower, ['balance', 'wallet balance', 'check balance'], true)
            || $messageLower === 'wallet_balance'
        ) {
            $bal = $this->wallet->getBalanceNgn((int) $driver['id']);
            $hint = $bal < 0
                ? "\n\n⚠️ Balance is negative — type *fund* before you accept new jobs."
                : "\n\n_Type *history* for recent wallet & job activity._";
            $this->whatsapp->sendMessage(
                $phone,
                '💰 *Balance:* ₦' . number_format($bal, 0) . $hint
            );

            return true;
        }

        if (
            in_array($messageLower, ['history', 'transactions', 'activity', 'wallet history'], true)
            || $messageLower === 'wallet_history'
        ) {
            $summary = (new RiderTransactionHistoryService($this->db))
                ->formatWhatsAppSummary((int) $driver['id'], 12);
            $this->whatsapp->sendMessage($phone, $summary);

            return true;
        }

        if (
            in_array($messageLower, ['fund', 'top up', 'topup', 'add money', 'wallet_fund'], true)
        ) {
            $this->sessionModel->updateStep($phone, 'rider_wallet_fund_amount');
            $this->whatsapp->sendMessage($phone, 'How much will you like to add to your wallet?');

            return true;
        }

        if (
            in_array($messageLower, ['withdraw', 'cash out', 'cashout', 'wallet_withdraw'], true)
        ) {
            if ($this->wallet->getBalanceNgn((int) $driver['id']) <= 0) {
                $this->whatsapp->sendMessage(
                    $phone,
                    '⚠️ Your wallet balance is too low to withdraw. Type *fund* to add money first.'
                );

                return true;
            }
            $this->sessionModel->updateStep($phone, 'rider_wallet_withdraw_amount');
            $this->whatsapp->sendMessage($phone, 'How much will you like to withdraw?');

            return true;
        }

        return false;
    }

    private function handleWalletStep(string $phone, string $raw, array $session, array $driver): bool
    {
        $step = (string) ($session['current_step'] ?? '');
        $driverId = (int) $driver['id'];

        if (in_array($raw, ['cancel', 'stop', 'menu', 'hi'], true)) {
            $this->sessionModel->updateStep($phone, 'rider_ready');
            $this->whatsapp->sendMessage($phone, '✅ Wallet action cancelled. Say *hi* for the rider menu.');

            return true;
        }

        if ($step === 'rider_wallet_fund_amount') {
            $amount = $this->parsePositiveAmount($raw);
            if ($amount === null || $amount < 100) {
                $this->whatsapp->sendMessage($phone, '⚠️ Enter a valid amount in NGN (minimum ₦100), numbers only.');

                return true;
            }
            $this->startFundPayment($phone, $driverId, $amount);
            $this->sessionModel->updateStep($phone, 'rider_ready');

            return true;
        }

        if ($step === 'rider_wallet_withdraw_amount') {
            $amount = $this->parsePositiveAmount($raw);
            if ($amount === null || $amount < 100) {
                $this->whatsapp->sendMessage($phone, '⚠️ Enter a valid amount (minimum ₦100), numbers only.');

                return true;
            }
            $bal = $this->wallet->getBalanceNgn($driverId);
            if ($bal < $amount) {
                $this->whatsapp->sendMessage(
                    $phone,
                    '⚠️ Insufficient balance. Your balance is ₦' . number_format($bal, 0) . '. Type *balance* to check.'
                );
                $this->sessionModel->updateStep($phone, 'rider_ready');

                return true;
            }
            $this->setWalletCtx($phone, ['withdraw_amount' => $amount]);
            $this->sessionModel->updateStep($phone, 'rider_wallet_withdraw_bank');
            $this->whatsapp->sendMessage(
                $phone,
                "Reply with your account and bank:\n`0227912723 moniepoint`\nor `0227912723 kredi money`\n\n(10-digit account, then bank name — can be more than one word.)"
            );

            return true;
        }

        if ($step === 'rider_wallet_withdraw_bank') {
            $parsed = $this->parseAccountAndBank($raw);
            if ($parsed === null) {
                $this->whatsapp->sendMessage(
                    $phone,
                    '⚠️ Use: `0123456789 Bank Name` (10 digits, then bank name).'
                );

                return true;
            }
            $ctx = $this->getWalletCtx($phone);
            $amount = (float) ($ctx['withdraw_amount'] ?? 0);
            if ($amount < 100) {
                $this->sessionModel->updateStep($phone, 'rider_ready');
                $this->whatsapp->sendMessage($phone, '⚠️ Withdrawal expired. Type *withdraw* to start again.');

                return true;
            }
            $this->processWithdraw($phone, $driver, $amount, $parsed['account'], $parsed['bank']);
            $this->clearWalletCtx($phone);
            $this->sessionModel->updateStep($phone, 'rider_ready');

            return true;
        }

        return false;
    }

    private function startFundPayment(string $phone, int $driverId, float $amount): void
    {
        if (!$this->directPay->isConfigured()) {
            $this->whatsapp->sendMessage($phone, '⚠️ Payments are not configured. Contact support.');

            return;
        }

        $reference = 'RIDER_FUND_' . $driverId . '_' . uniqid();
        $publicBase = rtrim(
            (string) ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: 'https://usesendra.com'),
            '/'
        );
        $successUrl = $publicBase . '/CentiivPaymentReturn.php?outcome=success';
        $failureUrl = $publicBase . '/CentiivPaymentReturn.php?outcome=failure';

        $created = $this->directPay->createPaymentLink(
            $amount,
            $reference,
            [
                'request_id' => 0,
                'driver_id' => $driverId,
                'type' => 'rider_wallet_fund',
            ],
            $successUrl,
            $failureUrl
        );

        if ($created === null || ($created['url'] ?? '') === '') {
            $this->whatsapp->sendMessage($phone, '⚠️ Payment link failed. Try again.');

            return;
        }

        (new RiderWalletTransaction($this->db))->createPending([
            'driver_id' => $driverId,
            'type' => 'fund',
            'amount_ngn' => $amount,
            'balance_after_ngn' => $this->wallet->getBalanceNgn($driverId),
            'reference' => $reference,
            'metadata' => ['expected_amount' => $amount],
        ]);

        $this->whatsapp->sendMessage(
            $phone,
            '💳 Pay ₦' . number_format($amount, 0) . " to fund your wallet:\n" . $created['url']
        );
    }

    /**
     * @return array{account: string, bank: string}|null
     */
    private function parseAccountAndBank(string $line): ?array
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if (!preg_match('/^(\d{10})\s+(.+)$/u', $line, $m)) {
            return null;
        }

        return ['account' => $m[1], 'bank' => trim($m[2])];
    }

    private function processWithdraw(string $phone, array $driver, float $amount, string $account, string $bankGuess): void
    {
        if (!$this->centiivBank->isConfigured()) {
            $this->whatsapp->sendMessage($phone, '⚠️ Bank payouts are not configured. Contact support.');

            return;
        }

        $agg = $this->centiivBank->getRamp();
        $banksRes = $agg->getBanks();
        if (!$banksRes['success']) {
            $this->whatsapp->sendMessage($phone, '❌ Could not load bank list. Try again shortly.');

            return;
        }
        $banks = SendraCentiivRampService::normalizeBankList($banksRes['data']);
        $code = SendraCentiivRampService::resolveBankCode($bankGuess, $banks);
        if ($code === null) {
            $this->whatsapp->sendMessage(
                $phone,
                "❌ Could not match *{$bankGuess}* to a bank. Try again with the official name (e.g. *GTBank*, *Opay*, *Moniepoint*)."
            );

            return;
        }

        $this->whatsapp->sendMessage($phone, '⏳ Checking your account…');
        $verify = $agg->verifyBankAccount($code, $account);
        if (!$verify['success']) {
            $err = $verify['error'] ?? 'Verify failed';
            $this->whatsapp->sendMessage(
                $phone,
                "❌ Account could not be verified.\nCheck the 10 digits and bank name.\n({$err})"
            );

            return;
        }

        $vdata = is_array($verify['data']) ? $verify['data'] : [];
        $acctName = $this->pickAccountName($vdata);
        if ($acctName === null || $acctName === '') {
            $this->whatsapp->sendMessage($phone, '❌ Verification incomplete. Try different details.');

            return;
        }

        $profileFirst = (string) ($driver['first_name'] ?? '');
        $profileLast = (string) ($driver['last_name'] ?? '');
        if (!NameMatchHelper::profileMatchesBankAccountName($profileFirst, $profileLast, $acctName)) {
            $this->whatsapp->sendMessage(
                $phone,
                "❌ The bank account name does not match your rider profile (*{$profileFirst} {$profileLast}* vs *{$acctName}*).\n"
                . 'Use an account registered in your name, or contact support.'
            );

            return;
        }

        $reference = 'RIDER_WD_' . (int) $driver['id'] . '_' . uniqid();
        $reserve = $this->wallet->reserveWithdraw((int) $driver['id'], $amount, $reference);
        if (!$reserve['ok']) {
            $this->whatsapp->sendMessage(
                $phone,
                '⚠️ Could not reserve that amount. Type *balance* to check your wallet.'
            );

            return;
        }

        $resolved = SendraCentiivRampService::resolvedBankFromVerifyEnvelope($vdata);
        if ($resolved !== null && ($resolved['code'] ?? '') !== '') {
            $code = $resolved['code'];
        }
        $rawBankRow = SendraCentiivRampService::findRawBankRowInBanksResponse($banksRes['data'], $code);
        $bankDisplay = $resolved['name'] ?? $bankGuess;

        $beneficiary = SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
            $account,
            $code,
            $acctName,
            $bankDisplay,
            $rawBankRow,
            $vdata,
            'sendra_rider_' . (int) $driver['id']
        );

        $order = $this->centiivBank->createRiderWithdrawOrder(
            (int) $driver['id'],
            $phone,
            $amount,
            $beneficiary,
            $reference,
            ['account_number' => $account, 'bank_name' => $bankDisplay]
        );

        if (!$order['success']) {
            $this->wallet->releaseWithdrawReserve($reference);
            $this->whatsapp->sendMessage($phone, '❌ Withdrawal could not be started. Nothing was deducted — try again later.');

            return;
        }

        $this->whatsapp->sendMessage(
            $phone,
            '⏳ Your ₦' . number_format($amount, 0) . " withdrawal to *{$acctName}* (`{$account}`) has been submitted.\n"
            . "We'll message you when the bank transfer completes."
        );
    }

    /**
     * @param array<string, mixed> $vdata
     */
    private function pickAccountName(array $vdata): ?string
    {
        $d = $vdata['data'] ?? $vdata;
        if (!is_array($d)) {
            $d = $vdata;
        }
        foreach (['accountName', 'account_name', 'accountHolderName', 'name'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) {
                return $d[$k];
            }
        }

        return null;
    }

    private function parsePositiveAmount(string $raw): ?float
    {
        $raw = preg_replace('/[^0-9.]/', '', $raw) ?? '';
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $n = (float) $raw;

        return $n > 0 ? $n : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getWalletCtx(string $phone): array
    {
        $session = $this->sessionModel->findByPhone($phone);
        if (!$session) {
            return [];
        }
        $raw = $session['data'] ?? ($session['session_data'] ?? '{}');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $j = json_decode($raw, true);

        return is_array($j) && isset($j['wallet_ctx']) && is_array($j['wallet_ctx']) ? $j['wallet_ctx'] : [];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function setWalletCtx(string $phone, array $ctx): void
    {
        $session = $this->sessionModel->findByPhone($phone);
        $raw = $session['data'] ?? ($session['session_data'] ?? '{}');
        $all = [];
        if (is_string($raw) && trim($raw) !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $all = $j;
            }
        }
        $all['wallet_ctx'] = array_merge($this->getWalletCtx($phone), $ctx);
        $json = json_encode($all, JSON_UNESCAPED_UNICODE) ?: '{}';
        if ($session && array_key_exists('data', $session)) {
            $this->sessionModel->update($phone, ['data' => $json]);
        } else {
            $this->sessionModel->update($phone, ['session_data' => $json]);
        }
    }

    private function clearWalletCtx(string $phone): void
    {
        $this->setWalletCtx($phone, []);
    }
}
