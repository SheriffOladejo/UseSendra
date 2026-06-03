<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders Sendra withdrawal receipt PNGs for WhatsApp ({@see WhatsAppService::sendImage()}).
 * Layout matches the 432×844 mobile reference (Sendra × Centiiv payout).
 */
final class SendraPayoutReceiptImageService
{
    /** Reference artwork width (px). */
    private const WIDTH = 432;

    private const PAD = 20;

    private const LABEL_COL_W = 132;

    private const HERO_H = 118;

    private const HERO_RADIUS = 14;

    /** Brand / hero / value green (#0F3E2E). */
    private const BRAND_R = 15;
    private const BRAND_G = 62;
    private const BRAND_B = 46;

    /**
     * @param list<array{label: string, time: string}> $timeline
     */
    private static function estimateHeight(
        string $ref,
        string $font,
        int $innerW
    ): int {
        $contentW = max(80, $innerW);
        $refLines = count(self::wrapToWidth($ref, $font, 12, $contentW - self::LABEL_COL_W - 8));

        $yy = 0;
        $yy += 18;  // top
        $yy += 36;  // logo
        $yy += 28;  // title gap + title
        $yy += 14;  // gap
        $yy += self::HERO_H;
        $yy += 22;  // after hero
        $yy += 28 + 36 * 2 + 24; // transaction summary
        $yy += 28 + 36 * 3 + 24; // receiver
        $yy += 36 * 2 + 24 + $refLines * 26; // sender + ref
        $yy += 30;  // date
        $yy += 56;  // footer

        return max(844, $yy + 48);
    }

