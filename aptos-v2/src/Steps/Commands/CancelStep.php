<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\UserRepository;
use AptosV2\Services\Buy\QuoteService;

final class CancelStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quotes->cancelActiveForPhone($ctx->from);

        $pendingId = $this->resolvePendingId($ctx);

        if ($pendingId !== null) {
            /** @var PendingTransactionRepository $pending */
            $pending = $ctx->services->get('pending_tx');
            $pending->cancel($pendingId);

            /** @var UserRepository $users */
            $users = $ctx->services->get('users');
            $users->clearPendingTransaction($ctx->from);
        }

        $this->reply($ctx, "✅ Transaction cancelled.\n\nType *menu* for options.");

        return StepResult::complete(['action' => 'cancelled']);
    }

    private function resolvePendingId(StepContext $ctx): ?string
    {
        if (str_starts_with($ctx->messageLower, 'cancel_')) {
            $id = substr($ctx->message, 7);

            return trim($id) !== '' ? trim($id) : null;
        }

        if ($ctx->messageLower === 'cancel') {
            $id = $ctx->user['pending_tx_id'] ?? null;

            return is_string($id) && trim($id) !== '' ? trim($id) : null;
        }

        return null;
    }
}
