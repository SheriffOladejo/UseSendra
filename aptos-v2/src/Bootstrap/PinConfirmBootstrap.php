<?php

declare(strict_types=1);

namespace AptosV2\Bootstrap;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\SendraVoucherService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use App\Services\SendraDayaAptosService;
use AptosV2\Services\Sell\CentiivOfframpOrderService;
use AptosV2\Services\Sell\DayaOfframpExecutionService;
use AptosV2\Services\Sell\NativeStellarOfframpExecutionService;
use AptosV2\Services\Sell\OneClickOfframpExecutionService;
use AptosV2\Services\Sell\OneClickOfframpSetupService;
use AptosV2\Services\Sell\PostPinExecutionService;
use AptosV2\Services\Sell\SellIntermediaryPool;
use AptosV2\Services\Send\ClaimedTransferExecutionService;
use AptosV2\Services\Swap\ClaimedSwapExecutionService;
use AptosV2\Services\Voucher\VoucherClaimService;
use AptosV2\Support\AptosServiceClient;
use AptosV2\Support\PinPromptService;
use AptosV2\Support\PinWebService;
use AptosV2\Support\V2PinConfirmUrls;

/**
 * Boots Aptos DB + PIN/post-PIN services for confirm-pin pages and workers.
 */
final class PinConfirmBootstrap
{
    /**
     * @return array{0: \PDO, 1: WhatsAppService, 2: PinWebService, 3: PostPinExecutionService}
     */
    public static function boot(?string $phoneNumberId = null): array
    {
        $db = self::connectAptosDb();
        $phoneNumberId = $phoneNumberId !== null && $phoneNumberId !== ''
            ? $phoneNumberId
            : V2PinConfirmUrls::defaultPhoneNumberId();

        $accessToken = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
        $whatsapp = new WhatsAppService($accessToken, $phoneNumberId);
        $auth = new AuthController($db, $whatsapp);
        $user = new User($db);
        $pendingTx = new PendingTransactionRepository($db);
        $txHistory = new TransactionHistoryRepository($db);
        $aptos = new AptosServiceClient();
        $pinPrompts = new PinPromptService($whatsapp, $user, $pendingTx);
        $pinWeb = new PinWebService($user, $auth, $whatsapp);
        $vouchers = new SendraVoucherService($db, $whatsapp, $user, $aptos);
        $pool = new SellIntermediaryPool();
        $centiivOrders = new CentiivOfframpOrderService($pool);
        $setup = new OneClickOfframpSetupService($pool, $centiivOrders);
        $oneClickOfframp = new OneClickOfframpExecutionService(
            $user,
            $whatsapp,
            $pendingTx,
            $txHistory,
            $setup,
            $aptos,
        );
        $stellarClient = new \AptosV2\Support\StellarServiceClient();
        $arcClient = new \AptosV2\Support\ArcServiceClient();
        $stellarOfframp = new NativeStellarOfframpExecutionService(
            $user,
            $whatsapp,
            $pendingTx,
            $txHistory,
            $centiivOrders,
            $stellarClient,
        );
        $arcOfframp = new \AptosV2\Services\Sell\NativeArcOfframpExecutionService(
            $user,
            $whatsapp,
            $pendingTx,
            $txHistory,
            $centiivOrders,
            $arcClient,
        );
        $dayaOfframp = new DayaOfframpExecutionService(
            $user,
            $whatsapp,
            $pendingTx,
            $txHistory,
            $aptos,
            new SendraDayaAptosService(),
        );
        $postPin = new PostPinExecutionService(
            $user,
            $auth,
            $whatsapp,
            $pendingTx,
            $oneClickOfframp,
            new ClaimedTransferExecutionService($user, $whatsapp, $pendingTx, $txHistory, $aptos, $stellarClient, $arcClient),
            new ClaimedSwapExecutionService($user, $whatsapp, $pendingTx, $txHistory, $aptos),
            $vouchers,
            $stellarOfframp,
            $arcOfframp,
            $dayaOfframp,
        );

        return [$db, $whatsapp, $pinWeb, $postPin];
    }

    /**
     * @deprecated Use boot()
     * @return array{0: \PDO, 1: WhatsAppService, 2: PinWebService, 3: PostPinExecutionService}
     */
    public static function createPostPinExecutionService(?string $phoneNumberId = null): array
    {
        return self::boot($phoneNumberId);
    }

    public static function createVoucherClaimService(?string $phoneNumberId = null): VoucherClaimService
    {
        $db = self::connectAptosDb();
        $phoneNumberId = $phoneNumberId !== null && $phoneNumberId !== ''
            ? $phoneNumberId
            : V2PinConfirmUrls::defaultPhoneNumberId();
        $accessToken = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
        $whatsapp = new WhatsAppService($accessToken, $phoneNumberId);
        $auth = new AuthController($db, $whatsapp);
        $user = new User($db);
        $pendingTx = new PendingTransactionRepository($db);
        $txHistory = new TransactionHistoryRepository($db);
        $aptos = new AptosServiceClient();
        $pinPrompts = new PinPromptService($whatsapp, $user, $pendingTx);

        return new VoucherClaimService(
            $db,
            $auth,
            $user,
            $whatsapp,
            new SendraVoucherService($db, $whatsapp, $user, $aptos),
            $txHistory,
            $aptos,
            $pinPrompts,
        );
    }

    public static function connectAptosDb(): \PDO
    {
        $bots = require __DIR__ . '/../../../config/Bots.php';
        foreach ($bots as $cfg) {
            if (($cfg['name'] ?? null) === 'aptos') {
                return \Database::getInstance($cfg['db_config'], useReplica: false)->getConnection();
            }
        }

        throw new \RuntimeException('Aptos bot config not found in config/Bots.php');
    }
}
