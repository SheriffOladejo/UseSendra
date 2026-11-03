<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use App\Services\SendraRateCardDelivery;
use App\Services\SendraRateCardFactory;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class RateStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        // Always ack immediately — webhook already returned 200 to Meta, so any
        // later failure would otherwise look like "no response".
        $this->reply($ctx, '📊 Fetching live rates...');
        self::log('start from=' . $ctx->from);

        try {
            $model = SendraRateCardFactory::build();
            if (empty($model['success']) || ($model['rows'] ?? []) === []) {
                $err = (string) ($model['error'] ?? 'fetch_failed');
                self::log('fetch_failed from=' . $ctx->from . ' err=' . $err);
                $this->reply($ctx, '❌ Could not load live rates right now. Please try again in a moment.');

                return StepResult::complete(['action' => 'rate', 'error' => $err]);
            }

            $result = SendraRateCardDelivery::trySend($ctx->whatsapp(), $ctx->from, $model);
            if (!empty($result['sent_image'])) {
                self::log('sent_image from=' . $ctx->from);

                return StepResult::complete(['action' => 'rate', 'via' => 'image']);
            }

            self::log('image_failed from=' . $ctx->from . '; sending text fallback');
            if (is_string($model['text'] ?? null) && $model['text'] !== '') {
                $this->reply($ctx, $model['text']);

                return StepResult::complete(['action' => 'rate', 'via' => 'text']);
            }

            $this->reply($ctx, '❌ Could not load live rates right now. Please try again in a moment.');

            return StepResult::complete(['action' => 'rate', 'error' => 'deliver_failed']);
        } catch (\Throwable $e) {
            self::log('exception from=' . $ctx->from . ' ' . $e->getMessage());
            error_log('RateStep exception: ' . $e->getMessage());
            try {
                $this->reply($ctx, '❌ Could not load live rates right now. Please try again in a moment.');
            } catch (\Throwable $ignored) {
                // ignore secondary send failures
            }

            return StepResult::complete(['action' => 'rate', 'error' => 'exception']);
        }
    }

    private static function log(string $line): void
    {
        $dir = dirname(__DIR__, 4) . '/var/log';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/rate_card.log',
            date('c') . ' ' . $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