    /**
     * @param array<string, mixed> $model From {@see SendraCentiivPayoutReceiptFactory::build()}
     * @return string|null Absolute path to temp .png, or null on failure (caller should unlink)
     */
    public static function renderToTempFile(array $model): ?string
    {
        if (SendraPayoutReceiptHtmlRenderer::isEnabled()) {
            $htmlPath = SendraPayoutReceiptHtmlRenderer::renderToTempFile($model);
            if ($htmlPath !== null) {
                return $htmlPath;
            }
            error_log('SendraPayoutReceiptImageService: HTML renderer failed, falling back to GD');
        }

        if (!extension_loaded('gd')) {
            error_log('SendraPayoutReceiptImageService: PHP GD extension required');

            return null;
        }

        $font = self::resolveFontPath();
        if ($font === null) {
            error_log('SendraPayoutReceiptImageService: no TTF font found (set SENDRA_RECEIPT_FONT_PATH)');

            return null;
        }
        $fontBold = self::resolveFontBoldPath($font) ?? $font;

        $bankName      = trim((string) ($model['bank_name']     ?? ''));
        $accountName   = trim((string) ($model['account_name']  ?? ($model['recipient'] ?? '—')));
        $accountNumber = trim((string) ($model['account_number'] ?? ''));
        $senderName    = trim((string) ($model['sender_name']   ?? 'Nomba Financial Services'));

        $tx        = (string) ($model['transaction_id'] ?? '—');
        $when      = (string) ($model['completed_at']   ?? '');
        $sessionId = trim((string) ($model['session_id']  ?? ''));
        $bridge    = trim((string) ($model['bridge_note']  ?? ''));

        $amt = $model['amount_ngn'] ?? null;
        $amountStr = is_numeric($amt)
            ? self::formatNgnHeroAmount((float) $amt)
            : '—';

        $withdrawAsset = strtoupper(trim((string) ($model['withdraw_asset'] ?? 'USDC')));
        $withdrawAmt   = $model['withdraw_amount'] ?? null;
        $withdrawStr   = is_numeric($withdrawAmt)
            ? self::formatCryptoAmount((float) $withdrawAmt, $withdrawAsset)
            : ('— ' . $withdrawAsset);

        $conversionRate = trim((string) ($model['conversion_rate'] ?? ''));
        if ($conversionRate === '' && is_numeric($withdrawAmt) && is_numeric($amt) && (float) $withdrawAmt > 0) {
            $conversionRate = '₦' . number_format((float) $amt / (float) $withdrawAmt, 2, '.', ',');
        }
        $conversionRate = self::conversionRateNgnOnly($conversionRate);

        $innerW   = self::WIDTH - self::PAD * 2;
        $whenDisp = $when !== ''
            ? $when
            : SendraCentiivPayoutReceiptFactory::formatReceiptNow('M d, Y g:i A');

        $refValue = trim((string) ($model['display_reference'] ?? ''));
        if ($refValue === '') {
            $refValue = $sessionId !== '' ? $sessionId : ($tx !== '' ? $tx : '—');
            if ($bridge !== '') {
                $refValue = preg_replace('/^Crypto bridge\s*\(1Click\):\s*/i', '', $bridge) ?: $bridge;
            }
        }

        $height = self::estimateHeight($refValue, $font, $innerW);

        $im = imagecreatetruecolor(self::WIDTH, $height);
        if ($im === false) {
            return null;
        }

        imagealphablending($im, true);
        imagesavealpha($im, true);

        $cBg         = self::color($im, 255, 255, 255);
        $cBrand      = self::color($im, self::BRAND_R, self::BRAND_G, self::BRAND_B);
        $cHeading    = self::color($im, 18,  25,  35);
        $cLabel      = self::color($im, 110, 118, 128);
        $cDivider    = self::color($im, 196, 204, 212);
        $cWhite      = self::color($im, 255, 255, 255);
        $cHeroLabel  = self::color($im, 184, 212, 203);
        $cLogoTeal   = self::color($im, 46,  178, 140);
        $cUsdcBlue   = self::color($im, 39,  117, 202);
        $cPattern    = imagecolorallocatealpha($im, 255, 255, 255, 108);

        imagefilledrectangle($im, 0, 0, self::WIDTH, $height, $cBg);

        $ix = self::PAD;
        $iw = $innerW;
        $yy = 16.0;

        // ── Logo (wordmark image only — no separate text) ───────────────────
        $markH  = 36;
        $markW  = self::sendraLogoWidthForHeight($markH);
        $logoX  = (int) ((self::WIDTH - $markW) / 2);
        $markTop = (int) $yy;
        if (self::drawSendraLogoImage($im, $logoX, $markTop, $markH) === null) {
            self::drawSendraLogoMark($im, $logoX, $markTop, 24, $cLogoTeal, $cBrand);
            $markW = 24;
        }
        $yy += $markH + 12;

        // ── Title ────────────────────────────────────────────────────────────
        self::ttfCenter($im, $fontBold, 20, $cBrand, (int) (self::WIDTH / 2), (int) ($yy + 18), 'Withdrawal receipt');
        $yy += 34;

        // ── Hero card ────────────────────────────────────────────────────────
        $cardX1 = self::PAD;
        $cardY1 = (int) $yy;
        $cardX2 = self::WIDTH - self::PAD;
        $cardY2 = $cardY1 + self::HERO_H;

        self::filledRoundRect($im, $cardX1, $cardY1, $cardX2, $cardY2, self::HERO_RADIUS, $cBrand);
        self::drawHeroPattern($im, $cardX1, $cardY1, $cardX2, $cardY2, $cPattern);

        self::ttfCenter($im, $font, 12, $cHeroLabel, (int) (self::WIDTH / 2), $cardY1 + 30, 'You withdrew:');

        $heroSize = strlen($amountStr) > 12 ? 34 : 40;
        self::ttfCenter($im, $fontBold, (float) $heroSize, $cWhite, (int) (self::WIDTH / 2), $cardY2 - 22, $amountStr);

        $yy += self::HERO_H + 32;

        // ── Transaction summary ────────────────────────────────────────────
        self::ttf($im, $fontBold, 14, $cHeading, $ix, (int) $yy, 'Transaction summary');
        $yy += 28;

        $yy = (float) self::kvRowWithIcon(
            $im, $font, $fontBold, $ix, (int) $yy, $iw,
            'Amount (USD):', $withdrawStr, $cLabel, $cBrand, $cUsdcBlue, $cWhite
        );
        $yy = (float) self::kvRow(
            $im, $font, $fontBold, $ix, (int) $yy, $iw,
            'Conversion rate:', $conversionRate, $cLabel, $cBrand
        );
        self::dottedHLine($im, $ix, (int) $yy, $ix + $iw, $cDivider);
        $yy += 24;

        // ── Receiver's details ─────────────────────────────────────────────
        self::ttf($im, $fontBold, 14, $cHeading, $ix, (int) $yy, "Receiver's details");
        $yy += 28;

        $bn  = $bankName      !== '' ? $bankName      : '—';
        $an  = $accountName   !== '' ? $accountName   : '—';
        $acn = $accountNumber !== '' ? $accountNumber : '—';

        $yy = (float) self::kvRow($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Bank Name', self::displayBankName($bn), $cLabel, $cBrand);
        $yy = (float) self::kvRow($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Account name:', self::displayPersonName($an), $cLabel, $cBrand);
        $yy = (float) self::kvRow($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Account number:', $acn, $cLabel, $cBrand);
        self::dottedHLine($im, $ix, (int) $yy, $ix + $iw, $cDivider);
        $yy += 24;

        $yy = (float) self::kvRow($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Sender:', $senderName !== '' ? $senderName : 'Nomba Financial Services', $cLabel, $cBrand);
        $yy = (float) self::kvRowWrapped($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Reference:', $refValue, $cLabel, $cBrand);
        self::dottedHLine($im, $ix, (int) $yy, $ix + $iw, $cDivider);
        $yy += 24;

        $yy = (float) self::kvRow($im, $font, $fontBold, $ix, (int) $yy, $iw, 'Date:', $whenDisp, $cLabel, $cBrand);

        $footerY1 = $height - 52;
        self::ttfCenter($im, $fontBold, 11, $cBrand, (int) (self::WIDTH / 2), $footerY1, 'THANK YOU FOR CHOOSING');
        self::ttfCenter($im, $fontBold, 11, $cBrand, (int) (self::WIDTH / 2), $footerY1 + 18, 'SENDRA!!');

        $path = sys_get_temp_dir() . '/sendra_payout_receipt_' . bin2hex(random_bytes(8)) . '.png';
        if (!imagepng($im, $path, 6)) {
            imagedestroy($im);

            return null;
        }
        imagedestroy($im);

        return $path;
    }

    private static function kvRow(
        \GdImage $im,
        string $font,
        string $fontBold,
        int $x,
        int $yy,
        int $w,
        string $k,
        string $v,
        int $cK,
        int $cV
    ): int {
        $labelSize = 13;
        $valueSize = 13;
        $lineH     = 34;
        $valueW    = max(100, $w - self::LABEL_COL_W - 8);
        $lines     = self::wrapToWidth($v, $fontBold, $valueSize, $valueW);
        $blockH    = max($lineH, count($lines) * $lineH);

        self::ttf($im, $font, $labelSize, $cK, $x, $yy, $k);

        $vRightEdge = $x + $w;
        $yLine      = (float) $yy;
        foreach ($lines as $ln) {
            $tw = self::textPixelWidth($fontBold, $valueSize, $ln);
            self::ttf($im, $fontBold, $valueSize, $cV, (int) ($vRightEdge - $tw), (int) $yLine, $ln);
            $yLine += $lineH;
        }

        return $yy + $blockH + 12;
    }

    private static function kvRowWithIcon(
        \GdImage $im,
        string $font,
        string $fontBold,
        int $x,
        int $yy,
        int $w,
        string $k,
        string $v,
        int $cK,
        int $cV,
        int $cBlue,
        int $cWhite
    ): int {
        $labelSize = 13;
        $valueSize = 13;
        $lineH     = 34;
        $iconR     = 9;
        $iconGap   = 6;
        $iconBlock = $iconR * 2 + $iconGap;
        $valueW    = max(80, $w - self::LABEL_COL_W - $iconBlock - 8);
        $lines     = self::wrapToWidth($v, $fontBold, $valueSize, $valueW);
        $blockH    = max($lineH, count($lines) * $lineH);

        self::ttf($im, $font, $labelSize, $cK, $x, $yy, $k);

        $vRightEdge = $x + $w;
        $yLine      = (float) $yy;
        foreach ($lines as $i => $ln) {
            $tw = self::textPixelWidth($fontBold, $valueSize, $ln);
            $vx = (int) ($vRightEdge - $tw);
            if ($i === 0) {
                $iconCx = $vx - $iconGap - $iconR;
                $iconCy = (int) ($yLine - (int) ($valueSize * 0.52));
                self::drawUsdcIcon($im, $iconCx, $iconCy, $iconR, $cBlue, $cWhite);
            }
            self::ttf($im, $fontBold, $valueSize, $cV, $vx, (int) $yLine, $ln);
            $yLine += $lineH;
        }

        return $yy + $blockH + 4;
    }

    private static function kvRowWrapped(
        \GdImage $im,
        string $font,
        string $fontBold,
        int $x,
        int $yy,
        int $w,
        string $k,
        string $v,
        int $cK,
        int $cV
    ): int {
        $labelSize = 13;
        $valueSize = 12;
        $lineH     = 22;
        $maxVw     = max(100, $w - self::LABEL_COL_W - 8);
        $lines     = self::wrapToWidth($v, $fontBold, $valueSize, $maxVw);

        self::ttf($im, $font, $labelSize, $cK, $x, $yy, $k);

        $vRightEdge = $x + $w;
        $y2         = (float) $yy;
        foreach ($lines as $ln) {
            $tw = self::textPixelWidth($fontBold, $valueSize, $ln);
            self::ttf($im, $fontBold, $valueSize, $cV, (int) ($vRightEdge - $tw), (int) $y2, $ln);
            $y2 += $lineH;
        }

        return (int) ($y2 + 14);
    }

    private static function drawUsdcIcon(\GdImage $im, int $cx, int $cy, int $r, int $cBlue, int $cWhite): void
    {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $cBlue);
        imagefilledellipse($im, $cx, $cy, (int) ($r * 1.55), (int) ($r * 1.55), $cWhite);
        imagefilledellipse($im, $cx + (int) ($r * 0.32), $cy, (int) ($r * 0.95), (int) ($r * 0.95), $cBlue);
        imageline($im, $cx - (int) ($r * 0.35), $cy - 1, $cx + (int) ($r * 0.2), $cy - 1, $cWhite);
    }

    private static function drawHeroPattern(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $cPattern): void
    {
        $seed = ($x1 + $y1) * 7;
        for ($n = 0; $n < 42; $n++) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $px = $x1 + 12 + ($seed % max(1, $x2 - $x1 - 24));
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $py = $y1 + 10 + ($seed % max(1, $y2 - $y1 - 20));
            $kind = $seed % 5;
            if ($kind === 0) {
                imagefilledellipse($im, $px, $py, 4, 4, $cPattern);
            } elseif ($kind === 1) {
                imageellipse($im, $px, $py, 10, 10, $cPattern);
            } elseif ($kind === 2) {
                imageline($im, $px - 3, $py - 3, $px + 3, $py + 3, $cPattern);
                imageline($im, $px + 3, $py - 3, $px - 3, $py + 3, $cPattern);
            } else {
                imageline($im, $px - 4, $py, $px + 4, $py + 4, $cPattern);
            }
        }
        imageellipse($im, $x2 - 28, $y1 + 26, 36, 36, $cPattern);
        imageellipse($im, $x2 - 14, $y1 + 44, 24, 24, $cPattern);
    }

    private static function dottedHLine(\GdImage $im, int $x1, int $y, int $x2, int $color): void
    {
        for ($x = $x1; $x < $x2; $x += 5) {
            imagesetpixel($im, $x, $y, $color);
            if ($x + 1 < $x2) {
                imagesetpixel($im, $x + 1, $y, $color);
            }
            $x += 2;
        }
    }

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

    private static function formatNgnHeroAmount(float $ngn): string
    {
        $whole = abs($ngn - round($ngn)) < 0.005;
        $numPart = $whole
            ? number_format($ngn, 0, '.', ',')
            : number_format($ngn, 2, '.', ',');

        return '₦ ' . $numPart;
    }

    private static function formatCryptoAmount(float $amount, string $asset): string
    {
        $asset = strtoupper(trim($asset));
        if ($asset === '') {
            $asset = 'USDC';
        }
        $s = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        if ($s === '') {
            $s = '0';
        }

        return $s . ' ' . $asset;
    }

    private static function formatCryptoAmountCompact(float $amount, string $asset): string
    {
        $asset = strtoupper(trim($asset));
        if ($asset === '') {
            $asset = 'USDC';
        }
        $s = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        if ($s === '') {
            $s = '0';
        }

        return $s . $asset;
    }

    private static function displayBankName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return '—';
        }

        return strtoupper($name);
    }

    private static function displayPersonName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return '—';
        }

