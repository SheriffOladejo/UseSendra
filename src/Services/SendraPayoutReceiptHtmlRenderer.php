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

    private const TEMPLATE_BUY = 'templates/receipts/buy.html';

    private const TEMPLATE_RATES = 'templates/receipts/rates.html';

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
     * @param array<string, mixed> $model From {@see SendraCentiivBuyReceiptFactory::buildFromOnrampMeta()}
     */
    public static function renderBuyToTempFile(array $model): ?string
    {
        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/' . self::TEMPLATE_BUY;
        if (!is_readable($templatePath)) {
            error_log('SendraPayoutReceiptHtmlRenderer: buy template missing at ' . $templatePath);

            return null;
        }

        $html = self::fillTemplate(file_get_contents($templatePath) ?: '', self::viewModelBuy($model, $root));
        $htmlPath = sys_get_temp_dir() . '/sendra_buy_receipt_' . bin2hex(random_bytes(8)) . '.html';
        $pngPath  = sys_get_temp_dir() . '/sendra_buy_receipt_' . bin2hex(random_bytes(8)) . '.png';
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
     * Rate card PNG. Always tries Chromium (does not require SENDRA_RECEIPT_USE_HTML).
     *
     * @param array<string, mixed> $model From {@see SendraRateCardFactory::build()}
     */
    public static function renderRatesToTempFile(array $model): ?string
    {
        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/' . self::TEMPLATE_RATES;
        if (!is_readable($templatePath)) {
            error_log('SendraPayoutReceiptHtmlRenderer: rates template missing at ' . $templatePath);

            return null;
        }

        $html = self::fillTemplate(file_get_contents($templatePath) ?: '', self::viewModelRates($model, $root));
        $htmlPath = sys_get_temp_dir() . '/sendra_rates_' . bin2hex(random_bytes(8)) . '.html';
        $pngPath  = sys_get_temp_dir() . '/sendra_rates_' . bin2hex(random_bytes(8)) . '.png';
        if (file_put_contents($htmlPath, $html) === false) {
            return null;
        }

        $ok = self::screenshotHtml($htmlPath, $pngPath, 920);
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
        $fiatCurrency = SendraCentiivPayoutReceiptFactory::receiptFiatCurrency($model);
        $amountFiat = is_numeric($amt)
            ? SendraCentiivPayoutReceiptFactory::formatFiatReceiptAmount((float) $amt, $fiatCurrency)
            : '—';

        $withdrawAmt = $model['withdraw_amount'] ?? null;
        $withdrawStr = is_numeric($withdrawAmt)
            ? self::formatCrypto((float) $withdrawAmt, $withdrawAsset)
            : ('— ' . $withdrawAsset);

        $conversionRate = trim((string) ($model['conversion_rate'] ?? ''));
        if ($conversionRate === '' && is_numeric($withdrawAmt) && is_numeric($amt) && (float) $withdrawAmt > 0) {
            $symbol = SendraCentiivPayoutReceiptFactory::fiatCurrencySymbol($fiatCurrency);
            $decimals = in_array($fiatCurrency, ['XOF', 'XAF', 'UGX', 'RWF', 'TZS', 'ETB'], true) ? 0 : 2;
            $conversionRate = $symbol . number_format((float) $amt / (float) $withdrawAmt, $decimals, '.', ',');
        }
        $conversionRate = self::conversionRateNgnOnly($conversionRate);

        $transactionFeeRow = '';
        $feeDisplay = SendraCentiivPayoutReceiptFactory::formatTransactionFeeDisplay($model);
        if ($feeDisplay !== null && $feeDisplay !== '') {
            $transactionFeeRow = '<div class="row"><span class="k">Transaction fees:</span><span class="v">'
                . self::e($feeDisplay) . '</span></div>';
        }

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
            'amount_ngn'       => self::e($amountFiat),
            'withdraw_amount'  => self::e($withdrawStr),
            'conversion_rate'  => self::e($conversionRate),
            'transaction_fee_row' => $transactionFeeRow,
            'bank_name'        => self::e(self::upper(trim((string) ($model['bank_name'] ?? '—')))),
            'account_name'     => self::e(self::upper(trim((string) ($model['account_name'] ?? ($model['recipient'] ?? '—'))))),
            'account_number'   => self::e(trim((string) ($model['account_number'] ?? '—'))),
            'sender_name'      => self::e(trim((string) ($model['sender_name'] ?? SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(false)))),
            'display_reference'=> self::e($ref),
            'bank_remark_row'  => $bankRemarkRow,
            'completed_at'     => self::e(trim((string) ($model['completed_at'] ?? SendraCentiivPayoutReceiptFactory::formatReceiptNow('M d, Y g:i A')))),
        ];
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, string>
     */
    private static function viewModelBuy(array $model, string $root): array
    {
        $asset = strtoupper(trim((string) ($model['receive_asset'] ?? 'USDC')));
        $amt = $model['amount_ngn'] ?? null;
        $amountNgn = is_numeric($amt)
            ? '₦ ' . number_format((float) $amt, abs((float) $amt - round((float) $amt)) < 0.005 ? 0 : 2, '.', ',')
            : '—';

        $recv = $model['receive_amount'] ?? null;
        $recvStr = is_numeric($recv)
            ? self::formatCrypto((float) $recv, $asset)
            : ('— ' . $asset);

        $rate = trim((string) ($model['conversion_rate'] ?? ''));
        if ($rate === '' && is_numeric($recv) && is_numeric($amt) && (float) $recv > 0) {
            $rate = '₦' . number_format((float) $amt / (float) $recv, 2, '.', ',');
        }

        $feeDisplay = SendraCentiivPayoutReceiptFactory::formatTransactionFeeDisplay($model);
        $transactionFeeRow = '';
        if ($feeDisplay !== null && $feeDisplay !== '') {
            $transactionFeeRow = '<div class="row"><span class="k">Transaction fees:</span><span class="v">'
                . self::e($feeDisplay) . '</span></div>';
        }

        $oneclick = trim((string) ($model['oneclick_reference'] ?? ''));
        $oneclickRow = '';
        if ($oneclick !== '') {
            $oneclickRow = '<div class="row"><span class="k">1Click ref:</span><span class="v">'
                . self::e($oneclick) . '</span></div>';
        }

        $fontRegular = self::fontFile($root, 'Inter-Regular.ttf');
        $fontBold    = self::fontFile($root, 'Inter-Bold.ttf');
        $logoPath    = $root . '/assets/images/sendra-logo.png';

        return [
            'logo_uri'          => is_readable($logoPath) ? self::fileUri($logoPath) : '',
            'font_regular_uri'  => $fontRegular !== null ? self::fileUri($fontRegular) : '',
            'font_bold_uri'     => $fontBold !== null ? self::fileUri($fontBold) : '',
            'amount_ngn'        => self::e($amountNgn),
            'receive_amount'    => self::e($recvStr),
            'conversion_rate'   => self::e($rate !== '' ? $rate : '—'),
            'transaction_fee_row' => $transactionFeeRow,
            'payment_method'    => self::e(trim((string) ($model['payment_method'] ?? 'Bank transfer'))),
            'bank_name'         => self::e(self::upper(trim((string) ($model['bank_name'] ?? '—')))),
            'account_name'      => self::e(self::upper(trim((string) ($model['account_name'] ?? '—')))),
            'account_number'    => self::e(trim((string) ($model['account_number'] ?? '—'))),
            'display_reference' => self::e(trim((string) ($model['display_reference'] ?? '—'))),
            'oneclick_row'      => $oneclickRow,
            'completed_at'      => self::e(trim((string) ($model['completed_at'] ?? SendraCentiivPayoutReceiptFactory::formatReceiptNow('M d, Y g:i A')))),
            'status_label'      => self::e(trim((string) ($model['status_label'] ?? 'Successful'))),
        ];
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, string>
     */
    private static function viewModelRates(array $model, string $root): array
    {
        $fontRegular = self::fontFile($root, 'Inter-Regular.ttf');
        $fontBold    = self::fontFile($root, 'Inter-Bold.ttf');
        $logoPath    = $root . '/assets/images/sendra-logo.png';
        $usdcPath    = $root . '/assets/images/usdc.png';
        $usdtPath    = $root . '/assets/images/usdt.png';

        $rowsHtml = '';
        $rows = $model['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pair = self::e((string) ($row['pair'] ?? ''));
                $buy = self::e((string) ($row['buy_display'] ?? '—'));
                $sell = self::e((string) ($row['sell_display'] ?? '—'));
                $left = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($row['left'] ?? '')) ?: 'xx');
                $right = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($row['right'] ?? 'usdc')) ?: 'usdc');
                $leftKind = (string) ($row['left_kind'] ?? 'flag');
                $leftClass = $leftKind === 'coin' ? ('coin-' . $left) : ('flag-' . $left);
                $rowsHtml .= '<div class="rate-row">'
                    . '<div class="pair-cell">'
                    . '<span class="icons">'
                    . '<span class="badge ' . self::e($leftClass) . '" aria-hidden="true"></span>'
                    . '<span class="badge coin-' . self::e($right) . '" aria-hidden="true"></span>'
                    . '</span>'
                    . '<span class="pair-name">' . $pair . '</span>'
                    . '</div>'
                    . '<div class="num buy">' . $buy . '</div>'
                    . '<div class="num sell">' . $sell . '</div>'
                    . '</div>';
            }
        }

        return [
            'logo_uri'         => is_readable($logoPath) ? self::fileUri($logoPath) : '',
            'font_regular_uri' => $fontRegular !== null ? self::fileUri($fontRegular) : '',
            'font_bold_uri'    => $fontBold !== null ? self::fileUri($fontBold) : '',
            'usdc_uri'         => is_readable($usdcPath) ? self::fileUri($usdcPath) : '',
            'usdt_uri'         => is_readable($usdtPath) ? self::fileUri($usdtPath) : '',
            'date_label'       => self::e((string) ($model['date_label'] ?? '')),
            'rows_html'        => $rowsHtml,
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

    private static function screenshotHtml(string $htmlPath, string $pngPath, int $windowHeight = 900): bool
    {
        $chrome = self::resolveChromiumPath();
        if ($chrome === null) {
            error_log('SendraPayoutReceiptHtmlRenderer: Chromium not found (install chromium-browser or set SENDRA_RECEIPT_CHROMIUM_PATH)');

            return false;
        }

        $windowHeight = max(600, min(1400, $windowHeight));
        $url = 'file://' . $htmlPath;
        $scale = max(1, min(3, (int) ($_ENV['SENDRA_RECEIPT_CAPTURE_SCALE'] ?? getenv('SENDRA_RECEIPT_CAPTURE_SCALE') ?: self::CAPTURE_SCALE)));
        $timeoutSec = max(5, min(60, (int) ($_ENV['SENDRA_RECEIPT_CHROMIUM_TIMEOUT'] ?? getenv('SENDRA_RECEIPT_CHROMIUM_TIMEOUT') ?: 20)));
        $userDataDir = sys_get_temp_dir() . '/sendra-chromium-' . getmyuid();
        if (!is_dir($userDataDir)) {
            @mkdir($userDataDir, 0700, true);
        }
        $chromeCmd = 'HOME=' . escapeshellarg(sys_get_temp_dir())
            . ' ' . escapeshellarg($chrome)
            . ' --headless --disable-gpu --hide-scrollbars --no-sandbox'
            . ' --user-data-dir=' . escapeshellarg($userDataDir)
            . ' --force-device-scale-factor=' . $scale
            . ' --window-size=' . self::OUTPUT_WIDTH . ',' . $windowHeight
            . ' --screenshot=' . escapeshellarg($pngPath)
            . ' ' . escapeshellarg($url)
            . ' 2>&1';
        // Snap Chromium can hang under AppArmor; never block the WhatsApp worker unbounded.
        $cmd = 'timeout ' . $timeoutSec . 's ' . $chromeCmd;

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            error_log('SendraPayoutReceiptHtmlRenderer: chromium failed (exit ' . $code . '): ' . implode("\n", $out));

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
