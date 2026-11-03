<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sendra buy (on-ramp): NGN → Centiiv → Aptos (via 1Click), native Stellar, or user Solana address.
 */
final class SendraBuyRampService
{
    private SendraCentiivRampService $centiiv;

    private SendraOneClickService $oneClick;

    public function __construct(?SendraCentiivRampService $centiiv = null, ?SendraOneClickService $oneClick = null)
    {
        $this->centiiv = $centiiv ?? new SendraCentiivRampService();
        $this->oneClick = $oneClick ?? new SendraOneClickService();
    }

    public function isCentiivConfigured(): bool
    {
        return $this->centiiv->isConfigured();
    }

    public static function buyMinNgn(): int
    {
        return max(100, (int) ($_ENV['SENDRA_BUY_MIN_NGN'] ?? getenv('SENDRA_BUY_MIN_NGN') ?: 100));
    }

    public function isOneClickBridgeConfigured(): bool
    {
        return SendraOfframpBridge::oneclickRampConfigured();
    }

    /**
     * @return array{success: bool, error: ?string, pay_ngn: float, asset: string, receive_crypto_display: string, receive_crypto: ?float, rate_hint: ?string, fee_hint: ?string, centiiv_usdc: ?float, oneclick_dry_quote_data: ?array<string, mixed>}
     */
    public function quoteNairaBuy(float $payNgn, string $asset, string $aptosAddress, bool $nativeStellar = false, bool $directSolana = false, bool $nativeArc = false): array
    {
        $asset = strtoupper(trim($asset));
        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            return ['success' => false, 'error' => 'unsupported_asset'] + $this->emptyQuote($payNgn, $asset);
        }
        if ($payNgn < self::buyMinNgn()) {
            return ['success' => false, 'error' => 'min_ngn'] + $this->emptyQuote($payNgn, $asset);
        }
        if ($nativeArc && $asset !== 'USDC') {
            return ['success' => false, 'error' => 'arc_usdc_only'] + $this->emptyQuote($payNgn, $asset);
        }
        if (!$nativeStellar && !$directSolana && !$nativeArc && ($aptosAddress === '' || !str_starts_with(strtolower($aptosAddress), '0x'))) {
            return ['success' => false, 'error' => 'missing_aptos'] + $this->emptyQuote($payNgn, $asset);
        }

        $qBody = [
            'fromAsset' => 'NGN',
            'toAsset' => $nativeStellar ? \AptosV2\Support\StellarStable::centiivAsset($asset) : 'USDC',
            'amount' => $payNgn,
        ];
        if ($nativeArc) {
            $qBody['network'] = 'ARC';
        } elseif ($nativeStellar) {
            $qBody['network'] = 'STELLAR';
        } elseif ($directSolana) {
            $qBody['network'] = 'SOLANA';
        }
        $q = $this->centiiv->getQuote($qBody);
        if (!$q['success']) {
            return [
                'success' => false,
                'error' => $q['error'] ?? 'centiiv_quote_failed',
            ] + $this->emptyQuote($payNgn, $asset);
        }

        $centiivSummary = SendraCentiivRampService::summarizeQuoteCrypto($q['data'], $payNgn);
        $centiivUsdc = $centiivSummary['receive_crypto'];
        if ($centiivUsdc === null || $centiivUsdc <= 0) {
            $minNgn = self::buyMinNgn();

            return [
                'success' => false,
                'error' => $payNgn < $minNgn ? 'min_ngn' : 'invalid_centiiv_usdc',
            ] + $this->emptyQuote($payNgn, $asset);
        }

        if ($nativeArc || $nativeStellar || $directSolana || !$this->isOneClickBridgeConfigured()) {
            return [
                'success' => true,
                'error' => null,
                'pay_ngn' => $payNgn,
                'asset' => $asset,
                'receive_crypto_display' => $centiivSummary['receive_crypto_display'],
                'receive_crypto' => $centiivUsdc,
                'rate_hint' => $centiivSummary['rate_hint'],
                'fee_hint' => $centiivSummary['fee_hint'],
                'centiiv_usdc' => $centiivUsdc,
                'oneclick_dry_quote_data' => null,
            ];
        }

