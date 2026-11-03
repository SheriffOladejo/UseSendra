<?php

declare(strict_types=1);

use AptosV2\Steps\Commands\BalanceStep;
use AptosV2\Steps\Commands\CancelStep;
use AptosV2\Steps\Commands\ChangePinStep;
use AptosV2\Steps\Commands\HelpStep;
use AptosV2\Steps\Commands\HistoryStep;
use AptosV2\Steps\Commands\MenuStep;
use AptosV2\Steps\Commands\RateStep;
use AptosV2\Steps\Commands\ReceiveStep;
use AptosV2\Steps\Commands\CreateArcStep;
use AptosV2\Steps\Commands\CreateStellarStep;
use AptosV2\Steps\Onboard\CreateAccountStep as OnboardCreateAccountStep;
use AptosV2\Steps\Onboard\WelcomeStep as OnboardWelcomeStep;
use AptosV2\Steps\Deposit\AmountStep as DepositAmountStep;
use AptosV2\Steps\Deposit\AssetSelectionStep as DepositAssetSelectionStep;
use AptosV2\Steps\Deposit\BankDetailsStep as DepositBankDetailsStep;
use AptosV2\Steps\Deposit\BankOfframpStep as DepositBankOfframpStep;
use AptosV2\Steps\Deposit\DestinationStep as DepositDestinationStep;
use AptosV2\Steps\Deposit\EntryStep as DepositEntryStep;
use AptosV2\Steps\Deposit\MethodStep as DepositMethodStep;
use AptosV2\Steps\Deposit\NetworkStep as DepositNetworkStep;
use AptosV2\Steps\Deposit\OneClickAmountStep as DepositOneClickAmountStep;
use AptosV2\Steps\Deposit\SolanaAmountStep as DepositSolanaAmountStep;
use AptosV2\Steps\Deposit\SolanaAssetStep as DepositSolanaAssetStep;
use AptosV2\Steps\Withdraw\AssetStep as WithdrawAssetStep;
use AptosV2\Steps\Withdraw\BankStep as WithdrawBankStep;
use AptosV2\Steps\Withdraw\CryptoAmountStep as WithdrawCryptoAmountStep;
use AptosV2\Steps\Withdraw\EntryStep as WithdrawEntryStep;
use AptosV2\Steps\Withdraw\MethodStep as WithdrawMethodStep;
use AptosV2\Steps\Withdraw\NairaAmountStep as WithdrawNairaAmountStep;
use AptosV2\Steps\Buy\AmountStep as BuyAmountStep;
use AptosV2\Steps\Buy\AssetStep as BuyAssetStep;
use AptosV2\Steps\Buy\EntryStep as BuyEntryStep;
use AptosV2\Steps\Buy\OpeningOrderStep as BuyOpeningOrderStep;
use AptosV2\Steps\Buy\QuoteConfirmStep as BuyQuoteConfirmStep;
use AptosV2\Steps\Buy\QuotePendingStep as BuyQuotePendingStep;
use AptosV2\Steps\Buy\SolanaAddressStep as BuySolanaAddressStep;
use AptosV2\Steps\Polymarket\AddressStep as PolymarketAddressStep;
use AptosV2\Steps\Polymarket\AmountStep as PolymarketAmountStep;
use AptosV2\Steps\Polymarket\EntryStep as PolymarketEntryStep;
use AptosV2\Steps\Polymarket\QuoteConfirmStep as PolymarketQuoteConfirmStep;
use AptosV2\Steps\Sell\AmountStep as SellAmountStep;
use AptosV2\Steps\Sell\AssetStep as SellAssetStep;
use AptosV2\Steps\Sell\BankStep as SellBankStep;
use AptosV2\Steps\Sell\EntryStep as SellEntryStep;
use AptosV2\Steps\Sell\NairaEntryStep as SellNairaEntryStep;
use AptosV2\Steps\Sell\NetworkStep as SellNetworkStep;
use AptosV2\Steps\Sell\BankOcrStep as SellBankOcrStep;
use AptosV2\Steps\Sell\OcrAmountStep as SellOcrAmountStep;
use AptosV2\Steps\Send\StartStep as SendStartStep;
use AptosV2\Steps\Kyc\PromptStep as KycPromptStep;
use AptosV2\Steps\Swap\ConfirmStep as SwapConfirmStep;
use AptosV2\Steps\Swap\StartStep as SwapStartStep;

