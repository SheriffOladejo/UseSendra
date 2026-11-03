<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Models\User;
use App\Services\SendraCentiivRampService;
use App\Services\SendraOneClickService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Persistence\TransactionHistoryRepository;
use AptosV2\Support\AptosServiceClient;

/**
 * v2 1Click + Centiiv off-ramp execution after PIN claim.
 */
final class OneClickOfframpExecutionService
{
    public function __construct(
        private User $userModel,
        private WhatsAppService $whatsapp,
        private PendingTransactionRepository $pendingTx,
        private TransactionHistoryRepository $txHistory,
        private OneClickOfframpSetupService $setup,
        private AptosServiceClient $aptosClient,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{success: bool, error?: string}
     */
    public function execute(string $from, array $user, array $pending, bool $deferPostSendPoll = false): array
    {
        OneClickOfframpTrace::log('execute_begin', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'tx_type' => (string) ($pending['tx_type'] ?? ''),
            'amount' => (string) ($pending['amount'] ?? ''),
            'currency' => (string) ($pending['currency'] ?? ''),
            'oneclick_deposit_aptos' => (string) ($pending['recipient_address'] ?? ''),
            'sender_aptos' => (string) ($user['aptos_address'] ?? ''),
        ]);

        $meta = $this->pendingTx->decodeMeta($pending);
        if (empty($meta['pin_claimed'])) {
            return ['success' => false, 'error' => 'PIN not claimed for this transaction.'];
        }
        if (($pending['user_phone'] ?? '') !== $from) {
            return ['success' => false, 'error' => 'Phone mismatch.'];
        }

        $agg = new SendraCentiivRampService();
        $setup = $this->setup->setupAfterPin($from, $user, $pending, $agg);
        if (!$setup['success']) {
            $pendingId = (string) ($pending['id'] ?? '');
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'cancelled');
            }
            $this->userModel->clearPendingTransaction($from);
            if (!empty($setup['user_message'])) {
                $this->whatsapp->sendMessage($from, (string) $setup['user_message']);
            }