        $originDecimals = SendraOfframpBridge::destDecimals();
        $oneClickOriginUsdc = $this->centiivUsdcForOneClickQuote($centiivUsdc);
        $originUsdcSmallest = SendraOneClickService::cryptoAmountToSmallestUnits(
            rtrim(rtrim(number_format($oneClickOriginUsdc, 6, '.', ''), '0'), '.'),
            $originDecimals
        );

        $destAptos = $this->aptosAssetId($asset);
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $buySwapType = $this->buyOneClickSwapType();

        $dryBody = SendraOneClickService::buildOnrampToAptosQuoteBody(
            $destAptos,
            $originUsdcSmallest,
            $aptosAddress,
            $deadlineIso,
            true,
            SendraOneClickService::resolveSearchQuoteWaitMs(),
            $buySwapType
        );
        $dryRes = $this->oneClick->requestQuote($dryBody);
        if (!$dryRes['success']) {
            return [
                'success' => false,
                'error' => $dryRes['error'] ?? 'oneclick_dry_quote_failed',
            ] + $this->emptyQuote($payNgn, $asset);
        }

        $parsed = SendraOneClickService::parseQuoteResponse($dryRes['data']);
        $recv = (float) ($parsed['amount_out_formatted'] ?? 0);
        if ($recv <= 0) {
            return ['success' => false, 'error' => 'oneclick_output_invalid'] + $this->emptyQuote($payNgn, $asset);
        }

        $recvDisplay = rtrim(rtrim(number_format($recv, 6, '.', ''), '0'), '.');

        $centiivRateHint = SendraCentiivRampService::formatNgnPerCryptoRateHint(
            SendraCentiivRampService::ngnPerCryptoFromCentiivQuoteData($q['data'], $payNgn, $centiivUsdc)
        ) ?? $centiivSummary['rate_hint'];

