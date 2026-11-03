<?php

declare(strict_types=1);

namespace App\Services;

use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\WhatsAppBotResolver;

/**
 * Watches Arc system Transfer logs (18 decimals) and credits inbound USDC
 * to the matching users.arc_address. ERC-20 logs are not read, so one movement
 * is not counted twice.
 */
final class SendraArcIndexerService
{
    private const SYSTEM_EMITTER = '0xffffFFFfFFffffffffffffffFfFFFfffFFFfFFfE';
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private \PDO $db,
        private WhatsAppService $whatsapp
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $stats = ['logs' => 0, 'credited' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->tablesReady()) {
            error_log('SendraArcIndexer: run migration 20261014_users_arc_wallet.sql');
            $stats['skipped'] = 1;

            return $stats;
        }

        $lockPath = sys_get_temp_dir() . '/sendra_arc_index.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $stats['skipped'] = 1;

            return $stats;
        }

        try {
            $this->indexRange($stats);
        } catch (\Throwable $e) {
            error_log('SendraArcIndexer: ' . $e->getMessage());
            $stats['failed']++;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    private function tablesReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM arc_indexer_state LIMIT 1');
            $this->db->query('SELECT 1 FROM arc_deposits LIMIT 1');
            $this->db->query('SELECT arc_address FROM users LIMIT 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, int> $stats
     */
    private function indexRange(array &$stats): void
    {
        $head = $this->blockNumber();
        if ($head === null || $head < 1) {
            return;
        }

        $last = $this->lastBlock();
        if ($last <= 0) {
            $this->setLastBlock($head);

            return;
        }
        if ($last >= $head) {
            return;
        }

        $from = $last + 1;
        $to = min($head, $from + 1999);
        $logs = $this->getLogs($from, $to);
        $users = $this->userIndex();
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $stats['logs']++;
            $this->considerLog($log, $users, $stats);
        }
        $this->setLastBlock($to);
    }

    /**
     * @param array<string, array<string, mixed>> $users
     * @param array<string, int> $stats
     * @param array<string, mixed> $log
     */
    private function considerLog(array $log, array $users, array &$stats): void
    {
        $topics = $log['topics'] ?? null;
        if (!is_array($topics) || count($topics) < 3) {
            $stats['skipped']++;

            return;
        }
        $to = $this->topicAddress((string) $topics[2]);
        $from = $this->topicAddress((string) $topics[1]);
        if ($to === null || $to === '0x0000000000000000000000000000000000000000') {
            $stats['skipped']++;

            return;
        }
        $user = $users[strtolower($to)] ?? null;
        if (!is_array($user)) {
            $stats['skipped']++;

            return;
        }

        $hash = strtolower((string) ($log['transactionHash'] ?? ''));
        $logIndex = hexdec((string) ($log['logIndex'] ?? '0x0'));
        if ($hash === '' || $this->alreadySeen($hash, $logIndex)) {
            $stats['skipped']++;

            return;
        }

        $amount = $this->formatAmount((string) ($log['data'] ?? '0x0'));
        if ((float) $amount <= 0) {
            $stats['skipped']++;

            return;
        }

        $phone = (string) ($user['phone_number'] ?? '');
        $this->insertDeposit($hash, $logIndex, $from, $to, $amount, $phone);
        if ($this->historyAlreadyHas($phone, $hash)) {
            $stats['skipped']++;

            return;
        }
        $explorer = 'https://explorer.arc.io/tx/' . $hash;
        $this->notifyAndHistory($user, $amount, $hash, $explorer, $from);
        $stats['credited']++;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function userIndex(): array
    {
        $stmt = $this->db->query(
            "SELECT phone_number, arc_address FROM users WHERE arc_address IS NOT NULL AND arc_address != ''"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addr = strtolower(trim((string) ($row['arc_address'] ?? '')));
            if ($addr !== '') {
                $out[$addr] = $row;
            }
        }

        return $out;
    }

    private function notifyAndHistory(array $user, string $amount, string $hash, string $explorer, ?string $from): void
    {
        $phone = (string) ($user['phone_number'] ?? '');
        if ($phone === '') {
            return;
        }
        $display = rtrim(rtrim($amount, '0'), '.');
        if ($display === '') {
            $display = $amount;
        }
        $body = "✅ *USDC credited to your Arc wallet*\n\n*{$display} USDC* is in your Sendra balance.";
        $phoneId = WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
            $this->db,
            $phone,
            null,
            $this->whatsapp->getPhoneNumberId()
        );
        $token = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
        $wa = ($phoneId !== '' && $phoneId !== $this->whatsapp->getPhoneNumberId() && $token !== '')
            ? new WhatsAppService($token, $phoneId)
            : $this->whatsapp;

        if ($explorer !== '') {
            $wa->sendCTAUrlButton($phone, $body, 'View on Arc', $explorer);
        } else {
            $wa->sendMessage($phone, $body);
        }

        $amt = is_numeric($amount) ? (float) $amount : 0.0;
        if ($amt > 0) {
            try {
                SendraIndexerDepositNotifyService::completeNativeOnrampCredit(
                    $this->db,
                    $wa,
                    $phone,
                    $amt,
                    'USDC',
                    'arc'
                );
            } catch (\Throwable $e) {
                error_log('SendraArcIndexer buy receipt: ' . $e->getMessage());
            }
        }

        (new TransactionHistoryRepository($this->db))->record([
            'user_phone' => $phone,
            'direction' => 'in',
            'amount' => $amount,
            'currency' => 'USDC',
            'chain' => 'arc',
            'status' => 'confirmed',
            'aptos_address' => (string) ($user['arc_address'] ?? ''),
            'counterparty' => $from ?? '',
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);
    }

    private function historyAlreadyHas(string $phone, string $hash): bool
    {
        if ($phone === '' || $hash === '') {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM transaction_history WHERE user_phone = :phone AND tx_hash = :hash LIMIT 1'
        );
        $stmt->execute(['phone' => $phone, 'hash' => $hash]);

        return (bool) $stmt->fetchColumn();
    }

    private function alreadySeen(string $hash, int $logIndex): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM arc_deposits WHERE tx_hash = :h AND log_index = :i LIMIT 1');
        $stmt->execute(['h' => $hash, 'i' => $logIndex]);

        return (bool) $stmt->fetchColumn();
    }

    private function insertDeposit(string $hash, int $logIndex, ?string $from, string $to, string $amount, string $phone): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO arc_deposits
                (id, tx_hash, log_index, from_address, to_address, amount, user_phone, status)
             VALUES
                (:id, :hash, :idx, :from_addr, :to_addr, :amount, :phone, \'credited\')'
        );
        $stmt->execute([
            'id' => bin2hex(random_bytes(16)),
            'hash' => $hash,
            'idx' => $logIndex,
            'from_addr' => $from,
            'to_addr' => $to,
            'amount' => $amount,
            'phone' => $phone !== '' ? $phone : null,
        ]);
    }

    private function lastBlock(): int
    {
        $stmt = $this->db->query('SELECT last_block FROM arc_indexer_state WHERE id = 1');
        $value = $stmt ? $stmt->fetchColumn() : 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function setLastBlock(int $block): void
    {
        $stmt = $this->db->prepare('UPDATE arc_indexer_state SET last_block = :b WHERE id = 1');
        $stmt->execute(['b' => $block]);
    }

    private function blockNumber(): ?int
    {
        $res = $this->rpc('eth_blockNumber', []);
        $hex = is_string($res) ? $res : '';
        if ($hex === '' || !str_starts_with($hex, '0x')) {
            return null;
        }

        return (int) hexdec($hex);
    }

    /**
     * @return list<mixed>
     */
    private function getLogs(int $from, int $to): array
    {
        $res = $this->rpc('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($to),
            'address' => self::SYSTEM_EMITTER,
            'topics' => [self::TRANSFER_TOPIC],
        ]]);

        return is_array($res) ? $res : [];
    }

    /**
     * @param list<mixed> $params
     */
    private function rpc(string $method, array $params): mixed
    {
        $url = trim((string) ($_ENV['ARC_RPC_URL'] ?? getenv('ARC_RPC_URL') ?: 'https://rpc.mainnet.arc.io'));
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            $err = is_array($decoded['error'] ?? null) ? json_encode($decoded['error']) : 'rpc_failed';
            throw new \RuntimeException('Arc RPC ' . $method . ' ' . $err);
        }

        return $decoded['result'] ?? null;
    }

    private function topicAddress(string $topic): ?string
    {
        $topic = strtolower(trim($topic));
        if (!str_starts_with($topic, '0x') || strlen($topic) < 42) {
            return null;
        }

        return '0x' . substr($topic, -40);
    }

    private function formatAmount(string $hex): string
    {
        $dec = $this->hexToDec($hex);
        if (!function_exists('bcdiv')) {
            return number_format(((float) $dec) / 1e18, 6, '.', '');
        }
        $whole = bcdiv($dec, bcpow('10', '18', 0), 6);

        return $whole === null ? '0' : $whole;
    }

    private function hexToDec(string $hex): string
    {
        $hex = strtolower(ltrim(str_replace('0x', '', $hex), '0'));
        if ($hex === '') {
            return '0';
        }
        if (!function_exists('bcmul')) {
            return (string) hexdec(substr($hex, -15));
        }
        $dec = '0';
        foreach (str_split($hex) as $char) {
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) hexdec($char), 0);
        }

        return $dec;
    }
}
