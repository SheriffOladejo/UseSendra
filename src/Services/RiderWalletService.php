<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LogisticsDriver;
use App\Models\RiderWalletTransaction;
use PDO;

/**
 * Rider NGN wallet: fund, withdraw, acceptance-fee debit with one-time negative grace.
 */
final class RiderWalletService
{
    public function __construct(
        private PDO $db,
        private LogisticsDriver $driverModel,
        private RiderWalletTransaction $ledger
    ) {
    }

    public function getBalanceNgn(int $driverId): float
    {
        $driver = $this->driverModel->findById($driverId);
        if (!$driver) {
            return 0.0;
        }

        return (float) ($driver['wallet_balance_ngn'] ?? 0);
    }

    public function hasNegativeBalance(int $driverId): bool
    {
        return $this->getBalanceNgn($driverId) < 0;
    }

    /**
     * @return array{ok:bool, balance:float, grace_used:bool, error?:string}
     */
    public function chargeAcceptanceFee(int $driverId, float $feeNgn, int $requestId, string $reference): array
    {
        if ($feeNgn <= 0) {
            return ['ok' => true, 'balance' => $this->getBalanceNgn($driverId), 'grace_used' => false];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT wallet_balance_ngn, wallet_grace_used FROM drivers WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();

                return ['ok' => false, 'balance' => 0.0, 'grace_used' => false, 'error' => 'driver_not_found'];
            }

            $balance = (float) ($row['wallet_balance_ngn'] ?? 0);
            $graceUsed = (int) ($row['wallet_grace_used'] ?? 0) === 1;
            $newBalance = $balance - $feeNgn;
            $usedGrace = false;

            if ($balance < $feeNgn) {
                if ($graceUsed) {
                    $this->db->rollBack();

                    return [
                        'ok' => false,
                        'balance' => $balance,
                        'grace_used' => false,
                        'error' => 'insufficient_balance',
                    ];
                }
                $usedGrace = true;
                $upd = $this->db->prepare(
                    'UPDATE drivers SET wallet_balance_ngn = ?, wallet_grace_used = 1 WHERE id = ?'
                );
                $upd->execute([$newBalance, $driverId]);
            } else {
                $upd = $this->db->prepare('UPDATE drivers SET wallet_balance_ngn = ? WHERE id = ?');
                $upd->execute([$newBalance, $driverId]);
            }

            $this->ledger->createPending([
                'driver_id' => $driverId,
                'type' => 'acceptance_fee',
                'amount_ngn' => -$feeNgn,
                'balance_after_ngn' => $newBalance,
                'reference' => $reference,
                'delivery_request_id' => $requestId,
                'metadata' => ['request_id' => $requestId],
            ]);
            $this->ledger->markCompleted($reference, $newBalance);

            $this->db->commit();

            return ['ok' => true, 'balance' => $newBalance, 'grace_used' => $usedGrace];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RiderWalletService::chargeAcceptanceFee: ' . $e->getMessage());

            return ['ok' => false, 'balance' => 0.0, 'grace_used' => false, 'error' => 'system_error'];
        }
    }

    /**
     * Credit wallet after Centiiv fund webhook (idempotent by reference).
     *
     * @param array<string, mixed> $metadata
     */
    public function creditFund(int $driverId, float $amountNgn, string $reference, array $metadata = []): bool
    {
        if ($amountNgn <= 0 || $reference === '') {
            return false;
        }

        $existing = $this->ledger->findByReference($reference);
        if ($existing && ($existing['status'] ?? '') === 'completed') {
            return true;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT wallet_balance_ngn FROM drivers WHERE id = ? FOR UPDATE');
            $stmt->execute([$driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();

                return false;
            }

            $balance = (float) ($row['wallet_balance_ngn'] ?? 0);
            $newBalance = $balance + $amountNgn;

            if (!$existing) {
                $this->ledger->createPending([
                    'driver_id' => $driverId,
                    'type' => 'fund',
                    'amount_ngn' => $amountNgn,
                    'balance_after_ngn' => $newBalance,
                    'reference' => $reference,
                    'metadata' => $metadata,
                ]);
            }

            $this->db->prepare('UPDATE drivers SET wallet_balance_ngn = ? WHERE id = ?')
                ->execute([$newBalance, $driverId]);
            $this->ledger->markCompleted($reference, $newBalance, $metadata['centiiv_resource_id'] ?? null);

            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RiderWalletService::creditFund: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Reserve withdraw amount (debit on initiate, restore on failure).
     */
    public function reserveWithdraw(int $driverId, float $amountNgn, string $reference): array
    {
        if ($amountNgn <= 0) {
            return ['ok' => false, 'error' => 'invalid_amount'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT wallet_balance_ngn FROM drivers WHERE id = ? FOR UPDATE');
            $stmt->execute([$driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();

                return ['ok' => false, 'error' => 'driver_not_found'];
            }

            $balance = (float) ($row['wallet_balance_ngn'] ?? 0);
            if ($balance < $amountNgn) {
                $this->db->rollBack();

                return ['ok' => false, 'error' => 'insufficient_balance', 'balance' => $balance];
            }

            $newBalance = $balance - $amountNgn;
            $this->db->prepare('UPDATE drivers SET wallet_balance_ngn = ? WHERE id = ?')
                ->execute([$newBalance, $driverId]);

            $this->ledger->createPending([
                'driver_id' => $driverId,
                'type' => 'withdraw',
                'amount_ngn' => -$amountNgn,
                'balance_after_ngn' => $newBalance,
                'reference' => $reference,
                'metadata' => [],
            ]);

            $this->db->commit();

            return ['ok' => true, 'balance' => $newBalance];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RiderWalletService::reserveWithdraw: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'system_error'];
        }
    }

    public function completeWithdraw(string $reference): bool
    {
        $tx = $this->ledger->findByReference($reference);
        if (!$tx || ($tx['status'] ?? '') === 'completed') {
            return ($tx['status'] ?? '') === 'completed';
        }

        return $this->ledger->markCompleted($reference, (float) ($tx['balance_after_ngn'] ?? 0));
    }

    public function releaseWithdrawReserve(string $reference): bool
    {
        $tx = $this->ledger->findByReference($reference);
        if (!$tx || ($tx['status'] ?? '') !== 'pending') {
            return false;
        }

        $driverId = (int) ($tx['driver_id'] ?? 0);
        $amount = abs((float) ($tx['amount_ngn'] ?? 0));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT wallet_balance_ngn FROM drivers WHERE id = ? FOR UPDATE');
            $stmt->execute([$driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();

                return false;
            }
            $balance = (float) ($row['wallet_balance_ngn'] ?? 0);
            $newBalance = $balance + $amount;
            $this->db->prepare('UPDATE drivers SET wallet_balance_ngn = ? WHERE id = ?')
                ->execute([$newBalance, $driverId]);
            $this->ledger->markFailed($reference);
            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RiderWalletService::releaseWithdrawReserve: ' . $e->getMessage());

            return false;
        }
    }
}
