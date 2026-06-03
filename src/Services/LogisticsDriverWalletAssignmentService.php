<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeliveryRequest;
use App\Models\DriverAcceptanceFeePayment;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\RiderWalletTransaction;
use PDO;

/**
 * Assign rider to job using wallet debit (replaces per-order Centiiv deposit for acceptance).
 */
final class LogisticsDriverWalletAssignmentService
{
    public function __construct(
        private PDO $db,
        private WhatsAppService $whatsappService,
        private string $projectRoot,
        private LogisticsAfterDriverFeePaidService $afterPaid
    ) {
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public function assignWithWalletDebit(
        int $requestId,
        int $driverId,
        float $offeredPriceNgn
    ): array {
        $deliveryRequestModel = new DeliveryRequest($this->db);
        $driverModel = new LogisticsDriver($this->db);
        $userModel = new LogisticsUser($this->db);
        $wallet = new RiderWalletService(
            $this->db,
            $driverModel,
            new RiderWalletTransaction($this->db)
        );

        $fee = round($offeredPriceNgn * 0.10, 2);
        $reference = 'WALLET_FEE_' . $requestId . '_' . $driverId . '_' . uniqid();

        $charge = $wallet->chargeAcceptanceFee($driverId, $fee, $requestId, $reference);
        if (!$charge['ok']) {
            $deliveryRequestModel->releaseClaim($requestId, $driverId);
            if (($charge['error'] ?? '') === 'insufficient_balance') {
                return [
                    'ok' => false,
                    'error' => $wallet->hasNegativeBalance($driverId)
                        ? 'negative_balance'
                        : 'insufficient_balance',
                ];
            }

            return ['ok' => false, 'error' => 'charge_failed'];
        }

        $ledgerData = [
            'reference' => $reference,
            'amount' => (int) round($fee * 100),
            'currency' => 'NGN',
            'channel' => 'rider_wallet',
            'id' => $reference,
            'paid_at' => date('c'),
            'customer' => null,
        ];
        $metadata = [
            'type' => 'driver_acceptance_fee',
            'request_id' => $requestId,
            'driver_id' => $driverId,
            'wallet' => true,
        ];
        $requestForLedger = $deliveryRequestModel->findById($requestId);
        (new DriverAcceptanceFeePayment($this->db))->recordSuccessfulCharge(
            $ledgerData,
            $metadata,
            is_array($requestForLedger) ? $requestForLedger : null
        );

        $deliveryRequestModel->update($requestId, [
            'acceptance_payment_reference' => $reference,
        ]);

        $this->afterPaid->run(
            $this->db,
            $requestId,
            $driverId,
            $reference,
            $ledgerData,
            $metadata,
            $deliveryRequestModel,
            $driverModel,
            $userModel
        );

        $driver = $driverModel->findById($driverId);
        if ($driver && !empty($charge['grace_used'])) {
            $this->whatsappService->sendMessage(
                (string) $driver['phone'],
                '⚠️ Your wallet went negative for this job (one-time grace). Fund your wallet before accepting another order.'
            );
        }

        return ['ok' => true];
    }
}
