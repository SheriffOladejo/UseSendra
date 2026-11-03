<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\StellarPoolSweep;
use AptosV2\Support\StellarReceive;
use AptosV2\Support\WhatsAppBotResolver;

/**
 * Horizon watcher: classic USDC / USDT0 paid to the shared Sendra G
 * with memo_id → look up user → SAC transfer G trustline → user C (1:1 stroops).
 *
 * There is no wrap/haircut. SAC transfer from a G account spends the classic
 * trustline; the C wallet then holds the same amount as a contract balance.
 */
final class SendraStellarPoolIndexerService
{
    private const USDC_ISSUER = 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN';
    private const USDT0_ISSUER = 'GATISXX6BZ6NC7IKQBY37CJD4SOZL3CYZJWXEDG6JVIY4WBS6KXJHN6Q';

    public function __construct(
        private \PDO $db,
        private WhatsAppService $whatsapp
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function run(int $pageSize = 50): array
    {
        $stats = [
            'fetched' => 0,
            'credited' => 0,
            'unmatched' => 0,
            'failed' => 0,
            'skipped' => 0,
            'retried' => 0,
        ];
        if (!$this->tablesReady()) {
            error_log('SendraStellarPoolIndexer: run migration 20260916_stellar_pool_deposits.sql');

            return $stats;
        }

        $lockPath = sys_get_temp_dir() . '/sendra_stellar_pool_index.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $stats['skipped'] = 1;
            $stats['locked'] = 1;

            return $stats;
        }

        try {
            $this->ingestHorizonPage($pageSize, $stats);
            $this->retryOpen($stats);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    private function tablesReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM stellar_pool_deposits LIMIT 1');
            $this->db->query('SELECT 1 FROM stellar_pool_indexer_state LIMIT 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, int> $stats
     */
    private function ingestHorizonPage(int $pageSize, array &$stats): void
    {
        $g = StellarReceive::sharedAddress();
        $cursor = $this->paymentsCursor();
        $url = $this->horizonBase() . '/accounts/' . rawurlencode($g)
            . '/payments?order=asc&limit=' . max(1, min(200, $pageSize));
        if ($cursor !== null && $cursor !== '') {
            $url .= '&cursor=' . rawurlencode($cursor);
        }

        $payload = $this->httpGetJson($url);
        $records = $payload['_embedded']['records'] ?? [];
        if (!is_array($records) || $records === []) {
            return;
        }

        $lastToken = $cursor;
        foreach ($records as $op) {
            if (!is_array($op)) {
                continue;
            }
            $stats['fetched']++;
            $token = (string) ($op['paging_token'] ?? '');
            if ($token !== '') {
                $lastToken = $token;
            }
            $this->considerPayment($op, $stats);
        }
        if ($lastToken !== null && $lastToken !== '' && $lastToken !== $cursor) {
            $this->setPaymentsCursor($lastToken);
        }
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, int> $stats
     */
    private function considerPayment(array $op, array &$stats): void
    {
        if (($op['type'] ?? '') !== 'payment') {
            return;
        }
        $g = StellarReceive::sharedAddress();
        if (strcasecmp((string) ($op['to'] ?? ''), $g) !== 0) {
            return;
        }
        $code = strtoupper((string) ($op['asset_code'] ?? ''));
        $issuer = (string) ($op['asset_issuer'] ?? '');
        $ledger = $this->ledgerAsset($code, $issuer);
        if ($ledger === null) {
            return;
        }

        $hash = strtolower((string) ($op['transaction_hash'] ?? ''));
        $amount = (string) ($op['amount'] ?? '');
        $token = (string) ($op['paging_token'] ?? '');
        $from = (string) ($op['from'] ?? '');
        if ($hash === '' || $amount === '' || (float) $amount <= 0) {
            return;
        }

        $memo = $this->memoIdForTx($hash);
        $existing = $this->findByInbound($hash);
        if ($existing === null) {
            $this->insertPending($hash, $token, $memo ?? '', $amount, $from, $ledger);
            $existing = $this->findByInbound($hash);
        }
        if (!is_array($existing)) {
            return;
        }
        $this->creditRow($existing, $stats);
    }

    /**
     * @param array<string, int> $stats
     */
    private function retryOpen(array &$stats): void
    {
        $stmt = $this->db->query(
            "SELECT * FROM stellar_pool_deposits
             WHERE status IN ('pending','failed','unmatched')
               AND attempts < 24
             ORDER BY created_at ASC
             LIMIT 20"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $stats['retried']++;
            $this->creditRow($row, $stats);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stats
     */
    private function creditRow(array $row, array &$stats): void
    {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'credited') {
            return;
        }

        $id = (string) ($row['id'] ?? '');
        $memo = trim((string) ($row['memo_id'] ?? ''));
        $inboundHash = strtolower((string) ($row['inbound_tx_hash'] ?? ''));
        if ($memo === '' && $inboundHash !== '') {
            $resolved = $this->memoIdForTx($inboundHash);
            if ($resolved !== null) {
                $memo = $resolved;
                $this->db->prepare('UPDATE stellar_pool_deposits SET memo_id = :m WHERE id = :id')
                    ->execute(['m' => $memo, 'id' => $id]);
            }
        }
        $amount = rtrim(rtrim((string) ($row['amount'] ?? ''), '0'), '.');
        if (strpos((string) ($row['amount'] ?? ''), '.') !== false) {
            $amount = (string) ($row['amount'] ?? '');
        }
        $this->bumpAttempts($id);

        if ($memo === '') {
            $this->mark($id, 'unmatched', 'missing_memo');
            $stats['unmatched']++;

            return;
        }

        $user = (new User($this->db))->findByStellarDepositMemo($memo);
        if (!is_array($user)) {
            $this->mark($id, 'unmatched', 'unknown_memo');
            $stats['unmatched']++;

            return;
        }

        $phone = (string) ($user['phone_number'] ?? '');
        $this->db->prepare('UPDATE stellar_pool_deposits SET user_phone = :p WHERE id = :id')
            ->execute(['p' => $phone, 'id' => $id]);

        $sweepAsset = \AptosV2\Support\StellarStable::serviceCurrency((string) ($row['asset'] ?? 'USDC'));
        $sweep = (new StellarPoolSweep())->sweepToUser($user, (string) ($row['amount'] ?? ''), $sweepAsset);
        if (empty($sweep['success'])) {
            $this->mark($id, 'failed', (string) ($sweep['error'] ?? json_encode($sweep)));
            $stats['failed']++;

            return;
        }

        $hash = (string) ($sweep['tx_hash'] ?? '');
        $explorer = (string) ($sweep['explorer_url'] ?? '');
        $upd = $this->db->prepare(
            'UPDATE stellar_pool_deposits
             SET status = \'credited\', user_phone = :phone, sweep_tx_hash = :tx,
                 explorer_url = :ex, error = NULL
             WHERE id = :id'
        );
        $upd->execute(['phone' => $phone, 'tx' => $hash, 'ex' => $explorer, 'id' => $id]);
        $stats['credited']++;

        $this->notifyAndHistory(
            $user,
            (string) ($row['amount'] ?? ''),
            $hash,
            $explorer,
            (string) ($row['inbound_tx_hash'] ?? ''),
            \AptosV2\Support\StellarStable::display((string) ($row['asset'] ?? 'USDC'))
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private function notifyAndHistory(array $user, string $amount, string $sweepHash, string $explorer, string $inboundHash, string $displayAsset = 'USDC'): void
    {
        $phone = (string) ($user['phone_number'] ?? '');
        if ($phone === '') {
            return;
        }

        $display = rtrim(rtrim($amount, '0'), '.');
        if ($display === '') {
            $display = $amount;
        }
        $body = "✅ *{$displayAsset} credited to your Stellar wallet*\n\n*{$display} {$displayAsset}* is in your Sendra balance.";

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
            $wa->sendCTAUrlButton($phone, $body, 'View on Stellar', $explorer);
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
                    $displayAsset,
                    'stellar'
                );
            } catch (\Throwable $e) {
                error_log('SendraStellarPoolIndexer buy receipt: ' . $e->getMessage());
            }
        }

        (new TransactionHistoryRepository($this->db))->record([
            'user_phone' => $phone,
            'direction' => 'in',
            'amount' => $amount,
            'currency' => $displayAsset,
            'chain' => 'stellar',
            'status' => 'confirmed',
            'aptos_address' => (string) ($user['stellar_address'] ?? ''),
            'counterparty' => StellarReceive::sharedAddress(),
            'tx_hash' => $sweepHash !== '' ? $sweepHash : $inboundHash,
            'explorer_url' => $explorer,
        ]);
    }

    private function insertPending(string $hash, string $token, string $memo, string $amount, string $from, string $asset): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO stellar_pool_deposits
                (id, inbound_tx_hash, paging_token, memo_id, asset, amount, from_account, status)
             VALUES
                (:id, :hash, :token, :memo, :asset, :amount, :from_acct, \'pending\')'
        );
        $stmt->execute([
            'id' => bin2hex(random_bytes(16)),
            'hash' => $hash,
            'token' => $token,
            'memo' => $memo,
            'amount' => $amount,
            'from_acct' => $from !== '' ? $from : null,
            'asset' => $asset,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByInbound(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stellar_pool_deposits WHERE inbound_tx_hash = :h LIMIT 1');
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function bumpAttempts(string $id): void
    {
        $this->db->prepare('UPDATE stellar_pool_deposits SET attempts = attempts + 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    private function mark(string $id, string $status, string $error): void
    {
        $this->db->prepare('UPDATE stellar_pool_deposits SET status = :s, error = :e WHERE id = :id')
            ->execute(['s' => $status, 'e' => substr($error, 0, 2000), 'id' => $id]);
    }

    private function memoIdForTx(string $hash): ?string
    {
        $tx = $this->httpGetJson($this->horizonBase() . '/transactions/' . $hash);
        $type = strtolower((string) ($tx['memo_type'] ?? ''));
        $memo = trim((string) ($tx['memo'] ?? ''));
        if ($memo === '') {
            return null;
        }
        // Centiiv uses memo_id. Freighter and many wallets send the same digits as memo_text.
        if ($type === 'id') {
            return $memo;
        }
        if ($type === 'text' && preg_match('/^\d{6,20}$/', $memo) === 1) {
            return $memo;
        }

        return null;
    }

    private function paymentsCursor(): ?string
    {
        $stmt = $this->db->query('SELECT payments_cursor FROM stellar_pool_indexer_state WHERE id = 1');
        $v = $stmt ? $stmt->fetchColumn() : false;

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function setPaymentsCursor(string $cursor): void
    {
        $this->db->prepare(
            'INSERT INTO stellar_pool_indexer_state (id, payments_cursor)
             VALUES (1, :c)
             ON DUPLICATE KEY UPDATE payments_cursor = VALUES(payments_cursor)'
        )->execute(['c' => $cursor]);
    }

    private function horizonBase(): string
    {
        $url = trim((string) ($_ENV['STELLAR_HORIZON_URL'] ?? getenv('STELLAR_HORIZON_URL') ?: ''));

        return rtrim($url !== '' ? $url : 'https://horizon.stellar.org', '/');
    }

    private function ledgerAsset(string $code, string $issuer): ?string
    {
        if ($code === 'USDC' && strcasecmp($issuer, $this->usdcIssuer()) === 0) {
            return 'USDC';
        }
        if ($code === 'USDT0' && strcasecmp($issuer, $this->usdt0Issuer()) === 0) {
            return 'USDT';
        }

        return null;
    }

    private function usdcIssuer(): string
    {
        $iss = trim((string) ($_ENV['STELLAR_USDC_ISSUER'] ?? getenv('STELLAR_USDC_ISSUER') ?: ''));

        return $iss !== '' ? $iss : self::USDC_ISSUER;
    }

    private function usdt0Issuer(): string
    {
        $iss = trim((string) ($_ENV['STELLAR_USDT_ISSUER'] ?? getenv('STELLAR_USDT_ISSUER') ?: ''));

        return $iss !== '' ? $iss : self::USDT0_ISSUER;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log('SendraStellarPoolIndexer HTTP ' . $code . ' ' . $url);

            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
