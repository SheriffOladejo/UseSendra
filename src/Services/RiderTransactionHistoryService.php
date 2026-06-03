<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompletedDeliveryOrder;
use App\Models\DriverAcceptanceFeePayment;
use App\Models\RiderWalletTransaction;
use PDO;

/**
 * Unified rider activity: wallet ledger, legacy acceptance fees, completed jobs.
 */
final class RiderTransactionHistoryService
{
    public function __construct(private PDO $db)
    {
    }

    public function formatWhatsAppSummary(int $driverId, int $limit = 12): string
    {
        $entries = $this->collectEntries($driverId, $limit);
        if ($entries === []) {
            return "📜 *No activity yet*\n\n"
                . "Wallet top-ups, job fees, and completed deliveries will show here.\n"
                . 'Type *fund* to add money to your wallet.';
        }

        $lines = ["📜 *Recent activity* (latest " . min($limit, count($entries)) . ")\n"];
        foreach ($entries as $e) {
            $lines[] = $this->formatLine($e);
        }
        $lines[] = "\n_Type *history* anytime to refresh._";

        return implode("\n", $lines);
    }

    /**
     * @return list<array{sort_ts:int, line:string}>
     */
    private function collectEntries(int $driverId, int $limit): array
    {
        $walletModel = new RiderWalletTransaction($this->db);
        $entries = [];

        foreach ($walletModel->listRecentForDriver($driverId, $limit * 2) as $row) {
            $entries[] = $this->entryFromWalletRow($row);
        }

        $feeModel = new DriverAcceptanceFeePayment($this->db);
        foreach ($feeModel->listRecentForDriverExcludingChannel($driverId, 'rider_wallet', $limit) as $row) {
            $entries[] = $this->entryFromFeePaymentRow($row);
        }

        $completed = new CompletedDeliveryOrder($this->db);
        foreach ($completed->listRecentForDriver($driverId, $limit) as $row) {
            $entries[] = $this->entryFromCompletedJob($row);
        }

        usort($entries, static fn ($a, $b) => $b['sort_ts'] <=> $a['sort_ts']);

        return array_slice($entries, 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sort_ts:int, line:string}
     */
    private function entryFromWalletRow(array $row): array
    {
        $type = (string) ($row['type'] ?? '');
        $amount = (float) ($row['amount_ngn'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $reqId = (int) ($row['delivery_request_id'] ?? 0);
        $ts = $this->rowTimestamp($row, 'completed_at', 'created_at');

        $icon = match ($status) {
            'pending' => '⏳',
            'failed', 'cancelled' => '❌',
            default => $amount >= 0 ? '➕' : '➖',
        };

        $label = match ($type) {
            'fund' => 'Wallet top-up',
            'withdraw' => 'Withdrawal',
            'acceptance_fee' => $reqId > 0 ? "Job fee (#{$reqId})" : 'Job acceptance fee',
            default => ucfirst(str_replace('_', ' ', $type)),
        };

        $amtStr = ($amount >= 0 ? '+' : '') . '₦' . number_format(abs($amount), 0);
        $bal = isset($row['balance_after_ngn']) && $status === 'completed'
            ? ' · bal ₦' . number_format((float) $row['balance_after_ngn'], 0)
            : '';
        $statusNote = $status === 'pending' ? ' (pending)' : ($status === 'failed' ? ' (failed)' : '');

        return [
            'sort_ts' => $ts,
            'line' => "{$icon} {$label} {$amtStr}{$statusNote}{$bal} · " . $this->formatShortDate($ts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sort_ts:int, line:string}
     */
    private function entryFromFeePaymentRow(array $row): array
    {
        $amountKobo = (int) ($row['amount_kobo'] ?? 0);
        $amount = $amountKobo / 100;
        $reqId = (int) ($row['delivery_request_id'] ?? 0);
        $channel = (string) ($row['channel'] ?? 'payment');
        $ts = $this->rowTimestamp($row, 'paid_at', 'created_at');

        $label = $reqId > 0 ? "Job fee (#{$reqId})" : 'Job acceptance fee';
        $via = $channel !== '' && $channel !== 'rider_wallet' ? ' · ' . $channel : '';

        return [
            'sort_ts' => $ts,
            'line' => '➖ ' . $label . ' -₦' . number_format($amount, 0) . $via
                . ' · ' . $this->formatShortDate($ts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sort_ts:int, line:string}
     */
    private function entryFromCompletedJob(array $row): array
    {
        $reqId = (int) ($row['original_request_id'] ?? 0);
        $price = (float) ($row['offered_price'] ?? 0);
        $ts = $this->rowTimestamp($row, 'created_at', 'created_at');

        return [
            'sort_ts' => $ts,
            'line' => '✅ Delivered job #' . $reqId . ' · ₦' . number_format($price, 0)
                . ' · ' . $this->formatShortDate($ts),
        ];
    }

    /**
     * @param array{sort_ts:int, line:string} $e
     */
    private function formatLine(array $e): string
    {
        return $e['line'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowTimestamp(array $row, string $primary, string $fallback): int
    {
        foreach ([$primary, $fallback] as $col) {
            $v = $row[$col] ?? null;
            if (is_string($v) && $v !== '') {
                $t = strtotime($v);
                if ($t !== false) {
                    return $t;
                }
            }
        }

        return 0;
    }

    private function formatShortDate(int $ts): string
    {
        if ($ts < 1) {
            return '—';
        }

        return date('j M, g:ia', $ts);
    }
}
