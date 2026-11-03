<?php

declare(strict_types=1);

namespace AptosV2\Bootstrap;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\SendraPanoraService;
use App\Services\WhatsAppService;
use AptosV2\Core\ServiceContainer;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepOutcome;
use AptosV2\Core\StepResult;
use AptosV2\Core\StepRouter;
use AptosV2\Persistence\ChatStateRepository;
use AptosV2\Persistence\OnrampPendingRepository;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Persistence\UserRepository;
use AptosV2\Services\Buy\OrderService as BuyOrderService;
use AptosV2\Services\Buy\QuoteService as BuyQuoteService;
use AptosV2\Services\Polymarket\OrderService as PolymarketOrderService;
use AptosV2\Routing\IntentRouter;
use AptosV2\Routing\IntentMatch;
use AptosV2\Services\NairaSizingService;
use AptosV2\Services\Onboarding\OnboardingService;
use AptosV2\Support\GreetingMatcher;
use AptosV2\Support\ImagePayload;
use AptosV2\Support\PinPromptService;
use AptosV2\Steps\Sell\BankService as SellBankService;
use AptosV2\Steps\Sell\ExecutionService as SellExecutionService;
use AptosV2\Steps\Sell\QuoteService as SellQuoteService;
use AptosV2\Steps\Swap\ExecutionService as SwapExecutionService;
use AptosV2\Steps\Send\ExecutionService as SendExecutionService;
use AptosV2\Steps\Buy\InteractiveRouter as BuyInteractiveRouter;
use AptosV2\Support\AptosBalanceService;
use AptosV2\Support\VoucherCreationService;

/**
 * v2 entry point. Exposes the same `handleIncomingMessage()` signature as the
 * old Aptos WhatsApp controller so webhook.php can swap it in with a one-line change.
 *
 * Dispatch order:
 *   1. Continue an in-progress v2 flow (chat_context {flow, step}).
 *   2. Match a new v2 flow-entry intent and start it.
 *   3. Otherwise reply with the unknown-command prompt.
 */
final class Kernel
{
    private function __construct(
        private ServiceContainer $services,
        private StepRouter $router,
        private IntentRouter $intents,
        private UserRepository $users,
        private ChatStateRepository $chatState,
        private PinPromptService $pinPrompts,
        private OnboardingService $onboarding,
    ) {
    }

