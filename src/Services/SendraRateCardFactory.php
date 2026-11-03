<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds the Sendra WhatsApp rate-card model from Centiiv GET /wallet/rates.
 */
final class SendraRateCardFactory
{
    /** @var list<array{source: string, target: string}> */
    private const DEFAULT_PAIRS = [
        ['source' => 'USDT', 'target' => 'NGN'],
        ['source' => 'USDC', 'target' => 'GHS'],
        ['source' => 'USDT', 'target' => 'KES'],
        ['source' => 'USDC', 'target' => 'XOF'],
        ['source' => 'USDC', 'target' => 'XAF'],
        ['source' => 'USDT', 'target' => 'ZAR'],
        ['source' => 'USDC', 'target' => 'ETB'],
        ['source' => 'USDT', 'target' => 'USDC'],
    ];

    /** @var list<string> */
    private const STABLES = ['USDC', 'USDT', 'USDT0', 'EURC', 'CNGN'];

    /**
     * @return array{success: bool, error: ?string, date_label: string, rows: list<array<string, mixed>>, text: string}
     */
    public static function build(?SendraCentiivRampService $ramp = null): array
    {
        $ramp ??= new SendraCentiivRampService();
        $fetched = self::fetchRates($ramp);
        if (!$fetched['success']) {
            return [
                'success' => false,
                'error' => $fetched['error'] ?? 'Rate lookup failed',
                'date_label' => self::formatDateLabel(null),
                'rows' => [],
                'text' => '',
            ];
        }

        $index = $fetched['index'];
        $dateLabel = self::formatDateLabel($fetched['timestamp']);
        $rows = [];
        foreach (self::displayPairs() as $want) {
            $key = $want['source'] . '|' . $want['target'];
            $hit = $index[$key] ?? null;
            if ($hit === null) {
                continue;
            }
            $left = $want['target'];
            $right = $want['source'];
            $rows[] = [
                'pair' => $left . '/' . $right,
                'left' => $left,
                'right' => $right,
                'left_kind' => in_array($left, self::STABLES, true) ? 'coin' : 'flag',
                'buy' => $hit['buy'],
                'sell' => $hit['sell'],
                'buy_display' => self::formatRate($hit['buy']),
                'sell_display' => self::formatRate($hit['sell']),
            ];
        }

        if ($rows === []) {
            return [
                'success' => false,
                'error' => 'No matching rates on the board',
                'date_label' => $dateLabel,
                'rows' => [],
                'text' => '',
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'date_label' => $dateLabel,
            'rows' => $rows,
            'text' => self::formatTextCard($dateLabel, $rows),
        ];
    }

    /**
     * @return list<array{source: string, target: string}>
     */
    private static function displayPairs(): array
    {
        $raw = trim((string) ($_ENV['SENDRA_RATE_CARD_PAIRS'] ?? getenv('SENDRA_RATE_CARD_PAIRS') ?: ''));
        if ($raw === '') {
            return self::DEFAULT_PAIRS;
        }

        $out = [];
        foreach (explode(',', $raw) as $token) {
            $token = strtoupper(trim($token));
            if (!preg_match('/^([A-Z0-9]{2,8})\/([A-Z0-9]{2,8})$/', $token, $m)) {
                continue;
            }
            // Card shows TARGET/SOURCE (e.g. NGN/USDT) matching the mockup.
            $out[] = ['source' => $m[2], 'target' => $m[1]];
        }

        return $out !== [] ? $out : self::DEFAULT_PAIRS;
    }

    /**
     * @return array{success: bool, error: ?string, index: array<string, array{buy: float, sell: float}>, timestamp: ?string}
     */
    private static function fetchRates(SendraCentiivRampService $ramp): array
    {
        $ttl = max(0, (int) ($_ENV['SENDRA_RATE_CARD_CACHE_TTL'] ?? getenv('SENDRA_RATE_CARD_CACHE_TTL') ?: 45));
        $cacheFile = sys_get_temp_dir() . '/sendra_centiiv_wallet_rates.json';
        if ($ttl > 0 && is_readable($cacheFile) && (time() - (int) filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['index'])) {
                return [
                    'success' => true,
                    'error' => null,
                    'index' => $cached['index'],
                    'timestamp' => is_string($cached['timestamp'] ?? null) ? $cached['timestamp'] : null,
                ];
            }
        }

        $res = $ramp->getWalletRates();
        if (empty($res['success'])) {
            return [
                'success' => false,
                'error' => $res['error'] ?? 'Rate lookup failed',
                'index' => [],
                'timestamp' => null,
            ];
        }

        $payload = $res['data'] ?? [];
        $list = [];
        if (is_array($payload)) {
            if (isset($payload['rates']) && is_array($payload['rates'])) {
                $list = $payload['rates'];
            } elseif (array_is_list($payload)) {
                $list = $payload;
            }
        }

        $index = [];
        $timestamp = null;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = strtoupper(trim((string) ($row['source'] ?? $row['from'] ?? '')));
            $target = strtoupper(trim((string) ($row['target'] ?? $row['to'] ?? '')));
            $buy = self::num($row['buyRate'] ?? $row['buy_rate'] ?? $row['buy'] ?? null);
            $sell = self::num($row['sellRate'] ?? $row['sell_rate'] ?? $row['sell'] ?? null);
            if ($source === '' || $target === '' || $buy === null || $sell === null) {
                continue;
            }
            $index[$source . '|' . $target] = ['buy' => $buy, 'sell' => $sell];
            $ts = $row['timestamp'] ?? $row['updatedAt'] ?? null;
            if (is_string($ts) && $ts !== '' && $timestamp === null) {
                $timestamp = $ts;
            }
        }