return [
    // ── One-shot commands ──────────────────────────────────────────────────────
    'cmd:balance' => BalanceStep::class,
    'cmd:menu'    => MenuStep::class,
    'cmd:receive' => ReceiveStep::class,
    'cmd:history' => HistoryStep::class,
    'cmd:cancel'  => CancelStep::class,
    'cmd:help'    => HelpStep::class,
    'cmd:change_pin' => ChangePinStep::class,
    'cmd:rate'    => RateStep::class,
    'cmd:create_stellar' => CreateStellarStep::class,
    'cmd:create_arc' => CreateArcStep::class,

    // ── Onboarding (unregistered users) ───────────────────────────────────────
    'onboard:welcome' => OnboardWelcomeStep::class,
    'onboard:create'  => OnboardCreateAccountStep::class,

    // ── Send (P2P USDT/USDC) ─────────────────────────────────────────────────
    'send:start' => SendStartStep::class,
    'send:wallet' => \AptosV2\Steps\Send\WalletStep::class,

    // ── Swap (USDT <-> USDC) ─────────────────────────────────────────────────
    'swap:start'   => SwapStartStep::class,
    'swap:confirm' => SwapConfirmStep::class,

    // ── Sell (USDC/USDT off-ramp + send-naira) ────────────────────────────────
    'sell:entry'   => SellEntryStep::class,
    'sell:wallet'  => \AptosV2\Steps\Sell\WalletStep::class,
    'sell:naira'   => SellNairaEntryStep::class,
    'sell:amount'  => SellAmountStep::class,
    'sell:network' => SellNetworkStep::class,
    'sell:asset'   => SellAssetStep::class,
    'sell:bank'    => SellBankStep::class,
    'sell:ocr'     => SellBankOcrStep::class,
    'sell:ocr_amount' => SellOcrAmountStep::class,

    // ── Deposit (fund wallet & cross-chain offramp) ───────────────────────────
    'deposit:entry'         => DepositEntryStep::class,
    'deposit:method'        => DepositMethodStep::class,
    'deposit:network'       => DepositNetworkStep::class,
    'deposit:destination'   => DepositDestinationStep::class,
    'deposit:asset'         => DepositAssetSelectionStep::class,
    'deposit:1click_amount' => DepositOneClickAmountStep::class,
    'deposit:bank_amount'   => DepositBankOfframpStep::class,
    'deposit:bank_details'  => DepositBankDetailsStep::class,
    'deposit:await_amount'  => DepositAmountStep::class,
    'deposit:solana_asset'  => DepositSolanaAssetStep::class,
    'deposit:solana_amount' => DepositSolanaAmountStep::class,

    // ── Withdraw (cash out) ───────────────────────────────────────────────────
    'withdraw:entry'               => WithdrawEntryStep::class,
    'withdraw:method'              => WithdrawMethodStep::class,
    'withdraw:asset'               => WithdrawAssetStep::class,
    'withdraw:await_crypto_amount' => WithdrawCryptoAmountStep::class,
    'withdraw:await_naira_amount'  => WithdrawNairaAmountStep::class,
    'withdraw:await_bank'          => WithdrawBankStep::class,

    // ── Buy (NGN on-ramp via Centiiv + 1Click) ────────────────────────────────
    'buy:entry'               => BuyEntryStep::class,
    'buy:await_wallet'        => \AptosV2\Steps\Buy\WalletStep::class,
    'buy:await_solana_address' => BuySolanaAddressStep::class,
    'buy:await_amount'        => BuyAmountStep::class,
    'buy:await_asset'         => BuyAssetStep::class,
    'buy:quote_pending'       => BuyQuotePendingStep::class,
    'buy:await_quote_confirm' => BuyQuoteConfirmStep::class,
    'buy:await_opening_order' => BuyOpeningOrderStep::class,

    // ── Polymarket (NGN → Daya → Polygon USDT) ────────────────────────────────
    'polymarket:entry'               => PolymarketEntryStep::class,
    'polymarket:await_address'       => PolymarketAddressStep::class,
    'polymarket:await_amount'        => PolymarketAmountStep::class,
    'polymarket:await_quote_confirm' => PolymarketQuoteConfirmStep::class,

    // ── KYC (tier upgrade for NGN limits) ─────────────────────────────────────
    'kyc:prompt' => KycPromptStep::class,
];
