<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Centiiv banking/ramp API for logistics riders (verify account, NGN payout).
 * Uses SENDRA_CENTIIV_* credentials on api.centiiv.io (same as Aptos sell flow).
 */
final class LogisticsRiderCentiivService
{
    private SendraCentiivRampService $ramp;

    public function __construct(?SendraCentiivRampService $ramp = null)
    {
        $this->ramp = $ramp ?? new SendraCentiivRampService();
    }

    public function isConfigured(): bool
    {
        return $this->ramp->isConfigured();
    }

    public function getRamp(): SendraCentiivRampService
    {
        return $this->ramp;
    }

    /**
     * Initiate NGN bank payout for rider withdrawal.
     *
     * @param array<string, mixed> $beneficiary from SendraCentiivRampService::buildOfframpBeneficiaryWithVerify
     * @param array<string, mixed> $metadata
     * @return array{success: bool, order_id: ?string, error: ?string, data: array<string, mixed>}
     */
    public function createRiderWithdrawOrder(
        int $driverId,
        string $driverPhone,
        float $amountNgn,
        array $beneficiary,
        string $reference,
        array $metadata = []
    ): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'order_id' => null, 'error' => 'not_configured', 'data' => []];
        }

        $publicBase = rtrim(
            (string) ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: 'https://usesendra.com'),
            '/'
        );
        $webhookUrl = $publicBase . '/CentiivWebhook.php';

        $body = [
            'fromAsset' => 'NGN',
            'toAsset' => 'NGN',
            'amount' => $amountNgn,
            'description' => 'Sendra logistics rider withdraw ' . $driverPhone,
            'beneficiary' => $beneficiary,
            'webhookUrl' => $webhookUrl,
            'metadata' => array_merge([
                'source' => 'sendra_logistics_rider',
                'type' => 'rider_wallet_withdraw',
                'driver_id' => (string) $driverId,
                'driver_phone' => $driverPhone,
                'reference' => $reference,
            ], $metadata),
        ];

        $res = $this->ramp->createConversionOrder($body);
        if (!$res['success']) {
            return [
                'success' => false,
                'order_id' => null,
                'error' => $res['error'] ?? 'order_failed',
                'data' => is_array($res['data']) ? $res['data'] : [],
            ];
        }

        $data = $res['data'];
        $orderId = (string) ($data['id'] ?? $data['orderId'] ?? $data['requestId'] ?? '');

        return [
            'success' => true,
            'order_id' => $orderId !== '' ? $orderId : null,
            'error' => null,
            'data' => $data,
        ];
    }
}