        if ($index === []) {
            return [
                'success' => false,
                'error' => 'Empty rate board',
                'index' => [],
                'timestamp' => null,
            ];
        }

        if ($ttl > 0) {
            @file_put_contents($cacheFile, json_encode(['index' => $index, 'timestamp' => $timestamp]));
        }

        return [
            'success' => true,
            'error' => null,
            'index' => $index,
            'timestamp' => $timestamp,
        ];
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (float) $v;

        return $n > 0 ? $n : null;
    }

    private static function formatRate(float $n): string
    {
        if ($n >= 1) {
            return number_format($n, 2, '.', ',');
        }

        $s = number_format($n, 4, '.', ',');

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    private static function formatDateLabel(?string $iso): string
    {
        $tzId = trim((string) ($_ENV['SENDRA_RECEIPT_TIMEZONE'] ?? getenv('SENDRA_RECEIPT_TIMEZONE') ?: 'Africa/Lagos'));
        try {
            $tz = new \DateTimeZone($tzId !== '' ? $tzId : 'Africa/Lagos');
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Africa/Lagos');
        }

        try {
            $dt = $iso !== null && $iso !== ''
                ? new \DateTimeImmutable($iso)
                : new \DateTimeImmutable('now', $tz);
            $dt = $dt->setTimezone($tz);
        } catch (\Exception $e) {
            $dt = new \DateTimeImmutable('now', $tz);
        }

        $day = (int) $dt->format('j');
        $mod = $day % 100;
        if ($mod >= 11 && $mod <= 13) {
            $suf = 'TH';
        } else {
            $suf = match ($day % 10) {
                1 => 'ST',
                2 => 'ND',
                3 => 'RD',
                default => 'TH',
            };
        }

        return strtoupper($day . $suf . ' ' . $dt->format('F, Y'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function formatTextCard(string $dateLabel, array $rows): string
    {
        $lines = ["*Sendra rate card*", '_' . $dateLabel . '_', ''];
        foreach ($rows as $row) {
            $lines[] = '*' . $row['pair'] . '*  Buy ' . $row['buy_display'] . '  ·  Sell ' . $row['sell_display'];
        }
        $lines[] = '';
        $lines[] = '_usesendra.com_';

        return implode("\n", $lines);
    }
}
