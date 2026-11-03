<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOfframpBridge;

/**
 * Open Centiiv off-ramp orders after PIN (1Click path).
 */
final class CentiivOfframpOrderService
{
    public function __construct(private SellIntermediaryPool $pool)
    {
    }

    /**
     * @param array<string, mixed> $centiivOrderData
     */
    public function resolveStellarPayin(array $centiivOrderData, string $from): ?string
    {
        return $this->resolvePayin($centiivOrderData, $from);
    }

    /**
     * @param array<string, mixed> $centiivOrderData
     */
    public function resolvePayin(array $centiivOrderData, string $from): ?string
    {
        $orderPayin = SendraOfframpBridge::extractPayinAddress($centiivOrderData);
        $dryRecipient = SendraOfframpBridge::dryRecipient();
        $allowPoolFallback = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT'] ?? getenv('SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT') ?: '')));
        if (($orderPayin === null || $orderPayin === '') && $allowPoolFallback === '1' && $dryRecipient !== '') {
            $orderPayin = $dryRecipient;
            error_log('Sendra 1Click v2: Centiiv order missing pay-in; using dry recipient for ' . $from);
        }
        if ($orderPayin === null || $orderPayin === '' || !SendraOfframpBridge::looksLikePayinAddress($orderPayin)) {
            return null;
        }

        return $orderPayin;
    }

    /**
     * @param array<string, mixed> $sellCtx
     * @param array<string, mixed> $extraMetadata
     * @return array{
     *   success: bool,
     *   data: array<string, mixed>,
     *   order_id: ?string,
     *   order_id_hex: ?string,
     *   payout_ref: ?string,
     *   error: ?string
     * }
     */
    public function createFromMeta(
        string $from,
        array $sellCtx,
        string $fromAsset,
        float $amount,
        string $description,
        array $extraMetadata,
        SendraCentiivRampService $agg
    ): array {
        if ($amount <= 0) {
            return [
                'success' => false,
                'data' => [],
                'order_id' => null,
                'order_id_hex' => null,
                'payout_ref' => null,
                'error' => 'Invalid order amount',
            ];
        }

        $body = $this->buildOrderBody($from, $sellCtx, $fromAsset, $amount, $description, $extraMetadata, $agg);
        $payoutRef = '';
        if (is_array($body['metadata'] ?? null)) {
            $payoutRef = trim((string) ($body['metadata']['sendra_payout_reference'] ?? $body['metadata']['trackingId'] ?? ''));
        }

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $this->formatConversionOrderError($created['error'] ?? 'Order failed');
            error_log("Centiiv create order (v2 post-PIN) failed {$from}: {$err} " . json_encode($created['data']));

            return [
                'success' => false,
                'data' => is_array($created['data'] ?? null) ? $created['data'] : [],
                'order_id' => null,
                'order_id_hex' => null,
                'payout_ref' => null,
                'error' => $err,
            ];
        }

        $data = is_array($created['data'] ?? null) ? $created['data'] : [];

        return [
            'success' => true,
            'data' => $data,
            'order_id' => SendraCentiivRampService::extractOrderId($data),
            'order_id_hex' => SendraCentiivRampService::extractOrderIdHex($data),
            'payout_ref' => $payoutRef !== '' ? $payoutRef : null,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $sellCtx
     * @param array<string, mixed> $extraMetadata
     * @return array<string, mixed>
     */
    private function buildOrderBody(
        string $from,
        array $sellCtx,
        string $fromAsset,
        float $amount,
        string $description,
        array $extraMetadata,
        SendraCentiivRampService $agg
    ): array {
        $acct = (string) ($sellCtx['account_number'] ?? '');
        $code = (string) ($sellCtx['bank_code'] ?? '');
        $name = (string) ($sellCtx['account_name'] ?? '');
        $bankDisplay = (string) ($sellCtx['bank_name'] ?? '');

        $rawBankRow = isset($sellCtx['centiiv_banks_raw_row']) && is_array($sellCtx['centiiv_banks_raw_row'])
            ? $sellCtx['centiiv_banks_raw_row']
            : null;
        if ($rawBankRow === null) {
            $banksLookup = $agg->getBanks();
            if ($banksLookup['success']) {
                $rawBankRow = SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
            }
        }

        $toFiat = strtoupper(trim((string) ($sellCtx['fiat'] ?? 'NGN')));
        if ($toFiat === '') {
            $toFiat = 'NGN';
        }

        $body = [
            'fromAsset' => $fromAsset,
            'toAsset' => $toFiat,
            'amount' => $amount,
            'description' => $description,
            'beneficiary' => SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
                $acct,
                $code,
                $name,
                $bankDisplay,
                $rawBankRow,
                isset($sellCtx['centiiv_verify_data']) && is_array($sellCtx['centiiv_verify_data'])
                    ? $sellCtx['centiiv_verify_data'] : null,
                SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($from)
            ),
            'network' => strtoupper((string) ($sellCtx['centiiv_network'] ?? SendraOfframpBridge::centiivNetwork())),
            'metadata' => array_merge([
                'user_phone' => $from,
                'source' => 'sendra_whatsapp',
            ], $extraMetadata),
        ];

        $lp = trim((string) ($_ENV['SENDRA_CENTIIV_LIQUIDITY_PROVIDER'] ?? getenv('SENDRA_CENTIIV_LIQUIDITY_PROVIDER') ?: ''));
        if ($lp !== '') {
            $body['metadata']['liquidity_provider'] = $lp;
        }
        $refundPayin = trim((string) ($sellCtx['refund_address'] ?? ''));
        if ($refundPayin === '') {
            $refundPayin = SendraOfframpBridge::centiivRefundAddressForNetwork((string) $body['network']);
        }
        if ($refundPayin !== '') {
            $body['refundAddress'] = $refundPayin;
        }
        $webhookUrl = $this->offrampWebhookUrl();
        if ($webhookUrl !== null) {
            $body['webhookUrl'] = $webhookUrl;
        }

        if ($this->pool->isFixedNgnTarget([], $sellCtx)) {
            $body = $this->pool->applyIntermediaryNgnIfEnabled($body, $sellCtx);
        }

        return SendraCentiivRampService::enrichOfframpOrderBody($body);
    }

    private function offrampWebhookUrl(): ?string
    {
        $webhookUrlOverride = trim(
            (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_URL'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_URL') ?: '')
        );
        if ($webhookUrlOverride !== '') {
            return $webhookUrlOverride;
        }
        $publicBase = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );
        if ($publicBase === '') {
            return null;
        }
        $path = trim(
            (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_PATH'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_PATH') ?: 'CentiivWebhook.php'),
            '/'
        );

        return rtrim($publicBase, '/') . '/' . $path;
    }

    private function formatConversionOrderError(string $error): string
    {
        if (stripos($error, 'timed out') !== false) {
            return 'Our bank payout partner did not respond in time. Nothing was debited from your wallet — please try again in a few minutes.';
        }
        if (stripos($error, 'property reference should not exist') !== false) {
            return 'Payout setup was rejected by our bank partner. Please try again — if it persists, contact support@usesendra.com';
        }

        return $error;
    }
}