    /**
     * Build a fully-wired Kernel.
     *
     * @param WhatsAppService $whatsapp Bound to the inbound phone number id
     *                                  (during testing this is the Logistics
     *                                  test number — that's intentional).
     */
    public static function boot(WhatsAppService $whatsapp): self
    {
        $db = self::connectAptosDb();

        $container = new ServiceContainer();
        $container->instance('db', $db);
        $container->instance('whatsapp', $whatsapp);

        $auth = new AuthController($db, $whatsapp);
        $container->instance('auth', $auth);

        $userRepo = new UserRepository($db);
        $container->instance('users', $userRepo);
        $container->set('tx_history', static fn (ServiceContainer $c): TransactionHistoryRepository => new TransactionHistoryRepository($c->get('db')));

        $container->set('user_model', static fn (ServiceContainer $c): User => new User($c->get('db')));
        $container->set('panora', static fn (): SendraPanoraService => new SendraPanoraService());
        $container->set('aptos_balance', static fn (): AptosBalanceService => new AptosBalanceService());
        $container->set('stellar_client', static fn (): \AptosV2\Support\StellarServiceClient => new \AptosV2\Support\StellarServiceClient());
        $container->set('stellar_balance', static fn (): \AptosV2\Support\StellarBalanceService => new \AptosV2\Support\StellarBalanceService());
        $container->set('stellar_wallet', static fn (ServiceContainer $c): \AptosV2\Support\StellarWalletService => new \AptosV2\Support\StellarWalletService($c->get('user_model')));
        $container->set('arc_client', static fn (): \AptosV2\Support\ArcServiceClient => new \AptosV2\Support\ArcServiceClient());
        $container->set('arc_balance', static fn (): \AptosV2\Support\ArcBalanceService => new \AptosV2\Support\ArcBalanceService());
        $container->set('arc_wallet', static fn (ServiceContainer $c): \AptosV2\Support\ArcWalletService => new \AptosV2\Support\ArcWalletService($c->get('user_model')));
        $container->set('pending_tx', static fn (ServiceContainer $c): PendingTransactionRepository => new PendingTransactionRepository($c->get('db')));
        $container->set('pin_prompts', static fn (ServiceContainer $c): PinPromptService => new PinPromptService(
            $c->get('whatsapp'),
            $c->get('user_model'),
            $c->get('pending_tx'),
        ));
        $container->set('onboarding', static fn (ServiceContainer $c): OnboardingService => new OnboardingService(
            $c->get('db'),
            $auth,
            $c->get('user_model'),
            $c->get('whatsapp'),
            $c->get('pin_prompts'),
        ));
        $container->set('swap_execution', static fn (ServiceContainer $c): SwapExecutionService => new SwapExecutionService(
            $c->get('whatsapp'),
            $c->get('user_model'),
            $c->get('panora'),
            $c->get('aptos_balance'),
            $c->get('pending_tx'),
        ));
        $container->set('send_execution', static fn (ServiceContainer $c): SendExecutionService => new SendExecutionService(
            $c->get('whatsapp'),
            $c->get('user_model'),
            $c->get('users'),
            $c->get('pending_tx'),
            new VoucherCreationService($c->get('db'), $c->get('whatsapp'), $c->get('user_model')),
            $c->get('pin_prompts'),
        ));
        $container->set('sell_execution', static fn (ServiceContainer $c): SellExecutionService => new SellExecutionService(
            $c->get('whatsapp'),
            $c->get('user_model'),
            $c->get('pending_tx'),
            new SellBankService($c->get('whatsapp')),
            new SellQuoteService($c->get('whatsapp')),
        ));
        $container->set('sell_naira_sizing', static fn (ServiceContainer $c): NairaSizingService => new NairaSizingService(
            $c->get('whatsapp'),
            $c->get('aptos_balance'),
        ));
        $container->set('onramp_pending', static fn (ServiceContainer $c): OnrampPendingRepository => new OnrampPendingRepository($c->get('db')));
        $container->set('buy_quote', static fn (ServiceContainer $c): BuyQuoteService => new BuyQuoteService(
            $c->get('whatsapp'),
            $c->get('db'),
        ));
        $container->set('buy_order', static fn (ServiceContainer $c): BuyOrderService => new BuyOrderService(
            $c->get('whatsapp'),
            $c->get('onramp_pending'),
            $c->get('users'),
        ));
        $container->set('polymarket_order', static fn (ServiceContainer $c): PolymarketOrderService => new PolymarketOrderService(
            $c->get('whatsapp'),
            $c->get('onramp_pending'),
        ));

        $pinPrompts = $container->get('pin_prompts');
        $onboarding = $container->get('onboarding');

        $router = new StepRouter(require self::configPath('steps.php'));
        $intents = new IntentRouter(require self::configPath('intents.php'));

        return new self(
            $container,
            $router,
            $intents,
            $userRepo,
            new ChatStateRepository($db),
            $pinPrompts,
            $onboarding,
        );
    }

