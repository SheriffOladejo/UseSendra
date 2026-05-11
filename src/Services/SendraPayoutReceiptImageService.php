<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders an OPay-style PNG payout receipt for WhatsApp ({@see WhatsAppService::sendImage()}).
 */
final class SendraPayoutReceiptImageService
{
    private const WIDTH = 720;

    private const PAD = 28;

    private const INNER_PAD = 22;

    /**
     * @param list<array{label: string, time: string}> $timeline
     */
    private static function estimateHeight(
        string $recipient,
        string $bankLine,
        string $sessionId,
        string $bridge,
        string $tx,
        string $when,
        string $paymentMethod,
        string $partner,
        string $font,
        int $innerW
    ): int {
        // New "Withdrawal receipt" design: white background + green amount card + summary blocks.
        // Keep a conservative estimate to prevent bottom cropping when reference wraps.
        $iw = $innerW - 2 * self::INNER_PAD;
        $contentW = max(80, $iw - 10);
        $ref = trim($bridge) !== '' ? $bridge : ($sessionId !== '' ? $sessionId : $tx);
        $refLines = count(self::wrapToWidth($ref, $font, 15, $contentW));

        $yy = 0;
        $yy += 24;   // top padding
        $yy += 52;   // logo row
        $yy += 34;   // title
        $yy += 190;  // green card + spacing
        $yy += 34;   // "Transaction summary" title
        $yy += 30 * 2 + 18; // two rows + divider
        $yy += 34;   // "Receiver's details" title
        $yy += 30 * 3 + 18; // three rows + divider
        $yy += 30;   // sender row
        $yy += 16 + $refLines * 20 + 12; // wrapped reference row
        $yy += 30;   // date row
        $yy += 90;   // footer

        return max(980, $yy + 80);
    }

    /** Label (14px) + 22 + value lines (16px, lh 22) + 14 gap — matches {@see detailRow}. */
    private static function detailBlockHeight(string $value, string $font, int $valueSize, int $maxW): int
    {
        $n = count(self::wrapToWidth($value, $font, $valueSize, $maxW));

        return 22 + $n * 22 + 14;
    }