        return strtoupper($name);
    }

    private static function sendraLogoWidthForHeight(int $targetH): int
    {
        $path = dirname(__DIR__, 2) . '/assets/images/sendra-logo.png';
        if (!is_readable($path)) {
            return 35;
        }
        $info = @getimagesize($path);
        if ($info === false || ($info[1] ?? 0) < 1) {
            return 35;
        }

        return max(1, (int) round(($info[0] ?? 35) * ($targetH / $info[1])));
    }

    /**
     * @return int|null Rendered width in px, or null if asset missing
     */
    private static function drawSendraLogoImage(\GdImage $im, int $x, int $y, int $targetH): ?int
    {
        $path = dirname(__DIR__, 2) . '/assets/images/sendra-logo.png';
        if (!is_readable($path)) {
            return null;
        }
        $src = @imagecreatefrompng($path);
        if ($src === false) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);

            return null;
        }
        $targetW = max(1, (int) round($sw * ($targetH / $sh)));
        $dest    = imagecreatetruecolor($targetW, $targetH);
        if ($dest === false) {
            imagedestroy($src);

            return null;
        }
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
        imagealphablending($dest, true);
        imagecopyresampled($dest, $src, 0, 0, 0, 0, $targetW, $targetH, $sw, $sh);
        imagedestroy($src);
        imagecopy($im, $dest, $x, $y, 0, 0, $targetW, $targetH);
        imagedestroy($dest);

        return $targetW;
    }

    private static function drawSendraLogoMark(\GdImage $im, int $x, int $y, int $w, int $cTeal, int $cDark): void
    {
        $h = (int) ($w * 0.72);
        $pts = [
            $x + 2,                 $y + (int) ($h * 0.52),
            $x + $w - 2,            $y + 3,
            $x + (int) ($w * 0.40), $y + $h - 1,
        ];
        imagefilledpolygon($im, $pts, $cTeal);
        $inner = [
            $x + (int) ($w * 0.20), $y + (int) ($h * 0.46),
            $x + $w - 8,            $y + 10,
            $x + (int) ($w * 0.36), $y + (int) ($h * 0.76),
        ];
        imagefilledpolygon($im, $inner, $cDark);
    }

    private static function filledRoundRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        $r = min($radius, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2));
        if ($r < 2) {
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);

            return;
        }
        imagefilledrectangle($im, $x1 + $r, $y1,      $x2 - $r, $y2,      $color);
        imagefilledrectangle($im, $x1,      $y1 + $r, $x2,      $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private static function ttfCenter(\GdImage $im, string $font, float $size, int $color, int $cx, int $yBaseline, string $text): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return;
        }
        $tw = (int) abs($box[2] - $box[0]);
        $x  = (int) ($cx - $tw / 2);
        imagettftext($im, $size, 0, $x, $yBaseline, $color, $font, $text);
    }

    private static function textPixelWidth(string $font, float $size, string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return (int) (strlen($text) * $size * 0.55);
        }

        return (int) abs($box[2] - $box[0]);
    }

    /**
     * @return list<string>
     */
    private static function breakOversizedToken(string $font, float $size, string $token, int $maxPx): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }
        if (self::textPixelWidth($font, $size, $token) <= $maxPx) {
            return [$token];
        }
        $out = [];
        $len = strlen($token);
        $i   = 0;
        while ($i < $len) {
            $low  = 1;
            $high = $len - $i;
            $best = 1;
            while ($low <= $high) {
                $mid   = (int) (($low + $high) / 2);
                $chunk = substr($token, $i, $mid);
                if (self::textPixelWidth($font, $size, $chunk) <= $maxPx) {
                    $best = $mid;
                    $low  = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $best   = max(1, $best);
            $out[]  = substr($token, $i, $best);
            $i     += $best;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function wrapToWidth(string $text, string $font, int $size, int $maxPx): array
    {
        $text   = trim($text);
        if ($text === '') {
            return ['—'];
        }
        $maxPx  = max(24, $maxPx);
        $words  = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return ['—'];
        }

        $lines = [];
        $line  = '';
        foreach ($words as $word) {
            foreach (self::breakOversizedToken($font, (float) $size, $word, $maxPx) as $piece) {
                $candidate = $line === '' ? $piece : ($line . ' ' . $piece);
                if (self::textPixelWidth($font, (float) $size, $candidate) <= $maxPx) {
                    $line = $candidate;
                } else {
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                    $line = $piece;
                }
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? ['—'] : $lines;
    }

    private static function ttf(\GdImage $im, string $font, float $size, int $color, int $x, int $yBaseline, string $text): void
    {
        imagettftext($im, $size, 0, $x, $yBaseline, $color, $font, $text);
    }

    private static function color(\GdImage $im, int $r, int $g, int $b): int
    {
        return imagecolorallocate($im, $r, $g, $b);
    }

    private static function resolveFontPath(): ?string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_FONT_PATH'] ?? getenv('SENDRA_RECEIPT_FONT_PATH') ?: ''));
        if ($env !== '' && is_readable($env)) {
            return $env;
        }

        $root = dirname(__DIR__, 2);
        $candidates = [
            $root . '/assets/fonts/extras/ttf/Inter-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ];
        foreach ($candidates as $p) {
            if (is_readable($p)) {
                return $p;
            }
        }

        return null;
    }

    private static function resolveFontBoldPath(string $regularFontPath): ?string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_FONT_BOLD_PATH'] ?? getenv('SENDRA_RECEIPT_FONT_BOLD_PATH') ?: ''));
        if ($env !== '' && is_readable($env)) {
            return $env;
        }

        $root = dirname(__DIR__, 2);
        $cands = [
            $root . '/assets/fonts/extras/ttf/Inter-Bold.ttf',
            preg_replace('/Regular\.ttf$/', 'Bold.ttf', $regularFontPath) ?: '',
            preg_replace('/-Regular\.ttf$/', '-Bold.ttf', $regularFontPath) ?: '',
            preg_replace('/Inter-Regular\.ttf$/', 'Inter-Bold.ttf', $regularFontPath) ?: '',
        ];
        foreach ($cands as $p) {
            if ($p !== '' && is_readable($p)) {
                return $p;
            }
        }

        return null;
    }
}
