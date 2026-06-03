<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders payout receipts from {@see templates/receipts/payout.html} via headless Chromium.
 * Enable with SENDRA_RECEIPT_USE_HTML=1 (falls back to GD when Chromium is missing).
 */
final class SendraPayoutReceiptHtmlRenderer
{
    private const TEMPLATE = 'templates/receipts/payout.html';

    /** Receipt width (px); capture at 2× then downscale for smooth rounded corners. */
    private const OUTPUT_WIDTH = 432;

    private const CAPTURE_SCALE = 2;

    public static function isEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_RECEIPT_USE_HTML'] ?? getenv('SENDRA_RECEIPT_USE_HTML') ?: '')));

        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $model
     */
    public static function renderToTempFile(array $model): ?string
    {
        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/' . self::TEMPLATE;
        if (!is_readable($templatePath)) {
            error_log('SendraPayoutReceiptHtmlRenderer: template missing at ' . $templatePath);

            return null;
        }

        $html = self::fillTemplate(file_get_contents($templatePath) ?: '', self::viewModel($model, $root));
        $htmlPath = sys_get_temp_dir() . '/sendra_receipt_' . bin2hex(random_bytes(8)) . '.html';
        $pngPath  = sys_get_temp_dir() . '/sendra_receipt_' . bin2hex(random_bytes(8)) . '.png';
        if (file_put_contents($htmlPath, $html) === false) {
            return null;
        }

        $ok = self::screenshotHtml($htmlPath, $pngPath);
        @unlink($htmlPath);
        if (!$ok || !is_readable($pngPath)) {
            @unlink($pngPath);

            return null;
        }

        return $pngPath;
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, string>
     */
    private static function viewModel(array $model, string $root): array
    {
        $withdrawAsset = strtoupper(trim((string) ($model['withdraw_asset'] ?? 'USDC')));
        $feeAsset      = strtoupper(trim((string) ($model['fee_asset'] ?? $withdrawAsset)));

        $amt = $model['amount_ngn'] ?? null;
        $amountNgn = is_numeric($amt)
            ? '₦ ' . number_format((float) $amt, abs((float) $amt - round((float) $amt)) < 0.005 ? 0 : 2, '.', ',')
            : '—';

        $withdrawAmt = $model['withdraw_amount'] ?? null;
        $withdrawStr = is_numeric($withdrawAmt)
            ? self::formatCrypto((float) $withdrawAmt, $withdrawAsset)
            : ('— ' . $withdrawAsset);

        $conversionRate = trim((string) ($model['conversion_rate'] ?? ''));
        if ($conversionRate === '' && is_numeric($withdrawAmt) && is_numeric($amt) && (float) $withdrawAmt > 0) {
            $conversionRate = '₦' . number_format((float) $amt / (float) $withdrawAmt, 2, '.', ',');
        }
        $conversionRate = self::conversionRateNgnOnly($conversionRate);

        $ref = trim((string) ($model['display_reference'] ?? ''));
        if ($ref === '') {
            $ref = trim((string) ($model['session_id'] ?? ''));
            if ($ref === '') {
                $ref = (string) ($model['transaction_id'] ?? '—');
            }
        }

        $bankRemark = trim((string) ($model['bank_remark'] ?? ''));
        $bankRemarkRow = '';
        if ($bankRemark !== '' && stripos($bankRemark, $ref) === false) {
            $bankRemarkRow = '<div class="row"><span class="k">Opay remark:</span><span class="v">'
                . self::e($bankRemark) . '</span></div>';
        }

        $fontRegular = self::fontFile($root, 'Inter-Regular.ttf');
        $fontBold    = self::fontFile($root, 'Inter-Bold.ttf');
        $logoPath    = $root . '/assets/images/sendra-logo.png';

        return [
            'logo_uri'         => is_readable($logoPath) ? self::fileUri($logoPath) : '',
            'font_regular_uri' => $fontRegular !== null ? self::fileUri($fontRegular) : '',
            'font_bold_uri'    => $fontBold !== null ? self::fileUri($fontBold) : '',
            'amount_ngn'       => self::e($amountNgn),
            'withdraw_amount'  => self::e($withdrawStr),
            'conversion_rate'  => self::e($conversionRate),
            'bank_name'        => self::e(self::upper(trim((string) ($model['bank_name'] ?? '—')))),
            'account_name'     => self::e(self::upper(trim((string) ($model['account_name'] ?? ($model['recipient'] ?? '—'))))),
            'account_number'   => self::e(trim((string) ($model['account_number'] ?? '—'))),
            'sender_name'      => self::e(trim((string) ($model['sender_name'] ?? 'Nomba Financial Services'))),
            'display_reference'=> self::e($ref),
            'bank_remark_row'  => $bankRemarkRow,
            'completed_at'     => self::e(trim((string) ($model['completed_at'] ?? SendraCentiivPayoutReceiptFactory::formatReceiptNow('M d, Y g:i A')))),
        ];
    }

    private static function formatCrypto(float $amount, string $asset): string
    {
        $s = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');

        return ($s !== '' ? $s : '0') . ' ' . $asset;
    }

    private static function formatCryptoCompact(float $amount, string $asset): string
    {
        $s = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');

        return ($s !== '' ? $s : '0') . $asset;
    }

    private static function upper(string $s): string
    {
        return $s === '' || $s === '—' ? $s : strtoupper($s);
    }

    /** Strip legacy " / USDC" suffix; receipt shows Naira rate only. */
    private static function conversionRateNgnOnly(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        if (preg_match('#^(.+?)\s*/\s*[A-Za-z0-9]+$#u', $value, $m)) {
            return trim($m[1]);
        }

        return $value;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, string> $vars
     */
    private static function fillTemplate(string $html, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $html = str_replace('{{' . $key . '}}', $value, $html);
        }

        return $html;
    }

    private static function fontFile(string $root, string $filename): ?string
    {
        $p = $root . '/assets/fonts/extras/ttf/' . $filename;
        if (is_readable($p)) {
            return $p;
        }

        return null;
    }

    private static function fileUri(string $path): string
    {
        $parts = array_map('rawurlencode', explode('/', $path));

        return 'file://' . implode('/', $parts);
    }

    private static function screenshotHtml(string $htmlPath, string $pngPath): bool
    {
        $chrome = self::resolveChromiumPath();
        if ($chrome === null) {
            error_log('SendraPayoutReceiptHtmlRenderer: Chromium not found (install chromium-browser or set SENDRA_RECEIPT_CHROMIUM_PATH)');

            return false;
        }

        $url = 'file://' . $htmlPath;
        $scale = max(1, min(3, (int) ($_ENV['SENDRA_RECEIPT_CAPTURE_SCALE'] ?? getenv('SENDRA_RECEIPT_CAPTURE_SCALE') ?: self::CAPTURE_SCALE)));
        $cmd = escapeshellarg($chrome)
            . ' --headless --disable-gpu --hide-scrollbars --no-sandbox'
            . ' --force-device-scale-factor=' . $scale
            . ' --window-size=' . self::OUTPUT_WIDTH . ',900'
            . ' --screenshot=' . escapeshellarg($pngPath)
            . ' ' . escapeshellarg($url)
            . ' 2>&1';

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            error_log('SendraPayoutReceiptHtmlRenderer: chromium failed: ' . implode("\n", $out));

            return false;
        }

        if (!is_readable($pngPath)) {
            return false;
        }

        if ($scale > 1) {
            self::downscalePngToWidth($pngPath, self::OUTPUT_WIDTH);
        }

        return is_readable($pngPath);
    }

    private static function downscalePngToWidth(string $path, int $targetWidth): void
    {
        if (!extension_loaded('gd')) {
            return;
        }
        $src = @imagecreatefrompng($path);
        if ($src === false) {
            return;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= $targetWidth) {
            imagedestroy($src);

            return;
        }
        $targetHeight = max(1, (int) round($sh * ($targetWidth / $sw)));
        $dest         = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($dest === false) {
            imagedestroy($src);

            return;
        }
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
        imagealphablending($dest, true);
        imagecopyresampled($dest, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $sw, $sh);
        imagedestroy($src);
        imagepng($dest, $path, 6);
        imagedestroy($dest);
    }

    private static function resolveChromiumPath(): ?string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_CHROMIUM_PATH'] ?? getenv('SENDRA_RECEIPT_CHROMIUM_PATH') ?: ''));
        if ($env !== '' && is_executable($env)) {
            return $env;
        }

        foreach (['chromium-browser', 'chromium', 'google-chrome', 'google-chrome-stable'] as $bin) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
