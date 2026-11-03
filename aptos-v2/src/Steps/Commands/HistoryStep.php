<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Persistence\TransactionHistoryRepository;

final class HistoryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        try {
            /** @var TransactionHistoryRepository $history */
            $history = $ctx->services->get('tx_history');
            $rows = $history->recentForPhone($ctx->from);
        } catch (\PDOException $e) {
            error_log('HistoryStep: ' . $e->getMessage());
            $this->reply($ctx, '❌ Could not load history. Please try again later.');

            return StepResult::complete(['action' => 'history', 'error' => 'db_error']);
        }

        if ($rows === []) {
            $this->reply(
                $ctx,
                "📭 *No transactions yet.*\n\n" .
                'Send or receive USDC/USDT to see your history here.'
            );

            return StepResult::complete(['action' => 'history']);
        }

        $lines = "*📋 Recent Transactions*\n\n";
        foreach ($rows as $tx) {
            $arrow = ($tx['direction'] ?? '') === 'out' ? '📤 Sent' : '📥 Received';
            $label = ($tx['direction'] ?? '') === 'out' ? 'To' : 'From';
            $counterparty = (string) ($tx['counterparty'] ?? '');
            $short = strlen($counterparty) > 10
                ? substr($counterparty, 0, 6) . '...' . substr($counterparty, -4)
                : $counterparty;
            $date = date('M d, g:ia', strtotime((string) ($tx['created_at'] ?? 'now')));
            $lines .= "{$arrow} *{$tx['amount']} {$tx['currency']}*\n";
            $lines .= "{$label}: `{$short}`\n";
            $lines .= "_{$date}_\n\n";
        }

        $this->reply($ctx, rtrim($lines));

        return StepResult::complete(['action' => 'history']);
    }
}