            return ['success' => false, 'error' => (string) ($setup['user_message'] ?? 'Setup failed.')];
        }

        $pendingId = (string) ($pending['id'] ?? '');
        if ($setup['meta_patch'] !== [] && $pendingId !== '') {
            $this->pendingTx->mergeMeta($pendingId, $setup['meta_patch']);
        }
        if (!empty($setup['deposit_address']) && $pendingId !== '') {
            $pending['recipient_address'] = (string) $setup['deposit_address'];
            $this->pendingTx->updateRecipient($pendingId, (string) $setup['deposit_address']);
        }

        $result = $this->executeGaslessWithRetry($from, $pendingId, [
            'sender_address' => $user['aptos_address'],
            'encrypted_private_key' => $user['encrypted_private_key'],
            'recipient' => $pending['recipient_address'],
            'amount' => $pending['amount'],
            'currency' => $pending['currency'],
        ]);

        $httpAptos = (int) ($result['http_code'] ?? 0);
        $curlNo = (int) ($result['curl_errno'] ?? 0);
        if ($curlNo) {
            OneClickOfframpTrace::log('aptos_curl_error', $from, [
                'pending_id' => $pendingId,
                'http' => $httpAptos,
                'curl_errno' => $curlNo,
                'curl_error' => (string) ($result['curl_error'] ?? ''),
            ]);
            error_log('aptos-service oneclick offramp curl (v2): ' . (string) ($result['curl_error'] ?? ''));
        }

        $this->userModel->clearPendingTransaction($from);

        $meta = $this->pendingTx->decodeMeta($pending);
        $correlation = (string) ($setup['correlation_id'] ?? $meta['oneclick_correlation_id'] ?? '');
        $depositMemo = isset($setup['deposit_memo']) && is_string($setup['deposit_memo']) && $setup['deposit_memo'] !== ''
            ? $setup['deposit_memo']
            : (isset($meta['oneclick_deposit_memo']) && is_string($meta['oneclick_deposit_memo']) && $meta['oneclick_deposit_memo'] !== ''
                ? $meta['oneclick_deposit_memo'] : null);

        OneClickOfframpTrace::log('context_after_aptos_call', $from, [
            'pending_id' => $pendingId,
            'oneclick_correlation' => $correlation,
            'oneclick_memo_set' => $depositMemo !== null,
            'centiiv_order_id' => $meta['centiiv_order_id'] ?? null,
            'centiiv_order_id_hex' => $meta['centiiv_order_id_hex'] ?? null,
            'aptos_service_http' => $httpAptos,
        ]);

        if (empty($result['success'])) {
            if ($pendingId !== '') {
                $this->pendingTx->updateStatus($pendingId, 'failed');
            }
            $err = is_array($result) ? ($result['error'] ?? 'Unknown error') : 'Service error';
            $rawResponse = (string) ($result['raw'] ?? json_encode($result));
            $smoothClass = OfframpExecuteErrorFormatter::classifySmoothSend((string) $err);
            OneClickOfframpTrace::log('aptos_execute_failed', $from, [
                'pending_id' => $pendingId,
                'error' => (string) $err,
                'http' => $httpAptos,
                'smoothsend' => $smoothClass !== null,
                'smoothsend_code' => $smoothClass['code'] ?? null,
                'result_preview' => strlen($rawResponse) > 1200 ? (substr($rawResponse, 0, 1200) . '…') : $rawResponse,
            ]);
            OfframpExecuteErrorFormatter::logExecuteFailure($from, (string) $err, [
                'pending_id' => $pendingId,
                'http' => $httpAptos,
                'curl_errno' => $curlNo,
                'raw_preview' => strlen($rawResponse) > 2000 ? (substr($rawResponse, 0, 2000) . '…') : $rawResponse,
            ]);
            error_log("1Click offramp aptos send failed (v2) {$from}: {$err} raw={$rawResponse}");
            $this->whatsapp->sendMessage($from, OfframpExecuteErrorFormatter::format((string) $err));

            return ['success' => false, 'error' => (string) $err];
        }

        $hash = (string) $result['tx_hash'];
        $explorerUrl = $this->aptosClient->explorerUrlForTransaction($result, $hash);
        OneClickOfframpTrace::log('aptos_tx_confirmed', $from, [
            'pending_id' => $pendingId,
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
            'oneclick_correlation' => $correlation,
            'http' => $httpAptos,
        ]);

        if ($pendingId !== '') {
            $this->pendingTx->updateStatus($pendingId, 'confirmed', $hash);
            $this->pendingTx->mergeMeta($pendingId, [
                'aptos_funding_tx_hash' => $hash,
                'need_oneclick_poll' => true,
            ]);
        }

        $this->txHistory->record([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $pending['amount'],
            'currency' => $pending['currency'],
            'chain' => 'aptos',
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => $pending['recipient_address'],
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $oneClick = new SendraOneClickService();
        $submit = $oneClick->submitDeposit((string) $pending['recipient_address'], $hash, $depositMemo);
        OneClickOfframpTrace::log('deposit_submit_result', $from, [
            'pending_id' => $pendingId,
            'ok' => (bool) ($submit['success'] ?? false),
            'http' => (int) ($submit['http_code'] ?? 0),
            'error' => (string) ($submit['error'] ?? ''),
        ]);
        if (!$submit['success']) {
            error_log('1Click deposit/submit (v2, non-fatal): ' . ($submit['error'] ?? '') . ' ' . json_encode($submit['data']));
        }

        $pollIters = $deferPostSendPoll
            ? 0
            : (int) ($_ENV['SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS'] ?? getenv('SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS') ?: 4);
        if ($pollIters < 1) {
            $pollIters = $deferPostSendPoll ? 0 : 1;
        }
        if ($pollIters > 12) {
            $pollIters = 12;
        }

        $swapDone = false;
        for ($i = 0; $i < $pollIters; $i++) {
            if ($i > 0) {
                sleep(2);
            }
            $st = $oneClick->getStatus((string) $pending['recipient_address'], $depositMemo);
            if (!$st['success']) {
                OneClickOfframpTrace::log('status_poll_stopped', $from, [
                    'pending_id' => $pendingId,
                    'iteration' => $i,
                    'error' => (string) ($st['error'] ?? ''),
                    'http' => (int) ($st['http_code'] ?? 0),
                ]);
                break;
            }
            $code = SendraOneClickService::parseStatus($st['data']);
            $stSummary = is_array($st['data'] ?? null)
                ? (strlen($sj = (string) json_encode($st['data'], JSON_UNESCAPED_UNICODE)) > 1800 ? substr($sj, 0, 1800) . '…' : $sj)
                : '';
            OneClickOfframpTrace::log('status_poll', $from, [
                'pending_id' => $pendingId,
                'iteration' => $i,
                'oneclick_status' => $code,
                'oneclick_correlation' => $correlation,
                'data_summary' => $stSummary,
            ]);
            if ($code === 'SUCCESS') {
                $swapDone = true;
                break;
            }
            if (in_array($code, ['FAILED', 'REFUNDED'], true)) {
                OneClickOfframpTrace::log('oneclick_swap_terminal', $from, [
                    'pending_id' => $pendingId,
                    'code' => $code,
                    'oneclick_correlation' => $correlation,
                ]);
                error_log("1Click swap terminal (v2) {$code} for {$from} correlation={$correlation}");
                break;
            }
        }

        OneClickOfframpTrace::log('post_poll_summary', $from, [
            'pending_id' => $pendingId,
            'swap_done' => $swapDone,
            'oneclick_correlation' => $correlation,
        ]);

        $centiivOrder = isset($meta['centiiv_order_id']) && is_string($meta['centiiv_order_id']) && $meta['centiiv_order_id'] !== ''
            ? $meta['centiiv_order_id'] : null;
        $centiivHex = isset($meta['centiiv_order_id_hex']) && is_string($meta['centiiv_order_id_hex']) && $meta['centiiv_order_id_hex'] !== ''
            ? $meta['centiiv_order_id_hex'] : null;
        if ($centiivOrder !== null) {
            error_log("Sendra sell offramp context (v2) {$from} order={$centiivOrder}" . ($centiivHex !== null ? " hex={$centiivHex}" : '') . " correlation={$correlation}");
        }

        if ($swapDone && $pendingId !== '') {
            $this->pendingTx->mergeMeta($pendingId, [
                'need_oneclick_poll' => false,
                'oneclick_swap_status' => 'SUCCESS',
                'oneclick_bridge_user_notified' => true,
            ]);
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeGaslessWithRetry(string $from, string $pendingId, array $payload): array
    {
        $attempts = [1, 2];
        $last = ['success' => false, 'error' => 'Unknown error'];
        // Must exceed aptos-service worst case: SmoothSend client timeout (60s) + on-chain
        // recovery poll window (45s) = 105s. 150s leaves buffer so PHP doesn't cut off mid-recovery.
        $perAttemptTimeout = 150;

        foreach ($attempts as $attempt) {
            $result = $this->aptosClient->executeGasless($payload, $perAttemptTimeout);
            if (!empty($result['success'])) {
                if ($attempt > 1) {
                    OneClickOfframpTrace::log('aptos_execute_retry_ok', $from, [
                        'pending_id' => $pendingId,
                        'attempt' => $attempt,
                    ]);
                    error_log("[Sendra offramp] aptos execute_gasless retry succeeded pending={$pendingId} attempt={$attempt}");
                }

                return $result;
            }

            $last = $result;
            $err = (string) ($result['error'] ?? 'Unknown error');
            $smoothClass = OfframpExecuteErrorFormatter::classifySmoothSend($err);

            if ($attempt === 1 && OfframpExecuteErrorFormatter::shouldRetrySmoothSend($smoothClass)) {
                OneClickOfframpTrace::log('aptos_execute_retry', $from, [
                    'pending_id' => $pendingId,
                    'attempt' => 1,
                    'smoothsend_code' => $smoothClass['code'] ?? null,
                    'error' => $err,
                ]);
                error_log('[Sendra offramp aptos_execute] retry_scheduled ' . json_encode([
                    'pending_id' => $pendingId,
                    'smoothsend_code' => $smoothClass['code'] ?? null,
                    'error' => $err,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                sleep(2);
                continue;
            }

            break;
        }

        return $last;
    }
}
