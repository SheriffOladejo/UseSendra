<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Services\BankAccountOcrService;
use App\Services\SendraCentiivRampService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\ImagePayload;

/**
 * Idle image: OCR NGN account screenshot, then pick USDT/USDC or ask for amount.
 */
final class BankOcrStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $payload = ImagePayload::parse($ctx->message);
        if ($payload === null) {
            return StepResult::complete(['action' => 'ocr_not_image']);
        }

        $agg = new SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            $this->reply($ctx, "❌ Cash-out isn't available right now. Try again later.");

            return StepResult::complete(['action' => 'ocr_unconfigured']);
        }

        $ocr = new BankAccountOcrService();
        if (!$ocr->isEnabled()) {
            $this->reply(
                $ctx,
                "📷 Photo reading needs a Google Gemini API key (SENDRA_BANK_OCR_GEMINI_API_KEY).\n\n" .
                "Get one at aistudio.google.com/apikey, or use:\n`send 10000 naira to 0123456789 GTBank`"
            );

            return StepResult::complete(['action' => 'ocr_disabled']);
        }

        $this->reply($ctx, '📷 Reading account details from your image…');
        $tmp = $ctx->whatsapp()->downloadMediaToFile($payload['id']);
        if ($tmp === null) {
            $this->reply(
                $ctx,
                "📷 Couldn't load that image. Please try again with a clear screenshot of the account number and bank name."
            );

            return StepResult::complete(['action' => 'ocr_download_failed']);
        }

        try {
            $extracted = $ocr->extractFromImageFile($tmp, $payload['caption']);
        } finally {
            @unlink($tmp);
        }

        if ($extracted === null) {
            return $this->ocrFailed($ctx, $ocr->getLastError());
        }

        $account = (string) $extracted['account_number'];
        $bankGuess = (string) $extracted['bank_name'];
        $sellCtx = [
            'flow' => 'sell',
            'fiat' => 'NGN',
            'ocr_source' => true,
        ];

        $bank = new BankService($ctx->whatsapp());
        if (!$bank->verifyAndPopulate($ctx->from, $sellCtx, $account, $bankGuess, $agg)) {
            return StepResult::complete(['action' => 'ocr_verify_failed']);
        }

        $captionAmount = ImagePayload::parseCaptionNairaAmount($payload['caption']);
        if ($captionAmount !== null) {
            $n = number_format($captionAmount, 0, '.', ',');
            $this->buttons(
                $ctx,
                "Send *≈ ₦{$n}* to `{$account}` (*" . (string) ($sellCtx['bank_name'] ?? $bankGuess) . "*).\n\nWhich balance should we use?",
                [
                    'sell_pick_usdt' => 'USDT',
                    'sell_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::transition('sell', 'asset', [
                'fiat' => 'NGN',
                'target_receive_ngn' => $captionAmount,
                'preset_account' => $account,
                'preset_bank_guess' => (string) ($sellCtx['bank_name'] ?? $bankGuess),
                'account_name' => (string) ($sellCtx['account_name'] ?? ''),
                'account_number' => $account,
                'bank_name' => (string) ($sellCtx['bank_name'] ?? $bankGuess),
                'ocr_source' => true,
            ], ['action' => 'ocr_proceed_asset']);
        }

        $name = (string) ($sellCtx['account_name'] ?? '');
        $bankName = (string) ($sellCtx['bank_name'] ?? $bankGuess);
        $this->reply(
            $ctx,
            "✅ *Account found*\n\n" .
            "• *{$name}*\n" .
            "• `{$account}` — *{$bankName}*\n\n" .
            "How much Naira do you want to send?\n(e.g. *10000* or *10000 naira*)\n\n*cancel* stops."
        );

        return StepResult::transition('sell', 'ocr_amount', [
            'fiat' => 'NGN',
            'preset_account' => $account,
            'preset_bank_guess' => $bankName,
            'account_name' => $name,
            'account_number' => $account,
            'bank_name' => $bankName,
            'ocr_source' => true,
        ], ['action' => 'ocr_await_amount']);
    }

    private function ocrFailed(StepContext $ctx, ?string $ocrErr): StepResult
    {
        if ($ocrErr === 'api_quota') {
            $this->reply(
                $ctx,
                "📷 Photo reading is unavailable — the Gemini API free quota is used up.\n\n" .
                "Check usage at aistudio.google.com, or cash out with:\n`send 10000 naira to 0227912723 GTBank`"
            );

            return StepResult::complete(['action' => 'ocr_api_quota']);
        }
        if (in_array($ocrErr, ['api_key', 'api_error'], true)) {
            error_log('Sendra bank OCR: failed (' . ($ocrErr ?? '') . ') for ' . $ctx->from);
            $this->reply(
                $ctx,
                "📷 Photo reading is temporarily unavailable (server OCR config).\n\n" .
                "You can still cash out with:\n`send 10000 naira to 0123456789 GTBank`"
            );

            return StepResult::complete(['action' => 'ocr_api_failed']);
        }

        $this->reply(
            $ctx,
            "📷 I couldn't find a valid *10-digit account number* and *bank name* in that image.\n\n" .
            "Use a clear photo of the account number and bank (app screenshot works best).\n" .
            "Or send:\n`send 10000 naira to 0123456789 GTBank`"
        );

        return StepResult::complete(['action' => 'ocr_parse_failed']);
    }
}