    /**
     * @param array<string, mixed> $model From {@see SendraCentiivPayoutReceiptFactory::build()}
     * @return string|null Absolute path to temp .png, or null on failure (caller should unlink)
     */
    public static function renderToTempFile(array $model): ?string
    {
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

        // New receipt design inputs (keep fallbacks for older model)
        $bankName = trim((string) ($model['bank_name'] ?? ''));
        $accountName = trim((string) ($model['account_name'] ?? ($model['recipient'] ?? '—')));
        $accountNumber = trim((string) ($model['account_number'] ?? ''));
        $senderName = trim((string) ($model['sender_name'] ?? 'Nomba Financial Services'));

        $recipient = $accountName !== '' ? $accountName : (string) ($model['recipient'] ?? '—');
        $bankLine = (string) ($model['bank_line'] ?? (($bankName !== '' && $accountNumber !== '') ? ($bankName . '  |  ' . $accountNumber) : ($accountNumber !== '' ? $accountNumber : '—')));
        $tx = (string) ($model['transaction_id'] ?? '—');
        $when = (string) ($model['completed_at'] ?? '');
        // Kept for backward compatibility, but the new design does not show status/partner.
        $partner = (string) ($model['payout_partner'] ?? 'Centiiv');
        $sessionId = trim((string) ($model['session_id'] ?? ''));
        $paymentMethod = (string) ($model['payment_method'] ?? 'Wallet');
        $bridge = trim((string) ($model['bridge_note'] ?? ''));

        $amt = $model['amount_ngn'] ?? null;
        $amountStr = is_numeric($amt)
            ? ('₦' . number_format((float) $amt, 2, '.', ','))
            : '—';

        // Withdraw + fees section
        $withdrawAsset = strtoupper(trim((string) ($model['withdraw_asset'] ?? 'USDT')));
        $withdrawAmt = $model['withdraw_amount'] ?? null;
        $withdrawStr = is_numeric($withdrawAmt)
            ? (rtrim(rtrim(number_format((float) $withdrawAmt, 6, '.', ''), '0'), '.') . ' ' . $withdrawAsset)
            : ('— ' . $withdrawAsset);

        $feeAsset = strtoupper(trim((string) ($model['fee_asset'] ?? $withdrawAsset)));
        $feeAmt = $model['fee_amount'] ?? null;
        $feeStr = is_numeric($feeAmt)
            ? (rtrim(rtrim(number_format((float) $feeAmt, 6, '.', ''), '0'), '.') . ' ' . $feeAsset)
            : ('— ' . $feeAsset);

        $innerW = self::WIDTH - self::PAD * 2;
        $whenDisp = $when !== '' ? $when : SendraCentiivPayoutReceiptFactory::formatReceiptNow('M j, Y g:i A');
        $height = self::estimateHeight(
            $recipient,
            $bankLine,
            $sessionId,
            $bridge,
            $tx,
            $whenDisp,
            $paymentMethod,
            $partner,
            $font,
            $innerW
        );

        $im = imagecreatetruecolor(self::WIDTH, $height);
        if ($im === false) {
            return null;
        }

        imagealphablending($im, true);
        imagesavealpha($im, true);

        // New design palette (close to the provided Sendra receipt)
        $cBg = self::color($im, 255, 255, 255);
        $cText = self::color($im, 18, 25, 35);
        $cMuted = self::color($im, 108, 118, 134);
        $cLine = self::color($im, 228, 233, 240);
        // Reference greens
        $cGreen = self::color($im, 13, 74, 56);   // #0D4A38
        $cGreen2 = self::color($im, 8, 58, 43);   // #083A2B
        $cWhite = self::color($im, 255, 255, 255);
        $cAccent = self::color($im, 13, 74, 46);  // darker footer green
        $cWhiteMuted = self::color($im, 184, 212, 203); // #B8D4CB
        $cCoinBlue = self::color($im, 42, 159, 216); // #2A9FD8

        imagefilledrectangle($im, 0, 0, self::WIDTH, $height, $cBg);

        $x0 = self::PAD;
        $wCard = self::WIDTH - 2 * self::PAD;
        $ix = (int) ($x0 + self::INNER_PAD);
        $iw = (int) ($wCard - 2 * self::INNER_PAD);

        $yy = (float) (self::PAD + 10);

        // Top logo + brand
        $brandText = 'sendra';
        $brandSize = 22;
        $markW = 34;
        $gap = 8;
        $brandW = $markW + $gap + self::textPixelWidth($fontBold, $brandSize, $brandText);
        $logoX = (int) ((self::WIDTH - $brandW) / 2);
        $logoY = (int) ($yy + 16);
        // Teal chevron/arrow mark
        $cTeal = self::color($im, 38, 184, 147);
        imagefilledpolygon($im, [
            $logoX, $logoY,
            $logoX + $markW, $logoY - 16,
            $logoX + 18, $logoY + 20,
        ], $cTeal);
        self::ttf($im, $fontBold, $brandSize, $cText, $logoX + $markW + $gap, (int) ($yy + 24), $brandText);
        $yy += 56;

        // Title
        self::ttfCenter($im, $fontBold, 34, $cText, (int) (self::WIDTH / 2), (int) $yy, 'Withdrawal receipt');
        $yy += 40;

        // Green amount card
        $cardX1 = (int) $x0;
        $cardY1 = (int) $yy;
        $cardX2 = (int) ($x0 + $wCard);
        $cardY2 = (int) ($yy + 195);
        self::filledRoundRect($im, $cardX1, $cardY1, $cardX2, $cardY2, 24, $cGreen);
        self::filledRoundRect($im, $cardX1 + 10, $cardY1 + 10, $cardX2 - 10, $cardY2 - 10, 22, $cGreen2);
        // Texture overlay: subtle crosses
        $cTex = self::color($im, 15, 85, 64);
        for ($dy = $cardY1 + 18; $dy < $cardY2 - 18; $dy += 20) {
            for ($dx = $cardX1 + 18; $dx < $cardX2 - 18; $dx += 20) {
                imageline($im, $dx - 2, $dy - 2, $dx + 2, $dy + 2, $cTex);
                imageline($im, $dx + 2, $dy - 2, $dx - 2, $dy + 2, $cTex);
            }
        }
        // Decorative circles (top-right)
        $cCircle = self::color($im, 26, 107, 80);
        imageellipse($im, $cardX2 - 46, $cardY1 + 40, 90, 90, $cCircle);
        imageellipse($im, $cardX2 - 22, $cardY1 + 70, 72, 72, $cCircle);
        self::ttfCenter($im, $font, 16, $cWhiteMuted, (int) (self::WIDTH / 2), (int) ($cardY1 + 62), 'You withdrew:');
        self::ttfCenter($im, $fontBold, 64, $cWhite, (int) (self::WIDTH / 2), (int) ($cardY1 + 142), $amountStr);
        $yy += 230;

        // Transaction summary
        self::ttf($im, $fontBold, 25, $cText, $ix, (int) $yy, 'Transaction summary');
        $yy += 34;
        $yy = (float) self::kvRowWithIcon($im, $font, $ix, (int) $yy, $iw, 'Amount (' . $withdrawAsset . '):', $withdrawStr, $cMuted, $cText, $cCoinBlue, $cWhite);
        $yy = (float) self::kvRowWithIcon($im, $font, $ix, (int) $yy, $iw, 'Transaction fees:', $feeStr, $cMuted, $cText, $cCoinBlue, $cWhite);
        imageline($im, $ix, (int) $yy, $ix + $iw, (int) $yy, $cLine);
        $yy += 26;

        // Receiver's details
        self::ttf($im, $fontBold, 25, $cText, $ix, (int) $yy, "Receiver's details");
        $yy += 34;
        $bn = $bankName !== '' ? $bankName : '—';
        $an = $accountName !== '' ? $accountName : '—';
        $acn = $accountNumber !== '' ? $accountNumber : '—';
        $yy = (float) self::kvRow($im, $font, $ix, (int) $yy, $iw, 'Bank Name', strtoupper($bn), $cMuted, $cText);
        $yy = (float) self::kvRow($im, $font, $ix, (int) $yy, $iw, 'Account name:', strtoupper($an), $cMuted, $cText);
        $yy = (float) self::kvRow($im, $font, $ix, (int) $yy, $iw, 'Account number:', $acn, $cMuted, $cText);
        imageline($im, $ix, (int) $yy, $ix + $iw, (int) $yy, $cLine);
        $yy += 22;

        // Sender
        $yy = (float) self::kvRow($im, $font, $ix, (int) $yy, $iw, 'Sender:', $senderName !== '' ? $senderName : 'Nomba Financial Services', $cMuted, $cText);

        // Reference (prefer 1Click correlation id / session id)
        $refValue = $sessionId !== '' ? $sessionId : ($tx !== '' ? $tx : '—');
        if ($bridge !== '') {
            $refValue = preg_replace('/^Crypto bridge\\s*\\(1Click\\):\\s*/i', '', $bridge) ?: $bridge;
        }
        $yy = (float) self::kvRowWrapped($im, $font, $ix, (int) $yy, $iw, 'Reference:', $refValue, $cMuted, $cText);

        // Date
        $yy = (float) self::kvRow($im, $font, $ix, (int) $yy, $iw, 'Date:', $whenDisp, $cMuted, $cText);

        // Footer
        self::ttfCenter($im, $fontBold, 21, $cAccent, (int) (self::WIDTH / 2), (int) ($height - 62), 'THANK YOU FOR CHOOSING');
        self::ttfCenter($im, $fontBold, 21, $cAccent, (int) (self::WIDTH / 2), (int) ($height - 38), 'SENDRA!!');

        $path = sys_get_temp_dir() . '/sendra_payout_receipt_' . bin2hex(random_bytes(8)) . '.png';
        if (!imagepng($im, $path, 6)) {
            imagedestroy($im);

            return null;
        }
        imagedestroy($im);

        return $path;
    }

