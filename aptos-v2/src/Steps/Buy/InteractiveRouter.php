<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use App\Services\SendraBuyQuoteJobService;
use AptosV2\Persistence\ChatStateRepository;

/**
 * Routes buy-flow interactive button taps to the correct step even when
 * chat_context is briefly stale (quote_pending while job already done, etc.).
 */
final class InteractiveRouter
{
    /**
     * @param array<string, mixed> $state
     * @return array{0: string, 1: string, 2: array<string, mixed>}|null flow, step, state
     */
    public static function resolve(
        string $phone,
        string $message,
        string $messageLower,
        array $state,
        ChatStateRepository $chatState,
        \PDO $db,
    ): ?array {
        if (!self::isBuyInteractive($message, $messageLower)) {
            return null;
        }

        $fresh = $chatState->load($phone);
        if ($fresh !== []) {
            $state = $fresh;
        }

        $flow = (string) ($state['flow'] ?? '');
        if ($flow !== 'buy') {
            return null;
        }

        $step = (string) ($state['step'] ?? '');

        if ($step === 'await_quote_confirm' || $step === 'await_opening_order') {
            return [$flow, $step, $state];
        }

        if ($step === 'await_asset' && in_array($messageLower, ['buy_pick_usdt', 'buy_pick_usdc', 'usdt', 'usdc'], true)) {
            return [$flow, $step, $state];
        }
        if ($step === 'await_wallet' && in_array($messageLower, ['wallet_aptos', 'wallet_stellar', 'wallet_solana', 'wallet_polygon', 'aptos', 'stellar', 'solana', 'polygon'], true)) {
            return [$flow, $step, $state];
        }

        if ($step === 'quote_pending' && self::isQuoteConfirmTap($message, $messageLower)) {
            $merged = self::mergeDoneQuoteJobState($phone, $state, $db);
            if ($merged !== null) {
                $chatState->save($phone, $merged);

                return ['buy', 'await_quote_confirm', $merged];
            }
        }

        if (self::isQuoteConfirmTap($message, $messageLower) && $step !== 'await_quote_confirm') {
            $merged = self::mergeDoneQuoteJobState($phone, $state, $db);
            if ($merged !== null) {
                $chatState->save($phone, $merged);

                return ['buy', 'await_quote_confirm', $merged];
            }
        }

        return null;
    }

    public static function isBuyInteractive(string $message, string $messageLower): bool
    {
        if (in_array($messageLower, ['buy_quote_yes', 'buy_quote_change', 'buy_pick_usdt', 'buy_pick_usdc', 'usdt', 'usdc', 'wallet_aptos', 'wallet_stellar', 'wallet_solana', 'wallet_polygon'], true)) {
            return true;
        }

        return IntentParser::messageMeansYes($message, $messageLower);
    }

    public static function isQuoteConfirmTap(string $message, string $messageLower): bool
    {
        return IntentParser::messageMeansYes($message, $messageLower)
            || $messageLower === 'buy_quote_change';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    public static function mergeDoneQuoteJobState(string $phone, array $state, \PDO $db): ?array
    {
        $jobs = new SendraBuyQuoteJobService($db);
        if (!$jobs->isAvailable()) {
            return null;
        }

        $jobId = (string) ($state['quote_job_id'] ?? '');
        $job = $jobId !== '' ? $jobs->findById($jobId) : null;
        if ($job === null || ($job['status'] ?? '') !== 'done') {
            $job = $jobs->findLatestDoneJobForPhone($phone);
        }
        if ($job === null || ($job['status'] ?? '') !== 'done') {
            return null;
        }

        $resultRaw = $job['result_json'] ?? null;
        $result = is_string($resultRaw) ? json_decode($resultRaw, true) : $resultRaw;
        if (!is_array($result)) {
            return null;
        }

        $legacy = is_array($result['legacy_quote'] ?? null) ? $result['legacy_quote'] : [];
        $mode = (string) ($job['quote_mode'] ?? 'naira');
        $asset = strtoupper((string) ($job['asset'] ?? 'USDC'));

        $merged = array_merge($state, [
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'fiat' => 'NGN',
            'quote_job_id' => (string) ($job['id'] ?? $jobId),
            'quote_mode' => $mode,
            'pay_ngn' => (float) ($legacy['pay_ngn'] ?? $job['pay_ngn'] ?? 0),
            'asset' => $asset,
            'receive_crypto_display' => (string) ($legacy['receive_crypto_display'] ?? '—'),
            'centiiv_usdc' => $legacy['centiiv_usdc'] ?? null,
            'oneclick_dry_quote_data' => $legacy['oneclick_dry_quote_data'] ?? null,
        ]);
        if ($mode === 'crypto_target') {
            $merged['target_crypto'] = (float) ($job['target_crypto'] ?? 0);
        }

        return $merged;
    }
}
