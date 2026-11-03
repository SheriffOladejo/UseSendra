<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

/**
 * Human-readable summary for confirm-pin-v2.php.
 */
final class PinConfirmSummary
{
    /**
     * @param array<string, mixed> $pending
     * @return array{title: string, subtitle: string, rows: list<array{label: string, value: string}>}
     */
    public static function describe(array $pending): array
    {
        $txType = (string) ($pending['tx_type'] ?? 'transfer');
        $amount = (string) ($pending['amount'] ?? '');
        $currency = strtoupper((string) ($pending['currency'] ?? ''));
        $meta = json_decode((string) ($pending['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $rows = [];
        $title = 'Confirm transaction';
        $subtitle = 'Review the details below, then enter your PIN.';

        if ($txType === 'transfer') {
            $recipient = (string) ($pending['recipient_address'] ?? '');
            $short = strlen($recipient) > 16
                ? substr($recipient, 0, 8) . '…' . substr($recipient, -4)
                : $recipient;
            $rows[] = ['label' => 'Action', 'value' => 'Send crypto'];
            $rows[] = ['label' => 'Amount', 'value' => trim($amount . ' ' . $currency)];
            $rows[] = ['label' => 'To', 'value' => $short];
        } elseif ($txType === 'voucher_escrow_fund') {
            $title = 'Confirm voucher';
            $subtitle = 'Enter your PIN to lock these funds in Sendra escrow.';
            $rows[] = ['label' => 'Action', 'value' => 'Create voucher'];
            $rows[] = ['label' => 'Amount', 'value' => trim($amount . ' ' . $currency)];
            $rows[] = ['label' => 'Recipient', 'value' => (string) ($meta['recipient_phone'] ?? 'Unknown')];
            $rows[] = ['label' => 'Escrow', 'value' => '48-hour auto refund if unclaimed'];
        } elseif (str_contains($txType, 'offramp') || ($meta['kind'] ?? '') !== '') {
            $title = 'Confirm payout';
            $subtitle = 'Enter your PIN to authorize this cash-out from your wallet.';
            $recvNgn = (string) ($meta['receive_ngn_display'] ?? '');
            if ($recvNgn !== '' && $recvNgn !== '—') {
                $rows[] = ['label' => 'You receive', 'value' => '≈ ₦' . $recvNgn];
            }
            $rows[] = ['label' => 'Wallet debit', 'value' => trim($amount . ' ' . $currency)];
            $name = trim((string) ($meta['account_name'] ?? ''));
            $acct = trim((string) ($meta['account_number'] ?? ''));
            $bank = trim((string) ($meta['bank_name'] ?? ''));
            if ($name !== '') {
                $rows[] = ['label' => 'Account name', 'value' => $name];
            }
            if ($acct !== '') {
                $rows[] = ['label' => 'Account', 'value' => $acct];
            }
            if ($bank !== '') {
                $rows[] = ['label' => 'Bank', 'value' => $bank];
            }
        } elseif ($txType === 'panora_wallet_swap') {
            $title = 'Confirm swap';
            $subtitle = 'Enter your PIN to swap assets in your Sendra wallet.';
            $fromAsset = strtoupper((string) ($meta['from_asset'] ?? $currency));
            $toAsset = strtoupper((string) ($meta['to_asset'] ?? ''));
            $rows[] = ['label' => 'Swap', 'value' => trim($amount . ' ' . $fromAsset) . ' → ' . $toAsset];
            $estOut = (string) ($meta['expected_out'] ?? '');
            if ($estOut !== '') {
                $rows[] = ['label' => 'Estimated output', 'value' => '≈ ' . $estOut . ' ' . $toAsset];
            }
        } else {
            $rows[] = ['label' => 'Amount', 'value' => trim($amount . ' ' . $currency)];
            $rows[] = ['label' => 'Type', 'value' => $txType];
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'rows' => $rows,
        ];
    }
}