    private static function kvRow(\GdImage $im, string $font, int $x, int $yy, int $w, string $k, string $v, int $cK, int $cV): int
    {
        $size = 23;
        self::ttf($im, $font, $size, $cK, $x, $yy, $k);
        $tw = self::textPixelWidth($font, $size, $v);
        $vx = (int) ($x + $w - $tw);
        self::ttf($im, $font, $size, $cV, $vx, $yy, $v);

        return $yy + 36;
    }

    private static function kvRowWithIcon(
        \GdImage $im,
        string $font,
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
        $size = 23;
        self::ttf($im, $font, $size, $cK, $x, $yy, $k);
        $tw = self::textPixelWidth($font, $size, $v);
        $iconR = 9;
        $gap = 8;
        $vx = (int) ($x + $w - $tw);
        $iconCx = $vx - ($iconR * 2) - $gap + $iconR;
        $iconCy = $yy - 8;
        self::drawCoinIcon($im, $iconCx, $iconCy, $iconR, $cBlue, $cWhite);
        self::ttf($im, $font, $size, $cV, $vx, $yy, $v);

        return $yy + 36;
    }

    private static function drawCoinIcon(\GdImage $im, int $cx, int $cy, int $r, int $cBlue, int $cWhite): void
    {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $cBlue);
        imageellipse($im, $cx, $cy, (int) ($r * 1.25), (int) ($r * 1.25), $cWhite);
        imagefilledellipse($im, $cx, $cy, (int) ($r * 0.78), (int) ($r * 0.78), $cWhite);
        // cutout to suggest a "C"
        imagefilledellipse($im, $cx + 2, $cy, (int) ($r * 0.52), (int) ($r * 0.52), $cBlue);
    }

    private static function kvRowWrapped(\GdImage $im, string $font, int $x, int $yy, int $w, string $k, string $v, int $cK, int $cV): int
    {
        self::ttf($im, $font, 16, $cK, $x, $yy, $k);
        $keyW = 125;
        $maxVw = max(80, $w - $keyW);
        $lines = self::wrapToWidth($v, $font, 15, $maxVw);
        $vx = $x + $keyW;
        $y2 = (float) $yy;
        foreach ($lines as $ln) {
            self::ttf($im, $font, 15, $cV, $vx, (int) $y2, $ln);
            $y2 += 20;
        }

        return (int) ($y2 + 18);
    }

    /**
     * @param list<array{label?: string, time?: string}> $timeline
     */
    private static function drawTimeline(
        \GdImage $im,
        string $font,
        int $yy,
        int $iw,
        float $x0,
        array $timeline,
        int $cGreen,
        int $cGreenDark,
        int $cLabel,
        int $cTime
    ): float {
        $padL = (int) ($x0 + self::INNER_PAD);
        $w = $iw;
        $n = 3;
        $centers = [];
        for ($i = 0; $i < $n; $i++) {
            $centers[] = (int) ($padL + ($i + 0.5) * ($w / $n));
        }

        $nodeY = $yy + 14;
        $r = 10;
        for ($i = 0; $i < $n - 1; $i++) {
            imageline($im, $centers[$i] + $r, $nodeY, $centers[$i + 1] - $r, $nodeY, $cGreenDark);
        }
        for ($i = 0; $i < $n; $i++) {
            imagefilledellipse($im, $centers[$i], $nodeY, $r * 2, $r * 2, $cGreen);
            imagefilledellipse($im, $centers[$i], $nodeY, 8, 8, self::color($im, 255, 255, 255));
        }

        $yy = (float) ($nodeY + 28);
        $defaultLabs = ['Payment successful', 'Processing by bank', 'Received by bank'];
        for ($i = 0; $i < $n; $i++) {
            $step = $timeline[$i] ?? [];
            $lab = (string) ($step['label'] ?? '');
            $tim = (string) ($step['time'] ?? '');
            if ($lab === '') {
                $lab = $defaultLabs[$i] ?? '';
            }
            $colW = (int) ($w / $n);
            $cx = $centers[$i];
            $lines = self::wrapToWidth($lab, $font, 13, $colW - 8);
            $y2 = $yy;
            foreach ($lines as $ln) {
                self::ttfCenter($im, $font, 13, $cLabel, $cx, (int) $y2, $ln);
                $y2 += 18;
            }
            self::ttfCenter($im, $font, 12, $cTime, $cx, (int) ($y2 + 4), $tim);
        }

        return $yy + 52;
    }

    private static function detailRow(
        \GdImage $im,
        string $font,
        int $x,
        int $yy,
        string $label,
        string $value,
        int $cLabel,
        int $cValue,
        int $maxW
    ): int {
        self::ttf($im, $font, 14, $cLabel, $x, $yy, $label);
        $yy += 22;
        $lh = 22;
        foreach (self::wrapToWidth($value, $font, 16, $maxW) as $ln) {
            self::ttf($im, $font, 16, $cValue, $x, $yy, $ln);
            $yy += $lh;
        }

        return $yy + 14;
    }

    private static function filledRoundRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        $r = min($radius, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2));
        if ($r < 2) {
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);

            return;
        }
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private static function drawWrappedCentered(
        \GdImage $im,
        string $font,
        float $size,
        int $color,
        int $cx,
        int $yy,
        int $maxW,
        string $text,
        int $lineHeight
    ): float {
        foreach (self::wrapToWidth($text, $font, (int) $size, $maxW) as $line) {
            self::ttfCenter($im, $font, $size, $color, $cx, (int) $yy, $line);
            $yy += $lineHeight;
        }

        return $yy;
    }

    private static function ttfCenter(\GdImage $im, string $font, float $size, int $color, int $cx, int $yBaseline, string $text): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return;
        }
        $tw = (int) abs($box[2] - $box[0]);
        $x = (int) ($cx - $tw / 2);
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
     * Split a single token into segments each not wider than $maxPx (for UUIDs, hashes, long URLs).
     *
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
        $i = 0;
        while ($i < $len) {
            $low = 1;
            $high = $len - $i;
            $best = 1;
            while ($low <= $high) {
                $mid = (int) (($low + $high) / 2);
                $chunk = substr($token, $i, $mid);
                if (self::textPixelWidth($font, $size, $chunk) <= $maxPx) {
                    $best = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            $best = max(1, $best);
            $out[] = substr($token, $i, $best);
            $i += $best;
        }

        return $out;
    }

    /**
     * Word-wrap using real TTF metrics so long unbroken strings and prose stay inside $maxPx.
     *
     * @return list<string>
     */
    private static function wrapToWidth(string $text, string $font, int $size, int $maxPx): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['—'];
        }
        $maxPx = max(24, $maxPx);

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return ['—'];
        }

        $lines = [];
        $line = '';
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

        $candidates = [
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

        // Common pattern: Inter-Regular.ttf -> Inter-Bold.ttf
        $cands = [];
        $cands[] = preg_replace('/Regular\\.ttf$/', 'Bold.ttf', $regularFontPath) ?: '';
        $cands[] = preg_replace('/-Regular\\.ttf$/', '-Bold.ttf', $regularFontPath) ?: '';
        $cands[] = preg_replace('/\\.ttf$/', '-Bold.ttf', $regularFontPath) ?: '';
        foreach ($cands as $p) {
            if ($p !== '' && is_readable($p)) {
                return $p;
            }
        }

        return null;
    }
}
