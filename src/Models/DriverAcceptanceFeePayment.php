<?php

namespace App\Models;

use PDO;
use PDOException;

/**
 * Ledger row for each successful driver acceptance fee (Paystack, Centiiv, etc.).
 * Idempotent on paystack_reference (stores provider reference / our DRV_ACCEPT_* ref).
 */
class DriverAcceptanceFeePayment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert one row from charge.success. Safe to call on webhook retries.
     *
     * @param array<string, mixed> $data Normalized charge payload (reference, amount in kobo, currency, channel, id, paid_at, …)
     * @param array<string, mixed> $metadata Normalized metadata array
     * @param array<string, mixed>|null $requestRow delivery_requests row if loaded
     */
    public function recordSuccessfulCharge(array $data, array $metadata, ?array $requestRow = null): bool
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return false;
        }

        $amountKobo = (int) ($data['amount'] ?? 0);
        if ($amountKobo < 1) {
            return false;
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));
        $channel = isset($data['channel']) ? (string) $data['channel'] : null;
        $txId = isset($data['id']) ? (string) $data['id'] : null;
        if ($txId === '' || $txId === '0') {
            $txId = null;
        }

        $paidRaw = $data['paid_at'] ?? $data['paidAt'] ?? null;
        $paidAt = null;
        if (is_string($paidRaw) && $paidRaw !== '') {
            $ts = strtotime($paidRaw);
            if ($ts !== false) {
                $paidAt = date('Y-m-d H:i:s', $ts);
            }
        }

        $requestId = (int) ($metadata['request_id'] ?? 0);
        $driverId = (int) ($metadata['driver_id'] ?? 0);

        $customerUserId = null;
        $jobPrice = null;
        if ($requestRow) {
            if (!empty($requestRow['user_id'])) {
                $customerUserId = (int) $requestRow['user_id'];
            }
            if (isset($requestRow['offered_price'])) {
                $jobPrice = $requestRow['offered_price'];
            }
        }

        $payload = [
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'paid_at' => $paidRaw,
            'id' => $data['id'] ?? null,
            'customer' => $data['customer'] ?? null,
            'metadata' => $metadata,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO logistics_driver_fee_payments (
                    paystack_reference,
                    paystack_transaction_id,
                    delivery_request_id,
                    driver_id,
                    customer_user_id,
                    amount_kobo,
                    currency,
                    channel,
                    paid_at,
                    job_offered_price_ngn,
                    fee_metadata_type,
                    raw_paystack_data
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE paystack_reference = paystack_reference
            ');

            return $stmt->execute([
                $reference,
                $txId,
                $requestId > 0 ? $requestId : null,
                $driverId > 0 ? $driverId : null,
                $customerUserId,
                $amountKobo,
                $currency,
                $channel,
                $paidAt,
                $jobPrice,
                (string) ($metadata['type'] ?? 'driver_acceptance_fee'),
                $json,
            ]);
        } catch (PDOException $e) {
            error_log('DriverAcceptanceFeePayment::recordSuccessfulCharge: ' . $e->getMessage());

            return false;
        }
    }
}