        return [
            'success' => true,
            'error' => null,
            'pay_ngn' => $payNgn,
            'asset' => $asset,
            'receive_crypto_display' => $recvDisplay,
            'receive_crypto' => $recv,
            'rate_hint' => $centiivRateHint,
            'fee_hint' => $centiivSummary['fee_hint'],
            'centiiv_usdc' => $centiivUsdc,
            'oneclick_dry_quote_data' => $dryRes['data'],
        ];
    }

    /**
     * Target Aptos receive amount → solve NGN spend via Centiiv rate probe, then full buy quote.
     *
     * @return array{success: bool, error: ?string, pay_ngn: float, asset: string, receive_crypto_display: string, receive_crypto: ?float, rate_hint: ?string, fee_hint: ?string, centiiv_usdc: ?float, oneclick_dry_quote_data: ?array<string, mixed>}
     */
    public function quoteCryptoBuy(float $targetCrypto, string $asset, string $aptosAddress, bool $nativeStellar = false, bool $directSolana = false, bool $nativeArc = false): array
    {
        $asset = strtoupper(trim($asset));
        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            return ['success' => false, 'error' => 'unsupported_asset'] + $this->emptyQuote(0, $asset);
        }
        if ($targetCrypto < 0.01) {
            return ['success' => false, 'error' => 'min_crypto'] + $this->emptyQuote(0, $asset);
        }
        if ($nativeArc && $asset !== 'USDC') {
            return ['success' => false, 'error' => 'arc_usdc_only'] + $this->emptyQuote(0, $asset);
        }
        if (!$nativeStellar && !$directSolana && !$nativeArc && ($aptosAddress === '' || !str_starts_with(strtolower($aptosAddress), '0x'))) {
            return ['success' => false, 'error' => 'missing_aptos'] + $this->emptyQuote(0, $asset);
        }

        $probeNgn = 10000.0;
        if ($probeNgn < 100) {
            return ['success' => false, 'error' => 'min_ngn'] + $this->emptyQuote(0, $asset);
        }

        $probe = $this->centiiv->getQuote([
            'fromAsset' => 'NGN',
            'toAsset' => $nativeStellar ? \AptosV2\Support\StellarStable::centiivAsset($asset) : 'USDC',
            'amount' => $probeNgn,
        ] + ($nativeArc ? ['network' => 'ARC'] : ($nativeStellar ? ['network' => 'STELLAR'] : ($directSolana ? ['network' => 'SOLANA'] : []))));
        if (!$probe['success']) {
            return [
                'success' => false,
                'error' => $probe['error'] ?? 'centiiv_quote_failed',
            ] + $this->emptyQuote(0, $asset);
        }

        $probeSummary = SendraCentiivRampService::summarizeQuoteCrypto($probe['data'], $probeNgn);
        $probeUsdc = $probeSummary['receive_crypto'];
        if ($probeUsdc === null || $probeUsdc <= 0) {
            return ['success' => false, 'error' => 'invalid_centiiv_usdc'] + $this->emptyQuote(0, $asset);
        }

        $bufferBps = (int) ($_ENV['SENDRA_BUY_CRYPTO_TARGET_NGN_BUFFER_BPS'] ?? getenv('SENDRA_BUY_CRYPTO_TARGET_NGN_BUFFER_BPS') ?: 250);
        $bufferBps = max(0, min(1000, $bufferBps));
        $payNgn = ($targetCrypto / $probeUsdc) * $probeNgn;
        $payNgn = ceil($payNgn * (10000 + $bufferBps) / 10000);
        if ($payNgn < 100) {
            return ['success' => false, 'error' => 'min_ngn'] + $this->emptyQuote(0, $asset);
        }

        return $this->quoteNairaBuy($payNgn, $asset, $aptosAddress, $nativeStellar, $directSolana, $nativeArc);
    }

    /**
     * @param array<string, mixed> $ctx from buy chat (pay_ngn, asset, oneclick_dry_quote_data, …)
     * @return array{success: bool, error: ?string, centiiv_order_id: ?string, bank: ?array{account_number: string, account_name: string, bank_name: string, bank_code: ?string}, meta: array<string, mixed>}
     */
    public function createBuyOrder(string $waPhone, string $aptosAddress, array $ctx): array
    {
        $payNgn = (float) ($ctx['pay_ngn'] ?? 0);
        $asset = strtoupper((string) ($ctx['asset'] ?? 'USDC'));
        $centiivUsdc = (float) ($ctx['centiiv_usdc'] ?? 0);

        if ($payNgn < 100 || !in_array($asset, ['USDC', 'USDT'], true)) {
            return ['success' => false, 'error' => 'invalid_context', 'centiiv_order_id' => null, 'bank' => null, 'meta' => []];
        }

        $publicBase = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );

        $oneclickMeta = [];
        $destinationPayin = null;
        $destinationMemo = null;
        $oneclickCorrelation = null;
        $destChain = strtolower((string) ($ctx['dest_chain'] ?? 'aptos'));
        $skipOneClick = in_array($destChain, ['stellar', 'solana', 'arc'], true);

        if ($this->isOneClickBridgeConfigured() && !$skipOneClick) {
            if ($centiivUsdc <= 0) {
                return ['success' => false, 'error' => 'missing_centiiv_usdc', 'centiiv_order_id' => null, 'bank' => null, 'meta' => []];
            }

            $originDecimals = SendraOfframpBridge::destDecimals();
            $oneClickOriginUsdc = $this->centiivUsdcForOneClickQuote($centiivUsdc);
            $originUsdcSmallest = SendraOneClickService::cryptoAmountToSmallestUnits(
                rtrim(rtrim(number_format($oneClickOriginUsdc, 6, '.', ''), '0'), '.'),
                $originDecimals
            );
            $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);

            $buySwapType = $this->buyOneClickSwapType();
            $liveBody = SendraOneClickService::buildOnrampToAptosQuoteBody(
                $this->aptosAssetId($asset),
                $originUsdcSmallest,
                $aptosAddress,
                $deadlineIso,
                false,
                null,
                $buySwapType
            );
            $liveRes = $this->oneClick->requestQuote($liveBody);
            if (!$liveRes['success']) {
                return [
                    'success' => false,
                    'error' => $liveRes['error'] ?? 'oneclick_live_quote_failed',
                    'centiiv_order_id' => null,
                    'bank' => null,
                    'meta' => [],
                ];
            }
            $parsedLive = SendraOneClickService::parseQuoteResponse($liveRes['data']);
            if ($parsedLive['deposit_address'] === null || $parsedLive['correlation_id'] === null) {
                return ['success' => false, 'error' => 'oneclick_live_incomplete', 'centiiv_order_id' => null, 'bank' => null, 'meta' => []];
            }

            $destinationPayin = $parsedLive['deposit_address'];
            $destinationMemo = $parsedLive['deposit_memo'];
            if (SendraOfframpBridge::originRequiresMemo() && ($destinationMemo === null || $destinationMemo === '')) {
                return ['success' => false, 'error' => 'oneclick_memo_required', 'centiiv_order_id' => null, 'bank' => null, 'meta' => []];
            }
            $oneclickCorrelation = $parsedLive['correlation_id'];
            $aptosDecimals = (int) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_DECIMALS'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_DECIMALS') ?: 6);
            $minOriginDisplay = self::formatSmallestUnitsAsCrypto($parsedLive['min_amount_in'], $originDecimals);
            $minRecvDisplay = $parsedLive['min_amount_out_formatted']
                ?? self::formatSmallestUnitsAsCrypto($parsedLive['min_amount_out'], $aptosDecimals);
            $oneclickMeta = [
                'sendra_oneclick_correlation_id' => $oneclickCorrelation,
                'sendra_oneclick_deposit_stellar' => $destinationPayin,
                'sendra_oneclick_deposit_address' => $destinationPayin,
                'sendra_oneclick_payin_network' => SendraOfframpBridge::centiivNetwork(),
                'sendra_oneclick_deposit_memo' => $destinationMemo,
                'sendra_oneclick_swap_type' => $buySwapType,
                'sendra_oneclick_receive_display' => $parsedLive['amount_out_formatted'],
                'sendra_oneclick_min_receive_display' => $minRecvDisplay,
                'sendra_oneclick_min_stellar_display' => $minOriginDisplay,
                'sendra_oneclick_quote_signature' => $parsedLive['signature'],
            ];
        }

        $description = 'Sendra WhatsApp buy ' . $waPhone;
        if ($oneclickCorrelation !== null) {
            $description .= ' (1Click ' . $oneclickCorrelation . ')';
        }
        if ($destinationMemo !== null && $destinationMemo !== '') {
            $description .= ' memo:' . $destinationMemo;
        }

        $body = [
            'fromAsset' => 'NGN',
            'toAsset' => $destChain === 'stellar'
                ? \AptosV2\Support\StellarStable::centiivAsset($asset)
                : 'USDC',
            'amount' => $payNgn,
            'description' => $description,
            'metadata' => array_merge([
                'user_phone' => $waPhone,
                'source' => 'sendra_whatsapp',
                'sendra_ramp_kind' => 'buy',
                'sendra_ledger_asset' => $asset,
                'sendra_ledger_amount' => (string) ($ctx['receive_crypto_display'] ?? ''),
                'sendra_pay_ngn' => (string) $payNgn,
                'sendra_user_aptos' => $aptosAddress,
            ], $oneclickMeta),
        ];

        $useRequestsApi = $this->centiivBuyUsesRequestsEndpoint();
        if ($destinationPayin !== null && SendraOfframpBridge::looksLikePayinAddress($destinationPayin)) {
            $body['destinationAddress'] = $destinationPayin;
            $body['network'] = SendraOfframpBridge::centiivNetwork();
            if ($useRequestsApi) {
                $body['sender'] = [
                    'externalId' => SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($waPhone),
                    'fullName' => trim((string) ($ctx['user_display_name'] ?? '')) !== ''
                        ? (string) $ctx['user_display_name']
                        : ('Sendra ' . $waPhone),
                ];
                if ($destinationMemo !== null && $destinationMemo !== '' && SendraOfframpBridge::originRequiresMemo()) {
                    $body['destinationMemo'] = $destinationMemo;
                    $body['destinationMemoType'] = $this->centiivDestinationMemoType();
                }
            }
        } elseif ($destChain === 'solana') {
            $solDest = SendraCentiivRampService::normalizeSolanaAddressInput((string) ($ctx['solana_destination'] ?? ''));
            if ($solDest === '' || !SendraCentiivRampService::looksLikeSolanaAddress($solDest)) {
                return [
                    'success' => false,
                    'error' => 'missing_solana_destination',
                    'centiiv_order_id' => null,
                    'bank' => null,
                    'meta' => [],
                ];
            }
            $body['destinationAddress'] = $solDest;
            $body['network'] = 'SOLANA';
            $body['sender'] = [
                'externalId' => SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($waPhone),
                'fullName' => trim((string) ($ctx['user_display_name'] ?? '')) !== ''
                    ? (string) $ctx['user_display_name']
                    : ('Sendra ' . $waPhone),
            ];
            $body['metadata']['sendra_dest_chain'] = 'solana';
            $body['metadata']['sendra_user_solana'] = $solDest;
        } elseif ($destChain === 'stellar') {
            $poolG = \AptosV2\Support\StellarReceive::sharedAddress();
            $memo = trim((string) ($ctx['stellar_deposit_memo'] ?? ''));
            $userC = trim((string) ($ctx['stellar_address'] ?? ''));
            if ($poolG === '' || $memo === '') {
                return [
                    'success' => false,
                    'error' => 'missing_stellar_receive_memo',
                    'centiiv_order_id' => null,
                    'bank' => null,
                    'meta' => [],
                ];
            }
            $body['destinationAddress'] = $poolG;
            $body['network'] = 'STELLAR';
            $body['destinationMemo'] = $memo;
            $body['destinationMemoType'] = $this->centiivDestinationMemoType();
            $body['sender'] = [
                'externalId' => SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($waPhone),
                'fullName' => trim((string) ($ctx['user_display_name'] ?? '')) !== ''
                    ? (string) $ctx['user_display_name']
                    : ('Sendra ' . $waPhone),
            ];
            $body['metadata']['sendra_user_stellar'] = $userC;
            $body['metadata']['sendra_stellar_deposit_memo'] = $memo;
            $body['metadata']['sendra_dest_chain'] = 'stellar';
            $body['metadata']['sendra_stellar_pool_g'] = $poolG;
            $destinationMemo = $memo;
        } elseif ($destChain === 'arc') {
            $arcDest = trim((string) ($ctx['arc_address'] ?? ''));
            if ($arcDest === '' || !\AptosV2\Support\ArcAddress::isValid($arcDest)) {
                return [
                    'success' => false,
                    'error' => 'missing_arc_address',
                    'centiiv_order_id' => null,
                    'bank' => null,
                    'meta' => [],
                ];
            }
            if ($asset !== 'USDC') {
                return [
                    'success' => false,
                    'error' => 'arc_usdc_only',
                    'centiiv_order_id' => null,
                    'bank' => null,
                    'meta' => [],
                ];
            }
            $body['destinationAddress'] = $arcDest;
            $body['network'] = 'ARC';
            $body['sender'] = [
                'externalId' => SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($waPhone),
                'fullName' => trim((string) ($ctx['user_display_name'] ?? '')) !== ''
                    ? (string) $ctx['user_display_name']
                    : ('Sendra ' . $waPhone),
            ];
            $body['metadata']['sendra_user_arc'] = $arcDest;
            $body['metadata']['sendra_dest_chain'] = 'arc';
        } elseif (!$this->isOneClickBridgeConfigured()) {
            $body['destinationAddress'] = $aptosAddress;
        }

        // POST /requests rejects webhookUrl; rely on Centiiv dashboard webhook for buy. /public/order still accepts it.
        if (!$useRequestsApi) {
            $webhookUrlOverride = trim((string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_URL'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_URL') ?: ''));
            if ($webhookUrlOverride !== '') {
                $body['webhookUrl'] = $webhookUrlOverride;
            } elseif ($publicBase !== '') {
                $path = trim((string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_PATH'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_PATH') ?: 'CentiivWebhook.php'), '/');
                $body['webhookUrl'] = rtrim($publicBase, '/') . '/' . $path;
            }
        }

        $created = ($useRequestsApi && ($this->isOneClickBridgeConfigured() || $skipOneClick))
            ? $this->centiiv->createOnrampRequest($body)
            : $this->centiiv->createConversionOrder($body);
        if (!$created['success']) {
            return [
                'success' => false,
                'error' => $created['error'] ?? 'centiiv_order_failed',
                'centiiv_order_id' => null,
                'bank' => null,
                'meta' => [],
            ];
        }

        $data = is_array($created['data']) ? $created['data'] : [];
        $orderId = SendraCentiivRampService::extractOrderId($data);
        $bank = SendraCentiivRampService::extractVirtualBankAccount($data);
        if ($bank === null && $orderId !== null && $orderId !== '') {
            $fetched = $this->centiiv->getRequest($orderId);
            if (!empty($fetched['success']) && is_array($fetched['data'] ?? null)) {
                $full = $fetched['data'];
                if (isset($full['data']) && is_array($full['data'])) {
                    $full = $full['data'];
                }
                $bank = SendraCentiivRampService::extractVirtualBankAccount($full);
            }
        }

        $meta = array_merge($oneclickMeta, [
            'kind' => 'oneclick_centiiv_onramp',
            'centiiv_order_id' => $orderId,
            'centiiv_order_id_hex' => SendraCentiivRampService::extractOrderIdHex($data),
            'centiiv_destination_memo' => $destinationMemo,
            'centiiv_destination_memo_type' => ($destinationMemo !== null && $destinationMemo !== '')
                ? $this->centiivDestinationMemoType() : null,
            'centiiv_create_path' => ($useRequestsApi && ($this->isOneClickBridgeConfigured() || $skipOneClick)) ? '/requests' : '/public/order',
            'pay_ngn' => $payNgn,
            'pay_ngn_display' => number_format($payNgn, 2, '.', ','),
            'ledger_asset' => $asset,
            'receive_crypto_display' => (string) ($ctx['receive_crypto_display'] ?? ''),
            'user_aptos' => $aptosAddress,
            'need_oneclick_poll' => $this->isOneClickBridgeConfigured() && !$skipOneClick,
            'dest_chain' => $destChain !== '' ? $destChain : 'aptos',
            'stellar_deposit_memo' => (string) ($ctx['stellar_deposit_memo'] ?? ''),
            'user_stellar' => (string) ($ctx['stellar_address'] ?? ''),
            'user_solana' => (string) ($ctx['solana_destination'] ?? ''),
            'solana_destination' => (string) ($ctx['solana_destination'] ?? ''),
            'virtual_bank' => $bank,
            'centiiv_usdc' => $centiivUsdc > 0 ? $centiivUsdc : null,
            'centiiv_crypto_received' => $centiivUsdc > 0 ? $centiivUsdc : null,
            'centiiv_crypto_asset' => 'USDC',
        ]);

        return [
            'success' => true,
            'error' => null,
            'centiiv_order_id' => $orderId,
            'bank' => $bank,
            'meta' => $meta,
        ];
    }

    /**
     * Size 1Click quotes below Centiiv estimate so FLEX_INPUT minAmountIn stays under actual payout.
     */
    private function centiivUsdcForOneClickQuote(float $centiivUsdc): float
    {
        if ($centiivUsdc <= 0) {
            return 0.0;
        }
        $bps = (int) ($_ENV['SENDRA_BUY_ONECLICK_STELLAR_HAIRCUT_BPS'] ?? getenv('SENDRA_BUY_ONECLICK_STELLAR_HAIRCUT_BPS') ?: 200);
        $bps = max(0, min(1000, $bps));

        return $centiivUsdc * (10000 - $bps) / 10000;
    }

    /**
     * 1Click swap mode for buy (Stellar → Aptos). FLEX_INPUT accepts Centiiv payout within minAmountIn band.
     */
    private function buyOneClickSwapType(): string
    {
        $t = strtoupper(trim((string) (
            $_ENV['SENDRA_BUY_ONECLICK_SWAP_TYPE']
            ?? getenv('SENDRA_BUY_ONECLICK_SWAP_TYPE')
            ?: 'FLEX_INPUT'
        )));
        if (!in_array($t, ['FLEX_INPUT', 'EXACT_INPUT', 'EXACT_OUTPUT'], true)) {
            return 'FLEX_INPUT';
        }

        return $t;
    }

    /**
     * @param ?string $smallest smallest-unit amount string from 1Click quote
     */
    private static function formatSmallestUnitsAsCrypto(?string $smallest, int $decimals): ?string
    {
        if ($smallest === null || $smallest === '' || !ctype_digit($smallest)) {
            return null;
        }
        if ($decimals <= 0) {
            return $smallest;
        }
        $scale = (string) (10 ** min(18, $decimals));
        if (function_exists('bcdiv')) {
            $s = bcdiv($smallest, $scale, $decimals);
        } else {
            $s = number_format(((float) $smallest) / (float) $scale, $decimals, '.', '');
        }

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    /**
     * Buy with 1Click uses POST /requests (memo fields). Set SENDRA_CENTIIV_BUY_USE_REQUESTS=0 to fall back to /public/order.
     */
    private function centiivBuyUsesRequestsEndpoint(): bool
    {
        $raw = $_ENV['SENDRA_CENTIIV_BUY_USE_REQUESTS'] ?? getenv('SENDRA_CENTIIV_BUY_USE_REQUESTS');
        if ($raw === false || $raw === null || (is_string($raw) && trim($raw) === '')) {
            $flag = '1';
        } else {
            $flag = strtolower(trim((string) $raw));
        }

        return !in_array($flag, ['0', 'false', 'off', 'no'], true);
    }

    private function centiivDestinationMemoType(): string
    {
        return trim((string) (
            $_ENV['SENDRA_CENTIIV_DESTINATION_MEMO_TYPE']
            ?? getenv('SENDRA_CENTIIV_DESTINATION_MEMO_TYPE')
            ?: 'id'
        ));
    }

    private function stellarUsdcAssetId(): string
    {
        return SendraOfframpBridge::destAssetId();
    }

    private function aptosAssetId(string $asset): string
    {
        if ($asset === 'USDT') {
            return trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        }

        return trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
    }

    private function stellarRefundAddress(): string
    {
        return SendraOfframpBridge::originRefundAddress();
    }

    /**
     * @return array{pay_ngn: float, asset: string, receive_crypto_display: string, receive_crypto: ?float, rate_hint: ?string, fee_hint: ?string, centiiv_usdc: ?float, oneclick_dry_quote_data: null}
     */
    private function emptyQuote(float $payNgn, string $asset): array
    {
        return [
            'pay_ngn' => $payNgn,
            'asset' => $asset,
            'receive_crypto_display' => '—',
            'receive_crypto' => null,
            'rate_hint' => null,
            'fee_hint' => null,
            'centiiv_usdc' => null,
            'oneclick_dry_quote_data' => null,
        ];
    }
}