    /**
     * @param array<string, mixed>|null $user Ignored — v2 re-resolves the user
     *                                        from the Aptos DB (see README).
     * @return array<string, mixed>
     */
    public function handleIncomingMessage(
        string $from,
        string $message,
        $user = null,
        string $displayName = 'there',
        ?string $inboundWamid = null
    ): array {
        $messageLower = strtolower(trim($message));
        $userRow = $this->users->findByPhone($from);

        if (empty($userRow)) {
            return $this->handleGuest($from, $message, $messageLower, $displayName, $inboundWamid);
        }

        if (!empty($userRow['is_frozen'])) {
            $this->services->get('whatsapp')->sendMessage(
                $from,
                "🔒 *Account Frozen*\n\n" .
                "Your Sendra account has been frozen for your security.\n\n" .
                "All transfers and actions are currently locked. To unfreeze your account, please contact support at *support@usesendra.com*."
            );

            return ['success' => false, 'reason' => 'account_frozen'];
        }

        if (!empty($userRow['awaiting_key_confirm'])) {
            $this->onboarding->skipLegacyKeyConfirmToPinSetup($from, $userRow);

            return ['success' => true, 'reason' => 'legacy_key_confirm_skipped'];
        }

        if (!empty($userRow['awaiting_pin_setup'])) {
            if (preg_match('/^\d{6}$/', trim($message))) {
                return $this->pinPrompts->rejectInChatSetup($from);
            }
            $this->pinPrompts->sendSetupPrompt($from);

            return ['success' => false, 'reason' => 'awaiting_pin'];
        }

        $state = $this->chatState->load($from);
        $activeFlow = is_string($state['flow'] ?? null) ? $state['flow'] : null;
        $activeStep = is_string($state['step'] ?? null) ? $state['step'] : null;

        // ── 0. Global interrupts — cancel/menu/help must work from any v2 step ─
        $interrupt = $this->resolveGlobalInterrupt($messageLower);
        if ($interrupt !== null && $this->router->has($interrupt->flow, $interrupt->step)) {
            $step = $this->router->resolve($interrupt->flow, $interrupt->step);
            $ctx = $this->makeContext($from, $message, $messageLower, $userRow, $state, $displayName, $inboundWamid);

            return $this->apply($step->handle($ctx), $from, $message, $userRow, $state, $displayName, $inboundWamid);
        }

        // ── 0b. Buy quote/asset buttons — must not get stuck on quote_pending/kyc ─
        $buyRoute = BuyInteractiveRouter::resolve(
            $from,
            $message,
            $messageLower,
            $state,
            $this->chatState,
            $this->services->get('db'),
        );
        if ($buyRoute !== null) {
            [$buyFlow, $buyStep, $buyState] = $buyRoute;
            if ($this->router->has($buyFlow, $buyStep)) {
                $step = $this->router->resolve($buyFlow, $buyStep);
                $ctx = $this->makeContext($from, $message, $messageLower, $userRow, $buyState, $displayName, $inboundWamid);

                return $this->apply($step->handle($ctx), $from, $message, $userRow, $buyState, $displayName, $inboundWamid);
            }
        }

        if (str_starts_with($messageLower, 'offramp_reconfirm_')) {
            $this->services->get('whatsapp')->sendMessage(
                $from,
                "That old rate-confirm button is no longer used.\n\nType *sell* to start a new cash-out."
            );

            return ['success' => true, 'action' => 'offramp_reconfirm_retired'];
        }

        if (empty($userRow['pending_tx_id']) && ImagePayload::parse($message) !== null
            && ($activeFlow === null || $activeStep === null || !$this->router->has($activeFlow, $activeStep))
            && $this->router->has('sell', 'ocr')) {
            $step = $this->router->resolve('sell', 'ocr');
            $ctx = $this->makeContext($from, $message, $messageLower, $userRow, [], $displayName, $inboundWamid);

            return $this->apply($step->handle($ctx), $from, $message, $userRow, [], $displayName, $inboundWamid);
        }

        // ── 1. Continue an in-progress v2 flow ───────────────────────────────
        if ($activeFlow !== null && $activeStep !== null && $this->router->has($activeFlow, $activeStep)) {
            $step = $this->router->resolve($activeFlow, $activeStep);
            $ctx = $this->makeContext($from, $message, $messageLower, $userRow, $state, $displayName, $inboundWamid);

            return $this->apply($step->handle($ctx), $from, $message, $userRow, $state, $displayName, $inboundWamid);
        }

        // ── 1b. Pending transaction — web PIN only; cancel via v2 ────────────
        if (!empty($userRow['pending_tx_id'])) {
            if (preg_match('/^\d{6}$/', trim($message))) {
                return $this->pinPrompts->rejectInChatConfirm($from, $userRow);
            }
            $isCancel = $messageLower === 'cancel' || str_starts_with($messageLower, 'cancel_');
            if ($isCancel) {
                $step = $this->router->resolve('cmd', 'cancel');
                $ctx = $this->makeContext($from, $message, $messageLower, $userRow, $state, $displayName, $inboundWamid);

                return $this->apply($step->handle($ctx), $from, $message, $userRow, $state, $displayName, $inboundWamid);
            }
            $this->pinPrompts->resendConfirmLink($from, $userRow);

            return ['success' => false, 'reason' => 'awaiting_pin_confirmation'];
        }

        // ── 2. Start a new v2 flow from an intent ────────────────────────────
        $intent = $this->resolveIntent($message, $messageLower, false);
        if ($intent !== null && $this->router->has($intent->flow, $intent->step)) {
            $step = $this->router->resolve($intent->flow, $intent->step);
            $ctx = $this->makeContext($from, $message, $messageLower, $userRow, [], $displayName, $inboundWamid);

            return $this->apply($step->handle($ctx), $from, $message, $userRow, [], $displayName, $inboundWamid);
        }

        $this->services->get('whatsapp')->sendMessage(
            $from,
            "Sorry, I didn't understand that.\nType *menu* for available commands."
        );

        return ['success' => false, 'action' => 'unknown'];
    }

    /**
     * Unregistered users: onboarding, help, and greetings only.
     *
     * @return array<string, mixed>
     */
    private function handleGuest(
        string $from,
        string $message,
        string $messageLower,
        string $displayName,
        ?string $inboundWamid
    ): array {
        $guestUser = [];

        $intent = $this->resolveIntent($message, $messageLower, true);
        if ($intent !== null && $this->router->has($intent->flow, $intent->step)) {
            $step = $this->router->resolve($intent->flow, $intent->step);
            $ctx = $this->makeContext($from, $message, $messageLower, $guestUser, [], $displayName, $inboundWamid);

            return $this->apply($step->handle($ctx), $from, $message, $guestUser, [], $displayName, $inboundWamid);
        }

        $this->services->get('whatsapp')->sendMessage(
            $from,
            "👋 Hi! I'm Sendra. Type *hi* to get started."
        );

        return ['success' => true, 'action' => 'guest_prompt'];
    }

    /**
     * Commands that must preempt an in-progress flow (cancel trapped in quote_pending, etc.).
     */
    private function resolveGlobalInterrupt(string $messageLower): ?IntentMatch
    {
        if ($messageLower === 'cancel' || str_starts_with($messageLower, 'cancel_')) {
            return new IntentMatch('cmd', 'cancel');
        }
        if ($messageLower === 'menu' || $messageLower === '/menu') {
            return new IntentMatch('cmd', 'menu');
        }
        if ($messageLower === 'help' || $messageLower === '/help') {
            return new IntentMatch('cmd', 'help');
        }
        if ($messageLower === 'rate' || $messageLower === 'rates' || $messageLower === '/rate' || $messageLower === '/rates') {
            return new IntentMatch('cmd', 'rate');
        }
        if ($messageLower === 'deposit' || $messageLower === '/deposit' || $messageLower === 'receive' || $messageLower === '/receive') {
            return new IntentMatch('deposit', 'entry');
        }
        if ($messageLower === 'create_stellar_wallet') {
            return new IntentMatch('cmd', 'create_stellar');
        }
        if ($messageLower === 'create_arc_wallet') {
            return new IntentMatch('cmd', 'create_arc');
        }
        if ($messageLower === 'sell' || (str_starts_with($messageLower, 'sell ') && !preg_match('/\b(naira|ngn)\b/', $messageLower))) {
            return new IntentMatch('sell', 'entry');
        }

        return null;
    }

    private function resolveIntent(string $message, string $messageLower, bool $isGuest): ?IntentMatch
    {
        $intent = $this->intents->match($message, $messageLower);
        if ($intent === null) {
            if ($isGuest && GreetingMatcher::isGreeting($messageLower)) {
                return new IntentMatch('onboard', 'welcome');
            }

            return null;
        }

        if ($isGuest) {
            if ($intent->flow === 'cmd' && $intent->step === 'menu' && GreetingMatcher::isGreeting($messageLower)) {
                return new IntentMatch('onboard', 'welcome');
            }
            if ($intent->flow === 'onboard' || ($intent->flow === 'cmd' && in_array($intent->step, ['help', 'cancel', 'rate'], true))) {
                return $intent;
            }

            return null;
        }

        if ($intent->flow === 'onboard') {
            return null;
        }

        return $intent;
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $state
     */
    private function makeContext(
        string $from,
        string $message,
        string $messageLower,
        array $userRow,
        array $state,
        string $displayName,
        ?string $inboundWamid
    ): StepContext {
        return new StepContext(
            $from,
            $message,
            $messageLower,
            $userRow,
            $state,
            $displayName,
            $inboundWamid,
            $this->services,
        );
    }

    /**
     * Apply a StepResult: persist transition / clear / re-dispatch to legacy.
     *
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $priorState
     * @return array<string, mixed>
     */
    private function apply(
        StepResult $result,
        string $from,
        string $message,
        array $userRow,
        array $priorState,
        string $displayName,
        ?string $inboundWamid
    ): array {
        switch ($result->outcome) {
            case StepOutcome::Transition:
                $newState = array_merge($priorState, $result->statePatch, [
                    'flow' => $result->flow,
                    'step' => $result->step,
                ]);
                $this->chatState->save($from, $newState);

                return ['success' => true] + $result->meta;

            case StepOutcome::Complete:
                $this->chatState->clear($from);

                return ['success' => true] + $result->meta;

            case StepOutcome::HandOff:
                error_log('aptos-v2 unexpected handOff: ' . json_encode($result->meta));
                $this->services->get('whatsapp')->sendMessage(
                    $from,
                    "Sorry, I didn't understand that.\nType *menu* for available commands."
                );

                return ['success' => false, 'action' => 'handoff_unknown'] + $result->meta;

            case StepOutcome::Stay:
            default:
                return ['success' => true] + $result->meta;
        }
    }

    private static function connectAptosDb(): \PDO
    {
        $bots = require __DIR__ . '/../../../config/Bots.php';
        foreach ($bots as $cfg) {
            if (($cfg['name'] ?? null) === 'aptos') {
                return \Database::getInstance($cfg['db_config'], useReplica: false)->getConnection();
            }
        }

        throw new \RuntimeException('Aptos bot config not found in config/Bots.php');
    }

    private static function configPath(string $file): string
    {
        return __DIR__ . '/../../config/' . $file;
    }
}
