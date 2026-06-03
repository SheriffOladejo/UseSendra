<?php

namespace App\Controllers;

use App\Services\WhatsAppService;
use App\Controllers\AuthController;

class MessageController
{
    private $whatsappService;
    private $authController;
    private $userModel;
    private $db;

    public function __construct(WhatsAppService $whatsappService, AuthController $authController, $db = null)
    {
        $this->whatsappService = $whatsappService;
        $this->authController  = $authController;
        $this->userModel       = $authController->getUserModel(); // shared User model instance
        $this->db              = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MAIN ROUTER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Route an incoming WhatsApp message to the correct handler.
     *
     * $user is the full DB row for the sender, or null if not registered.
     */
    public function handleIncomingMessage($from, $message, $user, $displayName = 'there')
    {
        $messageLower = strtolower(trim($message));
        if (\App\Services\WhatsAppInboundFilter::isOutboundEchoMessage([], $messageLower)) {
            error_log("handleIncomingMessage: ignored echo from={$from}");

            return ['success' => true, 'action' => 'ignored_echo'];
        }

        // ── 1. User is not registered yet ────────────────────────────────────
        if (!$user) {
            if ($this->isGreeting($messageLower)) {
                return $this->whatsappService->sendWelcomeMessage($from, $displayName);
            }
            // They might be sending a button reply before any greeting
            if ($messageLower === 'create_logistics_account') return $this->handleCreateLogisticsAccount($from, $displayName);
            if ($messageLower === 'create_account') return $this->handleCreateAccount($from, $displayName);
            if ($messageLower === 'import_account')  return $this->handleImportAccount($from, $displayName);

            return $this->whatsappService->sendMessage(
                $from,
                "👋 Hi! I'm Sendra. Type *hi* to get started."
            );
        }

        // ── 2. Awaiting key backup confirmation ──────────────────────────────
        if (!empty($user['awaiting_key_confirm'])) {
            return $this->handleKeyConfirmation($from, $messageLower, $user);
        }

        // ── 3. PIN setup in progress (awaiting first PIN entry) ──────────────
        if (!empty($user['awaiting_pin_setup'])) {
            if (preg_match('/^\d{6}$/', $message)) {
                return $this->handlePinSetup($from, $message);
            }
            // In PIN setup state but typed something else — re-prompt
            $this->whatsappService->sendMessage(
                $from,
                "\u{1F510} Please reply with your *6-digit PIN* to secure your account.\n\n" .
                    "Just type 6 numbers, e.g: 123456"
            );
            return ['success' => false, 'reason' => 'awaiting_pin'];
        }

        // ── 3. Pending transaction awaiting PIN confirmation ──────────────────
        if (!empty($user['pending_tx_id'])) {
            if (preg_match('/^\d{6}$/', $message)) {
                return $this->handlePinConfirmation($from, $message, $user);
            }
            // Support the Cancel button payload (`cancel_<id>`) while in pending state.
            if (str_starts_with($messageLower, 'cancel_')) {
                return $this->handleCancelTransaction($from, substr($message, 7));
            }
            if (strtolower(trim($message)) === 'cancel') {
                return $this->handleCancelTransaction($from, $user['pending_tx_id']);
            }
            // Has pending tx but typed something else — re-prompt
            $this->whatsappService->sendMessage(
                $from,
                "\u23F3 You have a pending transaction.\n\n" .
                    "Enter your *6-digit PIN* to confirm, or type *cancel* to abort."
            );
            return ['success' => false, 'reason' => 'awaiting_pin_confirmation'];
        }

        // ── 3b. Buy (on-ramp) multi-step — before sell ─────────────────────────
        $buyHandled = $this->handleBuyFlowMessage($from, $message, $messageLower, $user);
        if ($buyHandled !== null) {
            return $buyHandled;
        }

        // ── 3c. Sell (off-ramp) multi-step — before generic commands ───────────
        $sellHandled = $this->handleSellFlowMessage($from, $message, $messageLower, $user);
        if ($sellHandled !== null) {
            return $sellHandled;
        }

        // ── 3c. Idle: bank account screenshot → OCR payout (Centiiv verify) ───
        if ($this->getSellChatContext($user) === null && $this->parseImagePayload($message) !== null) {
            $ocrHandled = $this->handleIdleBankAccountImage($from, $message, $user);
            if ($ocrHandled !== null) {
                return $ocrHandled;
            }
        }

        // ── 4. Button / command routing ───────────────────────────────────────
        switch (true) {
            // Button replies from welcome message
            case $messageLower === 'create_account':
                return $this->handleCreateAccount($from, $user['username'] ?? 'there');

            case $messageLower === 'import_account':
                return $this->handleImportAccount($from, $displayName);

                // Cancel a pending transaction
            case str_starts_with($messageLower, 'cancel_'):
                return $this->handleCancelTransaction($from, substr($message, 7));

                // Send commands
            case str_starts_with($messageLower, '/send'):
                return $this->handleSendCommand($from);

                // `send …` is handled below with *sell* / *pay* so Naira bank lines hit cash-out, not P2P send.

                // Other commands
            case $messageLower === '/balance':
            case $messageLower === 'balance':
                return $this->handleBalanceCommand($from, $user);

            case $messageLower === '/receive':
            case $messageLower === 'receive':
                return $this->handleReceiveCommand($from, $user);

            case $messageLower === '/menu':
            case $messageLower === 'menu':
                return $this->handleGreeting($from, $user);

            case $messageLower === '/history':
            case $messageLower === 'history':
                return $this->handleHistoryCommand($from);

            case $this->isGreeting($messageLower):
                return $this->handleGreeting($from, $user);

            case $messageLower === 'buy':
            case str_starts_with($messageLower, 'buy '):
            case $messageLower === 'buy_fiat_ngn':
                return $this->handleBuyEntry($from, $message, $messageLower, $user);

            case $messageLower === 'sell':
            case str_starts_with($messageLower, 'sell '):
            case $messageLower === 'sell_fiat_ngn':
            case $messageLower === 'sell_fiat_ghs':
            case str_starts_with($messageLower, 'send '):
            case str_starts_with($messageLower, 'pay '):
                return $this->handleSellEntry($from, $message, $messageLower, $user);

            case $messageLower === 'swap':
            case str_starts_with($messageLower, 'swap '):
            case str_starts_with($messageLower, 'convert '):
                return $this->handleSwapEntry($from, $message, $user);

            default:
                return $this->handleUnknownCommand($from);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGISTICS ACCOUNT CREATION & IMPORT
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCreateLogisticsAccount($from, $username)
    {
        // For simplicity, logistics accounts are just regular accounts with a flag
        $result = $this->handleCreateAccount($from, $username, true);
        if ($result['success']) {
            $this->userModel->update($from, ['is_logistics' => 1]);
            $this->whatsappService->sendMessage(
                $from,
                "🚚 Your logistics account has been created!\n\n" .
                    "You can now get delivery orders through our platform."
            );
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACCOUNT CREATION & IMPORT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sendra-native flow: generate keypair on server, store encrypted key.
     */
    private function handleCreateAccount($from, $username, $isLogistics = false)
    {
        // Don't create a second account if already registered
        if ($this->userModel->exists($from)) {
            $this->whatsappService->sendMessage(
                $from,
                "✅ You already have a Sendra account!\nType *menu* to see what you can do."
            );
            return ['success' => false, 'reason' => 'already_exists'];
        }

        if ($isLogistics) {
            $this->whatsappService->sendMessage($from, "⏳ Setting up your logistics account...");
        } else {
            $this->whatsappService->sendMessage($from, "⏳ Setting up your Sendra account...");
        }

        // ── Call Node.js aptos-service ────────────────────────────────────────
        $ch = curl_init('http://127.0.0.1:3001/accounts/create');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['phone_number' => $from]),
            CURLOPT_HTTPHEADER     => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!$response || empty($response['success'])) {
            error_log("aptos-service account creation failed for $from: " . json_encode($response));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Something went wrong creating your account. Please try again."
            );
            return ['success' => false];
        }

        // ── Save to DB ────────────────────────────────────────────────────────
        $result = $this->authController->signup(
            $from,
            $username,
            $response['aptos_address'],
            $response['encrypted_private_key'],
            'sendra',
            $isLogistics
        );

        if (!$result['success']) {
            error_log("DB signup failed for $from: " . $result['message']);
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not save your account. Please try again."
            );
            return ['success' => false];
        }

        if (!$isLogistics) {
            // ── Show private key ONCE, save wamid, ask for confirmation ────────────
            $keyResult = $this->whatsappService->sendButtonMessage(
                $from,
                "✅ *Your Sendra wallet is ready!*\n\n" .
                    "📍 *Address:*\n`{$response['aptos_address']}`\n\n" .
                    "🔐 *Backup Key — save this now, shown only once:*\n" .
                    "`{$response['private_key_for_display']}`\n\n" .
                    "⚠️ Store this somewhere safe. It lets you access your funds independently of Sendra.\n\n" .
                    "━━━━━━━━━━━━━━━━━━━━\n" .
                    "Tap *I've saved it* once you've stored the key safely. " .
                    "The message will then be deleted for your security.",
                ['key_saved' => "✅ I've saved it"]
            );

            // Save the wamid so we can delete the key message after confirmation
            $wamid = $keyResult['response']['messages'][0]['id'] ?? null;
            if ($wamid) {
                $this->userModel->update($from, [
                    'key_message_wamid'   => $wamid,
                    'awaiting_key_confirm' => 1,
                ]);
            } else {
                // Couldn't get wamid — still proceed but won't be able to delete
                error_log("Could not capture wamid for key message — $from");
                $this->userModel->update($from, ['awaiting_pin_setup' => 1]);
            }
        }

        return ['success' => true, 'account_type' => 'sendra'];
    }

    /**
     * Petra flow: redirect user to wallet adapter website.
     */
    private function handleImportAccount($from, $username)
    {
        $url = "https://usesendra.com/client/?"
            . "sender=" . urlencode($from)
            . "&username="    . urlencode($username)
            . "&mode=import";

        $this->whatsappService->sendCTAUrlButton(
            $from,
            "🔗 *Import your existing wallet*\n\n" .
                "Connect your Petra wallet to link it with your Sendra account.\n\n" .
                "After connecting, you'll approve transactions through Petra on our website.",
            "Connect Petra Wallet",
            $url
        );

        return ['success' => true, 'account_type' => 'petra'];
    }

    /**
     * Handle the "I've saved my key" confirmation.
     * Deletes the private key message and moves user to PIN setup.
     */
    private function handleKeyConfirmation($from, $message, $user)
    {
        if ($message !== 'key_saved' && !in_array($message, ['yes', 'saved', 'done', 'ok', 'i have saved it'])) {
            // Re-prompt — don't let user proceed until they confirm
            $this->whatsappService->sendButtonMessage(
                $from,
                "⚠️ Please confirm you've saved your backup key before continuing.\n\n" .
                    "Once you confirm, the key message will be deleted for your security.",
                ['key_saved' => "✅ I've saved it"]
            );
            return ['success' => false, 'reason' => 'awaiting_key_confirm'];
        }

        // ── Delete the key message ────────────────────────────────────────────
        $wamid = $user['key_message_wamid'] ?? null;
        if ($wamid) {
            $deleted = $this->whatsappService->deleteMessage($from, $wamid);
            error_log("Key message delete for $from (wamid=$wamid): " . ($deleted ? 'success' : 'failed'));
        }

        // ── Clear confirm state, move to PIN setup ────────────────────────────
        $this->userModel->update($from, [
            'awaiting_key_confirm' => 0,
            'key_message_wamid'    => null,
            'awaiting_pin_setup'   => 1,
        ]);

        $this->whatsappService->sendMessage(
            $from,
            "✅ *Key confirmed and message deleted.*\n\n" .
                "🔐 Now set a *6-digit PIN* to confirm transactions.\n" .
                "Type your PIN now:"
        );

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PIN MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * First-time PIN setup — called when awaiting_pin_setup = 1
     */
    private function handlePinSetup($from, $pin)
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ PIN must be exactly 6 digits. Please try again:"
            );
            return ['success' => false];
        }

        $this->userModel->setPin($from, $pin);

        $this->whatsappService->sendMessage(
            $from,
            "✅ *PIN set successfully!*\n\n" .
                "You'll use this PIN to confirm all transactions.\n\n" .
                "Type *menu* to see what you can do."
        );

        return ['success' => true];
    }

    /**
     * PIN confirmation for a pending transaction.
     */
    private function handlePinConfirmation($from, $pin, $user)
    {
        error_log("PIN_CONFIRM: from=$from pending_tx_id=" . ($user['pending_tx_id'] ?? 'NULL'));

        // Verify PIN
        $pinValid = $this->userModel->verifyPin($from, $pin);
        error_log("PIN_CONFIRM: pin_valid=" . ($pinValid ? 'YES' : 'NO'));
        if (!$pinValid) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Wrong PIN. Please try again, or type *cancel* to abort."
            );
            return ['success' => false];
        }

        $pendingId = trim((string) ($user['pending_tx_id'] ?? ''));
        if ($pendingId === '') {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ No pending transaction found. Please start again."
            );
            return ['success' => false];
        }

        // Fetch pending transaction
        $pending = $this->getPendingTransaction($pendingId);

        if (!$pending) {
            $existing = $this->getPendingTransactionRow($pendingId);
            if ($existing !== null && $this->pendingTransactionAlreadyClaimedOrDone($existing)) {
                error_log("PIN_CONFIRM: duplicate webhook skipped pending_id={$pendingId} status=" . ($existing['status'] ?? ''));
                $this->whatsappService->sendMessage(
                    $from,
                    "⏳ That transaction is already processing or finished. Check *balance* or your explorer link."
                );
                $this->userModel->clearPendingTransaction($from);

                return ['success' => false, 'reason' => 'duplicate_pin'];
            }
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ No pending transaction found. Please start again."
            );
            $this->userModel->clearPendingTransaction($from);
            return ['success' => false];
        }

        if (strtotime($pending['expires_at']) < time()) {
            $this->whatsappService->sendMessage(
                $from,
                "⏰ Transaction expired (5 min limit). Please start again."
            );
            $this->userModel->clearPendingTransaction($from);
            return ['success' => false];
        }

        // One execution per pending row (WhatsApp often delivers the same PIN 2–3×).
        if (!$this->claimPendingTransactionForExecution($pendingId)) {
            error_log("PIN_CONFIRM: claim lost race pending_id={$pendingId}");
            $this->whatsappService->sendMessage(
                $from,
                "⏳ That transaction is already processing. You'll get a result message shortly."
            );
            $this->userModel->clearPendingTransaction($from);

            return ['success' => false, 'reason' => 'duplicate_pin'];
        }
        $this->userModel->clearPendingTransaction($from);

        // Guard: petra users and legacy accounts have no encrypted key — can't sign
        if (empty($user['encrypted_private_key'])) {
            $this->userModel->clearPendingTransaction($from);
            $accountType = $user['account_type'] ?? 'unknown';
            if ($accountType === 'petra') {
                $this->whatsappService->sendMessage(
                    $from,
                    "⚠️ Your account uses Petra wallet.

" .
                        "To send funds, use the send command and approve through your Petra wallet on our website."
                );
            } else {
                $this->whatsappService->sendMessage(
                    $from,
                    "⚠️ Your account was created before the current wallet system.

" .
                        "Please type *hi* and create a new Sendra account to enable in-chat transactions."
                );
            }
            return ['success' => false, 'reason' => 'no_private_key'];
        }

        if (($pending['tx_type'] ?? '') === 'offramp_panora_oneclick_centiiv') {
            return $this->executeCentiivPanoraOneClickOfframpTransfer($from, $user, $pending);
        }

        if (($pending['tx_type'] ?? '') === 'offramp_oneclick_centiiv') {
            return $this->executeCentiivOneClickOfframpTransfer($from, $user, $pending);
        }

        if (($pending['tx_type'] ?? '') === 'offramp_centiiv') {
            return $this->executeCentiivOfframpTransfer($from, $user, $pending);
        }

        if (($pending['tx_type'] ?? '') === 'panora_wallet_swap') {
            return $this->executePanoraWalletSwap($from, $user, $pending);
        }

        $this->whatsappService->sendMessage($from, "⏳ Processing your transaction...");

        $useMethod2Gasless = $this->aptosSendUsesGaslessMethod2();

        if ($useMethod2Gasless) {
            $result = $this->aptosServiceExecuteGasless([
                'sender_address'        => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'function'              => '0x1::aptos_account::transfer_fungible_assets',
                // Shortcut supported by aptos-service: builds
                // 0x1::aptos_account::transfer_fungible_assets(metadata, recipient, rawAmount)
                'recipient'             => $pending['recipient_address'],
                'amount'                => $pending['amount'],
                'currency'              => $pending['currency'],
            ]);
            $curlError = null;
            $rawResponse = isset($result['raw']) ? (string) $result['raw'] : json_encode($result);
            error_log('[send] aptos-service execute-gasless used. submit_mode=' . (string) ($result['submit_mode'] ?? '') . ' function=' . (string) ($result['function'] ?? ''));
        } else {
            // ── Call Node.js to execute (existing path) ────────────────────────
            $ch = curl_init('http://127.0.0.1:3001/transactions/execute');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'sender_address'        => $user['aptos_address'],
                    'encrypted_private_key' => $user['encrypted_private_key'],
                    'recipient'             => $pending['recipient_address'],
                    'amount'                => $pending['amount'],
                    'currency'              => $pending['currency'],
                ]),
                CURLOPT_HTTPHEADER     => $this->aptosServiceAuthHeaders(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $curlError = null;
            $rawResponse = curl_exec($ch);
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
                error_log("aptos-service execute curl error: $curlError");
            }
            curl_close($ch);
            $result = json_decode($rawResponse, true);
            error_log('[send] aptos-service execute used. transfer_mode=' . (string) ($result['transfer_mode'] ?? '') . ' force_smoothsend=' . (string) ($result['force_smoothsend'] ?? ''));
        }

        $this->userModel->clearPendingTransaction($from);

        if (!empty($result['success'])) {
            $hash = (string) $result['tx_hash'];
            $explorerUrl = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);
            $this->updatePendingTransactionStatus($pending['id'], 'confirmed', $hash);
            $this->recordTransaction([
                'user_phone'    => $from,
                'direction'     => 'out',
                'amount'        => $pending['amount'],
                'currency'      => $pending['currency'],
                'status'        => 'confirmed',
                'aptos_address' => $user['aptos_address'],
                'counterparty'  => $pending['recipient_address'],
                'tx_hash'       => $hash,
                'explorer_url'  => $explorerUrl,
            ]);
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Transaction Confirmed!*\n\n" .
                    "Sent: *{$pending['amount']} {$pending['currency']}*\n" .
                    "To: `{$pending['recipient_address']}`\n\n" .
                    "🔗 {$explorerUrl}"
            );
        } else {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $errorMsg = $result['error'] ?? ($curlError ? "Service unreachable" : "Unknown error");
            error_log("Transaction failed for $from: $errorMsg | raw: $rawResponse");
            $this->whatsappService->sendMessage(
                $from,
                "❌ Transaction failed: {$errorMsg}\n\nPlease try again or contact support."
            );
        }

        return ['success' => !empty($result['success'])];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEND
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Show send format instructions (triggered by bare /send)
     */
    private function handleSendCommand($from)
    {
        $reply = "Please provide the full details to send:\n\n" .
            "Format: *send [amount] [currency] to [recipient]*\n\n" .
            "Examples:\n" .
            "`send 50 USDT to 2348012345678`\n" .
            "`send 10 USDC to 0x1234...`\n\n" .
            "Recipient can be a *phone number* or *Aptos address*.\n" .
            "Supported currencies: *USDT*, *USDC*";

        $this->whatsappService->sendMessage($from, $reply);
        return ['success' => false, 'action' => 'send_help'];
    }

    /**
     * Parse a full send command, route by account type.
     * Sendra users: queue pending tx + ask for PIN
     * Petra users:  send CTA URL to website
     */
    private function handleSendInitiation($from, $message, $user)
    {
        // ── Parse ─────────────────────────────────────────────────────────────
        $parts = preg_split('/\s+/', trim($message));
        // Expected: ['send', '50', 'usdt', 'to', '0xabc...']
        if (count($parts) < 5 || strtolower($parts[3]) !== 'to') {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Format: *send [amount] [currency] to [address]*\n" .
                    "Example: `send 50 USDT to 0x00...`"
            );
            return ['success' => false];
        }

        $amount    = $parts[1];
        $currency  = strtoupper($parts[2]);
        $recipient = $parts[4];

        if (!is_numeric($amount) || floatval($amount) <= 0) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Please specify a valid amount greater than 0."
            );
            return ['success' => false];
        }

        if (!in_array($currency, ['USDT', 'USDC'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Unsupported currency *{$currency}*. Use USDT or USDC."
            );
            return ['success' => false];
        }

        // ── Resolve phone number to Aptos address ────────────────────────────
        // Accepts: +2348012345678, 2348012345678, 08012345678 (auto-prefixes 234)
        $recipientName = null;
        if (preg_match('/^\+?\d{7,15}$/', $recipient)) {
            $lookupPhone = $this->normalizePhone($recipient);
            $recipientUser = $this->userModel->findByPhone($lookupPhone);

            if (!$recipientUser || empty($recipientUser['aptos_address'])) {
                return $this->handleVoucherCreation($from, $user, $lookupPhone, $amount, $currency);
            }

            $recipientName = $recipientUser['username'] ?? $lookupPhone;
            $recipient     = $recipientUser['aptos_address'];
            error_log("Resolved phone $lookupPhone to address $recipient ($recipientName)");
        } elseif (!preg_match('/^0x[a-fA-F0-9]{60,64}$/', $recipient)) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Invalid recipient.

" .
                    "Send to a *phone number* (e.g. `2348012345678`) or an *Aptos address* (e.g. `0x1234...`)."
            );
            return ['success' => false, 'reason' => 'invalid_recipient'];
        }

        // ── Route: has server-side key = full in-chat flow, no key = Petra redirect ──
        if (!empty($user['encrypted_private_key'])) {
            return $this->queueTransactionAndAskForPin($from, $user, $amount, $currency, $recipient, $recipientName);
        }

        // Petra / legacy wallet adapter user — redirect to website for approval
        $url = "https://usesendra.com/client?action=send"
            . "&amount="    . urlencode($amount)
            . "&currency="  . urlencode($currency)
            . "&recipient=" . urlencode($recipient)
            . "&sender="    . urlencode($from);

        $toLabel = $recipientName ? "*{$recipientName}*" : "`{$recipient}`";
        $summary = "📤 *New Transaction*\n\n" .
            "Send: *{$amount} {$currency}*\n" .
            "To: {$toLabel}\n\n" .
            "Click below to approve with your Petra wallet.";

        $this->whatsappService->sendCTAUrlButton($from, $summary, "Approve Transaction", $url);
        return ['success' => true, 'account_type' => 'petra'];
    }

    /**
     * Normalize a phone number to international format (no + prefix).
     * Handles: 08012345678 → 2348012345678, +2348012345678 → 2348012345678
     */
    private function normalizePhone($phone)
    {
        // Strip all non-digits
        $digits = preg_replace('/\D/', '', $phone);

        // Nigerian local format: 0XXXXXXXXXX → 234XXXXXXXXXX
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = '234' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Store a pending transaction and ask the user for their PIN.
     */
    private function queueTransactionAndAskForPin($from, $user, $amount, $currency, $recipient, $recipientName = null)
    {
        if (empty($user['pin_hash'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ You haven't set a PIN yet.\n" .
                    "Please set a *6-digit PIN* now to enable transactions:"
            );
            $this->userModel->update($from, ['awaiting_pin_setup' => 1]);
            return ['success' => false, 'reason' => 'no_pin'];
        }

        $shortAddr        = substr($recipient, 0, 8) . '...' . substr($recipient, -4);
        $displayRecipient = $recipientName ? "*{$recipientName}*" : "`{$shortAddr}`";
        $descRecipient    = $recipientName ? "{$recipientName} ({$shortAddr})" : $shortAddr;

        $pendingId = $this->storePendingTransaction([
            'user_phone'        => $from,
            'tx_type'           => 'transfer',
            'amount'            => $amount,
            'currency'          => $currency,
            'recipient_address' => $recipient,
            'human_description' => "Send {$amount} {$currency} to {$descRecipient}",
            'expires_at'        => date('Y-m-d H:i:s', time() + 300), // 5 min TTL
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        $this->whatsappService->sendButtonMessage(
            $from,
            "📤 *Confirm Transaction*\n\n" .
                "Send: *{$amount} {$currency}*\n" .
                "To: {$displayRecipient}\n\n" .
                "Enter your *6-digit PIN* to confirm:",
            ['cancel_' . $pendingId => 'Cancel']
        );

        return ['success' => true, 'pending_id' => $pendingId];
    }

    /**
     * Cancel a pending transaction by ID.
     */
    private function handleCancelTransaction($from, $pendingTxId)
    {
        $this->updatePendingTransactionStatus($pendingTxId, 'cancelled');
        $this->userModel->clearPendingTransaction($from);
        $this->clearSellChatContext($from);

        $this->whatsappService->sendMessage(
            $from,
            "✅ Transaction cancelled.\n\nType *menu* for options."
        );
        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BALANCE
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBalanceCommand($from, $user)
    {
        $walletAddress = $user['aptos_address'] ?? $this->getWalletAddressByPhone($from);

        if (!$walletAddress) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ No wallet found for your account.\n\nType *hi* to get started."
            );
            return ['success' => false, 'action' => 'balance', 'error' => 'no_wallet'];
        }

        $this->whatsappService->sendMessage($from, "💰 Fetching your balance...");

        $balances = $this->fetchAptosBalance($walletAddress);

        if ($balances === null) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Failed to fetch balance. Please try again."
            );
            return ['success' => false, 'action' => 'balance', 'error' => 'fetch_failed'];
        }

        $this->whatsappService->sendMessage(
            $from,
            "*💰 Your Wallet Balance*\n\n" .
                "💵 USDC: *\${$balances['USDC']}*\n" .
                "💵 USDT: *\${$balances['USDT']}*\n\n" .
                "_Updated: " . date('M d, Y g:i A') . "_"
        );

        return ['success' => true, 'action' => 'balance'];
    }

    /**
     * Balances from aptos-service (same APTOS_NETWORK and USDC/USDT metadata as chain sends).
     * Avoids the old hardcoded testnet fullnode + testnet token addresses in PHP.
     */
    private function fetchAptosBalance($walletAddress)
    {
        $ch = curl_init('http://127.0.0.1:3001/accounts/balance');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['aptos_address' => $walletAddress]),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            error_log('fetchAptosBalance curl: ' . curl_error($ch));
        }
        curl_close($ch);

        if (!is_string($raw) || $http < 200 || $http >= 300) {
            error_log("fetchAptosBalance: aptos-service /accounts/balance HTTP {$http} " . substr((string) $raw, 0, 300));

            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['success']) || !isset($j['balances']) || !is_array($j['balances'])) {
            return null;
        }
        $b = $j['balances'];

        return [
            'APT' => number_format((float) ($b['APT'] ?? 0), 4),
            'USDC' => number_format((float) ($b['USDC'] ?? 0), 2),
            'USDT' => number_format((float) ($b['USDT'] ?? 0), 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECEIVE
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReceiveCommand($from, $user)
    {
        $walletAddress = $user['aptos_address'] ?? $this->getWalletAddressByPhone($from);

        if (!$walletAddress) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ No wallet found. Type *hi* to create or import one."
            );
            return ['success' => false, 'action' => 'receive', 'error' => 'no_wallet'];
        }

        $this->whatsappService->sendMessage($from, "💳 *Your Deposit Address*");
        usleep(300000); // small delay so messages arrive in order
        $this->whatsappService->sendMessage($from, $walletAddress);
        usleep(300000);
        $this->whatsappService->sendMessage(
            $from,
            "*📋 Instructions:*\n" .
                "• Copy the address above\n" .
                "• Share it with your sender\n" .
                "• Ask them to send on the *Aptos network* only\n\n" .
                "*⚠️ Important:*\n" .
                "• Only USDC or USDT on Aptos is supported\n" .
                "• Wrong network = lost funds\n" .
                "• You'll be notified here when funds arrive"
        );

        return ['success' => true, 'action' => 'receive'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PENDING TRANSACTIONS (DB helpers)
    // ─────────────────────────────────────────────────────────────────────────

    private function storePendingTransaction($data)
    {
        $id = bin2hex(random_bytes(16)); // UUID-like
        $stmt = $this->db->prepare("
            INSERT INTO pending_transactions
                (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
            VALUES
                (:id, :user_phone, :tx_type, :amount, :currency, :recipient_address, :human_description, :expires_at, 'pending', NOW())
        ");
        $stmt->execute(array_merge(['id' => $id], $data));
        return $id;
    }

    private function getPendingTransaction($id)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_transactions WHERE id = :id AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function getPendingTransactionRow($id)
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM pending_transactions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Lock pending row so duplicate WhatsApp PIN deliveries only execute once.
     * (status enum has no "processing" — use human_description.pin_claimed + row lock.)
     */
    private function claimPendingTransactionForExecution(string $id): bool
    {
        if (!$this->db || $id === '') {
            return false;
        }
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'SELECT id, status, human_description FROM pending_transactions WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (string) ($row['status'] ?? '') !== 'pending') {
                $this->db->rollBack();

                return false;
            }
            $meta = json_decode((string) ($row['human_description'] ?? ''), true);
            if (!is_array($meta)) {
                $meta = [];
            }
            if (!empty($meta['pin_claimed'])) {
                $this->db->rollBack();

                return false;
            }
            $meta['pin_claimed'] = true;
            $meta['pin_claimed_at'] = gmdate('c');
            $u = $this->db->prepare(
                'UPDATE pending_transactions SET human_description = ? WHERE id = ? AND status = ?'
            );
            $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id, 'pending']);
            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('claimPendingTransactionForExecution: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pendingTransactionAlreadyClaimedOrDone(array $row): bool
    {
        $st = (string) ($row['status'] ?? '');
        if (in_array($st, ['confirmed', 'failed', 'cancelled', 'expired'], true)) {
            return true;
        }
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            return false;
        }

        return !empty($meta['pin_claimed']);
    }

    private function updatePendingTransactionStatus($id, $status, $txHash = null)
    {
        $stmt = $this->db->prepare(
            "UPDATE pending_transactions SET status = :status, tx_hash = :tx_hash WHERE id = :id"
        );
        $stmt->execute(['status' => $status, 'tx_hash' => $txHash, 'id' => $id]);
    }

    /**
     * Merge JSON stored in pending_transactions.human_description (off-ramp meta).
     *
     * @param array<string, mixed> $patch
     */
    private function mergePendingTransactionHumanDescription(string $id, array $patch): void
    {
        if (!$this->db) {
            return;
        }
        $stmt = $this->db->prepare('SELECT human_description FROM pending_transactions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta = array_merge($meta, $patch);
        $u = $this->db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    }

    private function oneclickCentiivRampConfigured(): bool
    {
        $enabled = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_RAMP_ENABLED'] ?? getenv('SENDRA_ONECLICK_RAMP_ENABLED') ?: '1')));
        if ($enabled === '0' || $enabled === 'false' || $enabled === 'off') {
            return false;
        }
        $g = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        if ($g === '' || strlen($g) < 5 || $g[0] !== 'G') {
            return false;
        }
        $oc = new \App\Services\SendraOneClickService();

        return $oc->isConfigured();
    }

    /**
     * USDT off-ramp: Panora Aptos USDT→USDC (user wallet) then 1Click USDC→Stellar → Centiiv.
     */
    private function panoraUsdtOfframpEnabled(): bool
    {
        if (!$this->oneclickCentiivRampConfigured()) {
            return false;
        }

        return \App\Services\SendraPanoraService::isUsdtOfframpEnabled();
    }

    private function panoraWalletSwapEnabled(): bool
    {
        return \App\Services\SendraPanoraService::isWalletSwapEnabled();
    }

    /**
     * Send-naira sizing: rate math + 1–2 Centiiv quotes (not binary search). Centiiv has no exact-out quote API.
     */
    private function sellTargetNgnFastQuoteEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_SELL_TARGET_NGN_FAST_QUOTE'] ?? getenv('SENDRA_SELL_TARGET_NGN_FAST_QUOTE') ?: '1')));

        return !in_array($flag, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @return ?array{crypto_amount: string, receive_ngn_display: string, rate_hint: ?string, fee_hint: ?string}
     */
    private function sellSolveUsdcFastForTargetNgn($from, float $avail, float $targetNgn, float $toleranceNgn, \App\Services\SendraCentiivRampService $agg): ?array
    {
        $solveTargetNgn = $targetNgn + \App\Services\SendraOfframpSlippageService::extraNgnBuffer();
        $solved = $agg->solveUsdcForTargetNgn($solveTargetNgn, $toleranceNgn);
        if ($solved === null) {
            $this->whatsappService->sendMessage($from, '❌ Could not get a rate right now. Try again.');

            return null;
        }
        if (isset($solved['centiiv_min_usdc'])) {
            $this->whatsappService->sendMessage($from, $this->buildCentiivMinPayoutMessage($agg, (float) $solved['centiiv_min_usdc']));

            return null;
        }
        $amtStr = (string) $solved['usdc_amount_str'];
        $amt = (float) $amtStr;
        if ($avail + 1e-9 < $amt) {
            $this->whatsappService->sendMessage(
                $from,
                '❌ Your balance isn’t enough for *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($avail, 2) . "* USDC. Try a smaller amount."
            );

            return null;
        }
        $sum = $solved['sum'];

        return [
            'crypto_amount' => $amtStr,
            'receive_ngn_display' => (string) ($sum['receive_ngn_display'] ?? ''),
            'rate_hint' => $sum['rate_hint'] ?? null,
            'fee_hint' => $sum['fee_hint'] ?? null,
        ];
    }

    /**
     * @return ?array{
     *   crypto_amount: string,
     *   receive_ngn_display: string,
     *   rate_hint: ?string,
     *   fee_hint: ?string,
     *   panora_est_usdc: float,
     *   panora_quote_data: array<string, mixed>
     * }
     */
    private function sellSolveUsdtPanoraFastForTargetNgn(
        $from,
        float $avail,
        float $targetNgn,
        float $toleranceNgn,
        string $aptosWallet0x,
        \App\Services\SendraCentiivRampService $agg,
        \App\Services\SendraPanoraService $panora
    ): ?array {
        $extraNgn = \App\Services\SendraOfframpSlippageService::extraNgnBuffer();
        $solveTargetNgn = $targetNgn + $extraNgn;
        $lastUsdtStr = null;
        $lastPqData = null;
        $lastPanoraWalletUsdc = 0.0;
        $lastDepositUsdc = 0.0;
        $lastSum = null;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $usdcSolve = $agg->solveUsdcForTargetNgn($solveTargetNgn, $toleranceNgn);
            if ($usdcSolve === null) {
                $this->whatsappService->sendMessage($from, '❌ Could not get a Naira rate right now. Try again.');

                return null;
            }
            if (isset($usdcSolve['centiiv_min_usdc'])) {
                $this->whatsappService->sendMessage($from, $this->buildCentiivMinPayoutMessage($agg, (float) $usdcSolve['centiiv_min_usdc']));

                return null;
            }
            $usdcStr = (string) $usdcSolve['usdc_amount_str'];
            $pq = $panora->requestSwapQuoteExactOut($usdcStr, $aptosWallet0x);
            if (!$pq['success'] || !is_array($pq['data'] ?? null)) {
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Could not get a quote right now. Try again in a few minutes or use *USDC*.'
                );

                return null;
            }
            $parsed = \App\Services\SendraPanoraService::parseSwapResponse($pq['data']);
            $panoraWalletUsdc = \App\Services\SendraPanoraService::centiivUsdcAmountFromQuote(
                $parsed,
                (float) $usdcStr
            );
            if ($panoraWalletUsdc <= 0) {
                $this->whatsappService->sendMessage($from, '❌ Swap quote incomplete. Try again.');

                return null;
            }
            // PIN / sizing quote: wallet USDC after Panora (what user expects). Order uses haircut USDC (bridge loss).
            $depositUsdc = \App\Services\SendraCentiivRampService::expectedStellarDepositUsdc($panoraWalletUsdc);
            $confirm = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => $panoraWalletUsdc,
            ]);
            if (!$confirm['success']) {
                $this->whatsappService->sendMessage($from, '❌ Could not get a Naira rate right now. Try again.');

                return null;
            }
            $sum = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($confirm['data'], $panoraWalletUsdc);
            $recv = (float) ($sum['receive_ngn'] ?? 0);

            $usdtNeeded = $parsed['from_token_amount'] ?? null;
            if ($usdtNeeded === null || $usdtNeeded <= 0) {
                $this->whatsappService->sendMessage($from, '❌ Swap quote incomplete. Try again.');

                return null;
            }
            $usdtStr = rtrim(rtrim(number_format($usdtNeeded, 6, '.', ''), '0'), '.');
            if ($usdtStr === '') {
                $usdtStr = (string) $usdtNeeded;
            }

            $lastUsdtStr = $usdtStr;
            $lastPqData = $pq['data'];
            $lastPanoraWalletUsdc = $panoraWalletUsdc;
            $lastDepositUsdc = $depositUsdc;
            $lastSum = $sum;

            if ($recv + 1e-9 >= $targetNgn - $toleranceNgn) {
                break;
            }
            $shortfall = $targetNgn - $recv;
            $solveTargetNgn = $targetNgn + max($shortfall * 1.02, $targetNgn * 0.01);
        }

        if ($lastUsdtStr === null || $lastSum === null || $lastDepositUsdc <= 0) {
            return null;
        }
        if ($avail + 1e-9 < (float) $lastUsdtStr) {
            $this->whatsappService->sendMessage(
                $from,
                '❌ Your balance isn’t enough for *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($avail, 2) . "* USDT. Try a smaller amount."
            );

            return null;
        }
        $finalRecv = (float) ($lastSum['receive_ngn'] ?? 0);
        if ($finalRecv + 1e-9 < $targetNgn - $toleranceNgn) {
            $this->whatsappService->sendMessage(
                $from,
                '❌ Could not size a *USDT* amount for *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* right now.\nTry *USDC* or a slightly different amount."
            );

            return null;
        }

        return [
            'crypto_amount' => $lastUsdtStr,
            'receive_ngn_display' => (string) ($lastSum['receive_ngn_display'] ?? ''),
            'rate_hint' => $lastSum['rate_hint'] ?? null,
            'fee_hint' => $lastSum['fee_hint'] ?? null,
            'panora_est_usdc' => $lastPanoraWalletUsdc,
            'panora_centiiv_usdc' => $lastDepositUsdc,
            'panora_quote_data' => $lastPqData,
        ];
    }

    /**
     * Block sell entry when ramp health cache (cron_ramp_health.php) reports outages.
     *
     * @return ?array<string, mixed> non-null = flow should stop (message already sent)
     */
    private function sellRampHealthGateIfDown($from): ?array
    {
        $centiiv = new \App\Services\SendraCentiivRampService();
        if (!$centiiv->isConfigured()) {
            return null;
        }
        $gate = \App\Services\SendraRampHealthService::evaluateSellGate(true, $this->oneclickCentiivRampConfigured());
        if (empty($gate['block'])) {
            return null;
        }
        $this->whatsappService->sendMessage($from, (string) ($gate['message'] ?? '⚠️ Cash-out is temporarily unavailable. Try again soon.'));

        return ['success' => false, 'action' => 'ramp_health_blocked'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function getWalletAddressByPhone($phoneNumber)
    {
        if (!$this->db) return null;
        try {
            $stmt = $this->db->prepare("SELECT aptos_address FROM users WHERE phone_number = ? LIMIT 1");
            $stmt->execute([$phoneNumber]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result['aptos_address'] ?? null;
        } catch (\PDOException $e) {
            error_log("getWalletAddressByPhone error: " . $e->getMessage());
            return null;
        }
    }

    private function handleGreeting($from, $user)
    {
        $this->clearSellChatContext($from);
        $this->clearBuyChatContext($from);
        $name = $user['username'] ?? 'there';

        $message = '';

        if ($user['is_logistics'] === 1) {
            $message = "Welcome back, {$name}! 🚚\n\n";
        }
        else {
            $message = "Hi {$name} 🙋🏽‍♀️ I'm Sendra.\n\n" .
                "*Main Menu*\n" .
                "💰 Check balance — type `/balance`\n" .
                "🛒 Buy crypto with Naira — type `buy` or `buy 5000`\n" .
                "📈 Sell crypto for Naira — type `sell` or `sell 20 USDC`\n" .
                "📤 Send USDT/USDC — type `send 50 USDT to 0x...`\n" .
                "🔄 Swap USDT ↔ USDC — type `swap 2 USDT to USDC`\n" .
                "📥 Receive funds — type `/receive`\n" .
                "❓ Help — type `/help`";
        }
        
        $this->whatsappService->sendMessage(
            $from,
            $message
        );
        return ['success' => true, 'action' => 'greeting'];
    }

    private function handleUnknownCommand($from)
    {
        $this->whatsappService->sendMessage(
            $from,
            "Sorry, I didn't understand that.\nType *menu* for available commands."
        );
        return ['success' => false, 'action' => 'unknown'];
    }

    private function isGreeting($message)
    {
        foreach (['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'start'] as $g) {
            if (stripos($message, $g) !== false) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSACTION HISTORY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a confirmed transaction to the permanent history ledger.
     * Called after successful send (out) and on deposit notification (in).
     * INSERT IGNORE prevents duplicates if a webhook fires twice.
     */
    private function recordTransaction($data)
    {
        if (!$this->db) return;
        try {
            $stmt = $this->db->prepare("
                INSERT INTO transaction_history
                    (id, user_phone, direction, amount, currency, status,
                     aptos_address, counterparty, tx_hash, explorer_url, created_at)
                VALUES
                    (:id, :user_phone, :direction, :amount, :currency, :status,
                     :aptos_address, :counterparty, :tx_hash, :explorer_url, NOW())
                ON DUPLICATE KEY UPDATE id = id
            ");
            $stmt->execute(array_merge(['id' => bin2hex(random_bytes(16))], $data));
        } catch (\PDOException $e) {
            error_log("recordTransaction failed: " . $e->getMessage());
            // Non-fatal — don't break the transaction flow
        }
    }

    /**
     * Show the last 5 transactions for the user — /history command.
     */
    private function handleHistoryCommand($from)
    {
        if (!$this->db) {
            $this->whatsappService->sendMessage($from, "❌ History unavailable.");
            return ['success' => false];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT direction, amount, currency, counterparty, tx_hash, created_at
                FROM transaction_history
                WHERE user_phone = ?
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$from]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("handleHistoryCommand error: " . $e->getMessage());
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not load history. Please try again later."
            );
            return ['success' => false];
        }

        if (empty($rows)) {
            $this->whatsappService->sendMessage(
                $from,
                "📭 *No transactions yet.*

" .
                    "Send or receive USDC/USDT to see your history here."
            );
            return ['success' => true];
        }

        $lines = "*📋 Recent Transactions*

";
        foreach ($rows as $tx) {
            $arrow = $tx['direction'] === 'out' ? '📤 Sent' : '📥 Received';
            $label = $tx['direction'] === 'out' ? 'To' : 'From';
            $short = substr($tx['counterparty'], 0, 6) . '...' . substr($tx['counterparty'], -4);
            $date  = date('M d, g:ia', strtotime($tx['created_at']));
            $lines .= "{$arrow} *{$tx['amount']} {$tx['currency']}*
";
            $lines .= "{$label}: `{$short}`
";
            $lines .= "_{$date}_

";
        }

        $this->whatsappService->sendMessage($from, rtrim($lines));
        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VOUCHERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a voucher when sender tries to pay a phone number not on Sendra.
     * Stores the voucher in DB, sends the claim link back to sender.
     */
    private function handleVoucherCreation($from, $user, $recipientPhone, $amount, $currency)
    {
        // Only sendra users with a server-side key can create vouchers
        if (empty($user['encrypted_private_key'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ *{$recipientPhone}* is not on Sendra yet.\n\n" .
                    "Your account uses an external wallet, so vouchers are not supported.\n" .
                    "Ask them to sign up and send directly."
            );
            return ['success' => false, 'reason' => 'petra_no_voucher'];
        }

        if (!$this->db) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not create voucher. Please try again."
            );
            return ['success' => false];
        }

        // Generate secure unguessable token (256-bit)
        $token     = bin2hex(random_bytes(32));
        $voucherId = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + (48 * 3600)); // 48hr TTL

        try {
            $stmt = $this->db->prepare("
                INSERT INTO vouchers
                    (id, token, created_by, amount, currency, recipient_phone, expires_at, created_at)
                VALUES
                    (:id, :token, :created_by, :amount, :currency, :recipient_phone, :expires_at, NOW())
            ");
            $stmt->execute([
                'id'             => $voucherId,
                'token'          => $token,
                'created_by'     => $from,
                'amount'         => $amount,
                'currency'       => $currency,
                'recipient_phone' => $recipientPhone,
                'expires_at'     => $expiresAt,
            ]);
        } catch (\PDOException $e) {
            error_log("Voucher creation failed: " . $e->getMessage());
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not create voucher. Please try again."
            );
            return ['success' => false];
        }

        $voucherUrl  = "https://usesendra.com/client?voucher={$token}&phoneNumber={$recipientPhone}";
        $displayPhone = $recipientPhone;

        $this->whatsappService->sendMessage(
            $from,
            "📱 *{$displayPhone}* is not on Sendra yet.\n\n" .
                "✅ A voucher for *{$amount} {$currency}* has been created.\n\n" .
                "👇 *Forward this link to them:*\n" .
                "{$voucherUrl}\n\n" .
                "When they sign up on Sendra, the *{$amount} {$currency}* will be " .
                "automatically sent to their wallet.\n\n" .
                "⏳ _Expires in 48 hours._"
        );

        error_log("Voucher created: id=$voucherId phone=$recipientPhone amount=$amount $currency by=$from");
        return ['success' => true, 'voucher_id' => $voucherId];
    }

    /**
     * Execute a voucher claim — called from webhook.php after recipient signs up.
     * Transfers funds from voucher creator to the new user.
     */
    public function executeVoucherClaim($token, $recipientPhone, $username = null)
    {
        if (!$this->db) return ['success' => false, 'message' => 'DB unavailable'];

        // Fetch and validate voucher
        $stmt = $this->db->prepare("
            SELECT * FROM vouchers
            WHERE token = ? AND status = 'pending' AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $voucher = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$voucher) {
            return ['success' => false, 'message' => 'Voucher not found or expired'];
        }

        if ($voucher['recipient_phone'] !== $recipientPhone) {
            return ['success' => false, 'message' => 'Phone number does not match voucher'];
        }

        // Get sender's account
        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone_number = ? LIMIT 1");
        $stmt->execute([$voucher['created_by']]);
        $sender = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sender || empty($sender['encrypted_private_key'])) {
            return ['success' => false, 'message' => 'Sender account error'];
        }

        // Lock voucher immediately to prevent double-claim
        $this->db->prepare("
            UPDATE vouchers SET status = 'claimed', claimed_by = ?, claimed_at = NOW() WHERE id = ?
        ")->execute([$recipientPhone, $voucher['id']]);

        // ── Generate a new Sendra wallet for the recipient via aptos-service ──
        $existingUser = $this->userModel->findByPhone($recipientPhone);
        if ($existingUser) {
            // Already has an account — use their existing address
            $recipientAddress = $existingUser['aptos_address'];
            error_log("Voucher claim: $recipientPhone already registered, using existing address");
        } else {
            // Create a fresh Sendra keypair
            $ch = curl_init('http://127.0.0.1:3001/accounts/create');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['phone_number' => $recipientPhone]),
                CURLOPT_HTTPHEADER     => $this->aptosServiceAuthHeaders(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $aptosResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (!$aptosResponse || empty($aptosResponse['success'])) {
                // Revert voucher lock so they can retry
                $this->db->prepare("UPDATE vouchers SET status = 'pending', claimed_by = NULL, claimed_at = NULL WHERE id = ?")
                    ->execute([$voucher['id']]);
                error_log("Voucher claim: aptos-service failed for $recipientPhone: " . json_encode($aptosResponse));
                return ['success' => false, 'message' => 'Could not create wallet. Please try again.'];
            }

            $recipientAddress    = $aptosResponse['aptos_address'];
            $defaultUsername = $username ?? ('user_' . substr($recipientPhone, -4));

            $this->authController->signup(
                $recipientPhone,
                $defaultUsername,
                $recipientAddress,
                $aptosResponse['encrypted_private_key'],
                'sendra'
            );

            // Send private key to recipient via WhatsApp — they must save it
            $this->whatsappService->sendButtonMessage(
                $recipientPhone,
                "🔐 *Your Sendra wallet has been created!*\n\n" .
                    "📍 *Address:*\n`{$recipientAddress}`\n\n" .
                    "🔑 *Backup Key — save this now, shown only once:*\n" .
                    "`{$aptosResponse['private_key_for_display']}`\n\n" .
                    "⚠️ Store this somewhere safe. Tap below once saved.",
                ['key_saved' => "✅ I've saved it"]
            );

            // Put user in key confirmation state
            $this->userModel->update($recipientPhone, ['awaiting_key_confirm' => 1]);

            error_log("Voucher claim: created new Sendra account for $recipientPhone at $recipientAddress");
        }

        // Execute transfer via aptos-service
        $ch = curl_init('http://127.0.0.1:3001/transactions/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'sender_address'        => $sender['aptos_address'],
                'encrypted_private_key' => $sender['encrypted_private_key'],
                'recipient'             => $recipientAddress,
                'amount'               => $voucher['amount'],
                'currency'             => $voucher['currency'],
            ]),
            CURLOPT_HTTPHEADER     => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($result['success'])) {
            $hash = (string) $result['tx_hash'];
            $explorerUrl = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);

            // Save tx_hash on voucher
            $this->db->prepare("UPDATE vouchers SET tx_hash = ? WHERE id = ?")
                ->execute([$hash, $voucher['id']]);

            // Record in transaction history for both sides
            $this->recordTransaction([
                'user_phone'    => $voucher['created_by'],
                'direction'     => 'out',
                'amount'        => $voucher['amount'],
                'currency'      => $voucher['currency'],
                'status'        => 'confirmed',
                'aptos_address' => $sender['aptos_address'],
                'counterparty'  => $recipientAddress,
                'tx_hash'       => $hash,
                'explorer_url'  => $explorerUrl,
            ]);
            $this->recordTransaction([
                'user_phone'    => $recipientPhone,
                'direction'     => 'in',
                'amount'        => $voucher['amount'],
                'currency'      => $voucher['currency'],
                'status'        => 'confirmed',
                'aptos_address' => $recipientAddress,
                'counterparty'  => $sender['aptos_address'],
                'tx_hash'       => $hash,
                'explorer_url'  => $explorerUrl,
            ]);

            // Get names for personalised messages
            $senderName    = $sender['username'] ?? $voucher['created_by'];
            $recipientUser = $this->userModel->findByPhone($recipientPhone);
            $recipientName = $recipientUser['username'] ?? $recipientPhone;

            // Notify sender
            $this->whatsappService->sendMessage(
                $voucher['created_by'],
                "✅ *Voucher Claimed!*\n\n" .
                    "*{$recipientName}* signed up and received your " .
                    "*{$voucher['amount']} {$voucher['currency']}*.\n\n" .
                    "🔗 {$explorerUrl}"
            );

            // Notify recipient — mention sender by name
            $this->whatsappService->sendMessage(
                $recipientPhone,
                "🎉 *You received a voucher!*\n\n" .
                    "*{$senderName}* sent you *{$voucher['amount']} {$voucher['currency']}* " .
                    "and it has been credited to your wallet.\n\n" .
                    "💰 Type *balance* to check your wallet.\n" .
                    "📋 Type *history* to see your transactions."
            );

            error_log("Voucher claimed: {$voucher['id']} by $recipientPhone tx=$hash");
            return ['success' => true, 'tx_hash' => $hash];
        } else {
            // Revert voucher so they can try again
            $this->db->prepare("
                UPDATE vouchers SET status = 'pending', claimed_by = NULL, claimed_at = NULL WHERE id = ?
            ")->execute([$voucher['id']]);

            $error = $result['error'] ?? 'Transfer failed';
            error_log("Voucher transfer failed: {$voucher['id']} error=$error");
            return ['success' => false, 'message' => $error];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SELL (crypto → NGN bank payout)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return ?array<string, mixed> non-null = handled (consume message)
     */
    private function handleSellFlowMessage($from, $message, $messageLower, $user)
    {
        $userRow = $this->userModel->findByPhone($from) ?: $user;
        $ctx = $this->getSellChatContext($userRow);
        if ($ctx === null) {
            return null;
        }

        if ($messageLower === 'cancel') {
            $this->clearSellChatContext($from);
            $this->whatsappService->sendMessage($from, "Cancelled. Type *menu* for options.");

            return ['success' => true, 'action' => 'sell_cancelled'];
        }

        if ($messageLower === 'menu' || $messageLower === '/menu') {
            $this->clearSellChatContext($from);

            return $this->handleGreeting($from, $this->userModel->findByPhone($from) ?: $userRow);
        }

        $agg = new \App\Services\SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            $this->clearSellChatContext($from);
            $this->whatsappService->sendMessage($from, "❌ Sell is temporarily unavailable. Please try again later.");

            return ['success' => false, 'action' => 'sell_unconfigured'];
        }

        $healthBlock = $this->sellRampHealthGateIfDown($from);
        if ($healthBlock !== null) {
            return $healthBlock;
        }

        $step = $ctx['step'] ?? '';

        if ($step === 'await_ocr_payout_amount') {
            $amount = $this->sellParseNairaAmountOnly($message);
            if ($amount === null) {
                $this->whatsappService->sendMessage(
                    $from,
                    "Reply with how much to send in Naira, e.g. *10000* or *10000 naira*.\n*cancel* stops."
                );

                return ['success' => false, 'action' => 'ocr_amount_reprompt'];
            }

            return $this->sellOcrProceedToAssetPick($from, $userRow, $ctx, $amount, $agg);
        }

        if ($step === 'await_send_naira_asset') {
            $asset = null;
            if ($messageLower === 'sell_pick_usdt' || $messageLower === 'usdt') {
                $asset = 'USDT';
            } elseif ($messageLower === 'sell_pick_usdc' || $messageLower === 'usdc') {
                $asset = 'USDC';
            }
            if ($asset !== null) {
                // Avoid duplicate webhook / button taps double-processing.
                $ctx['step'] = 'await_send_naira_processing';
                $ctx['asset'] = $asset;
                $this->setSellChatContext($from, $ctx);

                $this->whatsappService->sendMessage($from, '⏳ Getting the best rate…');

                return $this->sellSendNairaAfterAssetPick($from, $userRow, $ctx, $asset, $agg);
            }
            $this->whatsappService->sendMessage($from, "Tap *USDT* or *USDC*, or type *USDT* / *USDC*.\n*cancel* stops.");

            return ['success' => false, 'action' => 'sell_send_naira_asset_reprompt'];
        }

        if ($step === 'await_send_naira_processing') {
            // Duplicate WhatsApp webhooks while the first request is still opening the Centiiv order.
            return ['success' => false, 'action' => 'sell_send_naira_processing'];
        }

        if ($step === 'await_amount') {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(usdc|usdt)\s*$/i', trim($message), $m)) {
                return $this->sellApplyQuoteAndPrompt(
                    $from,
                    $this->userModel->findByPhone($from) ?: $userRow,
                    (string) $m[1],
                    strtoupper($m[2]),
                    (string) ($ctx['fiat'] ?? 'NGN'),
                    $agg
                );
            }
            $this->whatsappService->sendMessage(
                $from,
                "Reply with amount and token, e.g. *5 USDC* or *10 USDT*.\n" .
                "Or cash out Naira with:\n`send 5000 naira to 0123456789 GTBank`\n\nType *cancel* to stop."
            );

            return ['success' => false, 'action' => 'sell_prompt_amount'];
        }

        if ($step === 'await_quote_confirm') {
            if ($this->sellMessageMeansYes($message, $messageLower, 'sell_quote_yes')) {
                $ctx['step'] = 'await_bank';
                $this->setSellChatContext($from, $ctx);
                $this->whatsappService->sendMessage(
                    $from,
                    "🏦 *Bank payout*\nReply in this format:\n`0123456789 BankName`\nExample: `0123456789 GTBank`\n\nType *cancel* to stop."
                );

                return ['success' => true, 'action' => 'sell_bank_prompt'];
            }
            if ($messageLower === 'sell_quote_change') {
                $fiat = (string) ($ctx['fiat'] ?? 'NGN');
                $this->setSellChatContext($from, ['flow' => 'sell', 'step' => 'await_amount', 'fiat' => $fiat]);
                $this->whatsappService->sendMessage($from, "Okay. How much do you want to sell? (e.g. *20 USDC*)");

                return ['success' => true, 'action' => 'sell_change_amount'];
            }
            $this->whatsappService->sendMessage(
                $from,
                "Reply *yes* to continue or tap *Different amount*.\nType *cancel* to stop."
            );

            return ['success' => false, 'action' => 'sell_quote_reprompt'];
        }

        if ($step === 'await_bank') {
            if (preg_match('/^(\d{10})\s+(.+)$/u', trim($message), $m)) {
                return $this->sellResolveBankAndConfirmPayee($from, $userRow, $ctx, $m[1], trim($m[2]), $agg, false);
            }
            $this->whatsappService->sendMessage(
                $from,
                "Use: `0123456789 BankName` (10-digit NGN account).\nType *cancel* to stop."
            );

            return ['success' => false, 'action' => 'sell_bank_format'];
        }

        // (no await_payee_confirm: we go straight to PIN after bank verification)

        $this->clearSellChatContext($from);

        return null;
    }

    private function handleSellEntry($from, $message, $messageLower, $user)
    {
        if (!$this->sellPrerequisitesOk($from, $user)) {
            return ['success' => false, 'action' => 'sell_blocked'];
        }

        $agg = new \App\Services\SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Cash-out isn’t available right now. Try again later or email support@usesendra.com"
            );

            return ['success' => false, 'action' => 'sell_unconfigured'];
        }

        $healthBlock = $this->sellRampHealthGateIfDown($from);
        if ($healthBlock !== null) {
            return $healthBlock;
        }

        $trimMsg = trim($message);
        $sendNaira = $this->sellParseSendNairaIntent($trimMsg);
        if ($sendNaira !== null) {
            return $this->sellSendNairaStart($from, $user, $sendNaira, $agg);
        }

        // Crypto P2P send (`send 50 USDT to …`) — not Naira bank cash-out
        if (preg_match('/^send\s+/i', $trimMsg)) {
            if (preg_match('/\b(naira|ngn)\b/i', $trimMsg) && $this->sellParseSendNairaIntent($trimMsg) === null) {
                $this->whatsappService->sendMessage(
                    $from,
                    "Bank cash-out needs at least *₦100* in this form:\n`send 500 naira to 0123456789 GTBank`\n\n" .
                    'For wallet sends use: `send 10 USDT to 0x…`'
                );

                return ['success' => false, 'action' => 'send_naira_format_or_min'];
            }

            return $this->handleSendInitiation($from, $trimMsg, $user);
        }

        if ($messageLower === 'sell_fiat_ghs') {
            $this->clearSellChatContext($from);
            $this->whatsappService->sendMessage(
                $from,
                "🇬🇭 *Ghana (GHS)* is coming soon.\n\nType *sell* to cash out in *Nigerian Naira*."
            );

            return ['success' => true, 'action' => 'sell_ghs_soon'];
        }

        if ($messageLower === 'sell_fiat_ngn') {
            $this->setSellChatContext($from, ['flow' => 'sell', 'step' => 'await_amount', 'fiat' => 'NGN']);
            $this->whatsappService->sendMessage(
                $from,
                "How much crypto do you want to cash out?\nExample: *5 USDC* or *10 USDT*\n\n" .
                "Or send Naira directly:\n`send 5000 naira to 0123456789 GTBank`\n\n*cancel* stops."
            );

            return ['success' => true, 'action' => 'sell_ngn_amount'];
        }

        if ($messageLower === 'sell') {
            $this->setSellChatContext($from, ['flow' => 'sell', 'step' => 'await_amount', 'fiat' => 'NGN']);
            $this->whatsappService->sendMessage(
                $from,
                "Cash out to your Nigerian bank.\n\n" .
                "• Crypto amount: *5 USDC* or *10 USDT*\n" .
                "• Naira payout: *send 5000 naira to 0123456789 GTBank*\n\n" .
                "*cancel* stops."
            );

            return ['success' => true, 'action' => 'sell_ngn_amount'];
        }

        if (preg_match('/^sell\s+(\d+(?:\.\d+)?)\s*(usdc|usdt)\b/i', trim($message), $m)) {
            $this->clearSellChatContext($from);

            return $this->sellApplyQuoteAndPrompt(
                $from,
                $user,
                (string) $m[1],
                strtoupper($m[2]),
                'NGN',
                $agg
            );
        }

        $this->whatsappService->sendMessage(
            $from,
            "Try:\n• *sell 5 USDC* (cash out crypto)\n• *send 5000 naira to 0123456789 GTBank* (Naira payout)"
        );

        return ['success' => false, 'action' => 'sell_usage'];
    }

    private function sellPrerequisitesOk($from, $user): bool
    {
        if (empty($user['aptos_address'])) {
            $this->whatsappService->sendMessage($from, "❌ No wallet on file. Type *hi* to get started.");

            return false;
        }
        if (empty($user['pin_hash'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Set a *6-digit PIN* first. Type *hi* and complete setup."
            );
            $this->userModel->update($from, ['awaiting_pin_setup' => 1]);

            return false;
        }
        if ((($user['account_type'] ?? '') === 'petra') || empty($user['encrypted_private_key'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ *Sell from WhatsApp* needs a Sendra-native wallet (we sign the transfer here).\n\n" .
                "Type *hi* → create a new Sendra account, or use the web client for Petra-linked flows."
            );

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSellChatContext($userRow): ?array
    {
        $raw = $userRow['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || ($raw['flow'] ?? '') !== 'sell') {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function setSellChatContext($from, array $ctx): void
    {
        $this->userModel->update($from, ['chat_context' => json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
    }

    private function clearSellChatContext($from): void
    {
        $this->userModel->update($from, ['chat_context' => null]);
    }

    /**
     * WhatsApp users often paste the whole card or prefix with emoji; normalize affirmative replies.
     *
     * @param 'sell_quote_yes'|'sell_payee_yes' $buttonId
     */
    private function sellMessageMeansYes(string $message, string $messageLower, string $buttonId): bool
    {
        if ($messageLower === $buttonId || $messageLower === 'yes' || $messageLower === 'y') {
            return true;
        }
        $stripped = preg_replace('/^[\s\p{So}\p{Sk}]+/u', '', $messageLower);
        $stripped = trim((string) $stripped);
        if ($stripped === 'yes' || $stripped === 'y') {
            return true;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $message)), static fn ($ln) => $ln !== ''));
        if ($lines !== []) {
            $last = strtolower((string) end($lines));
            $last = preg_replace('/^[\s\p{So}\p{Sk}]+/u', '', $last);
            $last = trim((string) $last);
            if ($last === 'yes' || $last === 'y' || $last === strtolower($buttonId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * e.g. `send 20000 naira to 0227912723 gtbank` / `pay 15000 ngn to 0123456789 Access`
     *
     * @return array{amount_ngn: float, account: string, bank_guess: string}|null
     */
    /**
     * @return ?array<string, mixed>
     */
    private function parseImagePayload(string $message): ?array
    {
        $data = json_decode($message, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['image']['id'])) {
            return null;
        }

        return [
            'id' => (string) $data['image']['id'],
            'caption' => isset($data['caption']) && is_string($data['caption']) ? $data['caption'] : null,
        ];
    }

    /**
     * Idle-state image: OCR account + bank, Centiiv verify, then NGN amount (or skip if caption has amount).
     *
     * @return ?array<string, mixed>
     */
    private function handleIdleBankAccountImage(string $from, string $message, array $user): ?array
    {
        if (!$this->sellPrerequisitesOk($from, $user)) {
            return ['success' => false, 'action' => 'ocr_blocked'];
        }

        $payload = $this->parseImagePayload($message);
        if ($payload === null) {
            return null;
        }

        $agg = new \App\Services\SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            $this->whatsappService->sendMessage($from, "❌ Cash-out isn’t available right now. Try again later.");

            return ['success' => false, 'action' => 'ocr_unconfigured'];
        }

        $healthBlock = $this->sellRampHealthGateIfDown($from);
        if ($healthBlock !== null) {
            return $healthBlock;
        }

        $ocr = new \App\Services\BankAccountOcrService();
        if (!$ocr->isEnabled()) {
            $this->whatsappService->sendMessage(
                $from,
                "📷 Photo reading needs a Google Gemini API key (SENDRA_BANK_OCR_GEMINI_API_KEY).\n\n" .
                "Get one at aistudio.google.com/apikey, or use:\n`send 10000 naira to 0123456789 GTBank`"
            );

            return ['success' => false, 'action' => 'ocr_disabled'];
        }

        $this->whatsappService->sendMessage($from, '📷 Reading account details from your image…');

        $tmp = $this->whatsappService->downloadMediaToFile($payload['id']);
        if ($tmp === null) {
            $this->whatsappService->sendMessage(
                $from,
                "📷 Couldn't load that image. Please try again with a clear screenshot of the account number and bank name."
            );

            return ['success' => false, 'action' => 'ocr_download_failed'];
        }

        try {
            $extracted = $ocr->extractFromImageFile($tmp, $payload['caption']);
        } finally {
            @unlink($tmp);
        }

        if ($extracted === null) {
            $ocrErr = $ocr->getLastError();
            if ($ocrErr === 'api_quota') {
                $this->whatsappService->sendMessage(
                    $from,
                    "📷 Photo reading is unavailable — the Gemini API free quota is used up.\n\n" .
                    "Check usage at aistudio.google.com, or cash out with:\n`send 10000 naira to 0227912723 GTBank`"
                );

                return ['success' => false, 'action' => 'ocr_api_quota'];
            }
            if (in_array($ocrErr, ['api_key', 'api_error'], true)) {
                error_log('Sendra bank OCR: OpenAI failed (' . ($ocrErr ?? '') . ') for ' . $from);
                $this->whatsappService->sendMessage(
                    $from,
                    "📷 Photo reading is temporarily unavailable (server OCR config).\n\n" .
                    "You can still cash out with:\n`send 10000 naira to 0123456789 GTBank`"
                );

                return ['success' => false, 'action' => 'ocr_api_failed'];
            }

            $this->whatsappService->sendMessage(
                $from,
                "📷 I couldn't find a valid *10-digit account number* and *bank name* in that image.\n\n" .
                "Use a clear photo of the account number and bank (app screenshot works best).\n" .
                "Or send:\n`send 10000 naira to 0123456789 GTBank`"
            );

            return ['success' => false, 'action' => 'ocr_parse_failed'];
        }

        $account = $extracted['account_number'];
        $bankGuess = $extracted['bank_name'];

        $ctx = [
            'flow' => 'sell',
            'fiat' => 'NGN',
            'ocr_source' => true,
        ];

        if (!$this->sellVerifyAndPopulateBankContext($from, $ctx, $account, $bankGuess, $agg, true)) {
            return ['success' => false, 'action' => 'ocr_verify_failed'];
        }

        $captionAmount = $this->sellParseCaptionNairaAmount($payload['caption'] ?? null);
        if ($captionAmount !== null) {
            return $this->sellOcrProceedToAssetPick($from, $user, $ctx, $captionAmount, $agg);
        }

        $ctx['step'] = 'await_ocr_payout_amount';
        $this->setSellChatContext($from, $ctx);

        $name = (string) ($ctx['account_name'] ?? '');
        $bank = (string) ($ctx['bank_name'] ?? $bankGuess);
        $this->whatsappService->sendMessage(
            $from,
            "✅ *Account found*\n\n" .
            "• *{$name}*\n" .
            "• `{$account}` — *{$bank}*\n\n" .
            "How much Naira do you want to send?\n(e.g. *10000* or *10000 naira*)\n\n*cancel* stops."
        );

        return ['success' => true, 'action' => 'ocr_await_amount'];
    }

    private function sellParseNairaAmountOnly(string $message): ?float
    {
        $t = trim($message);
        if (!preg_match('/^₦?\s*([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            return null;
        }
        $raw = str_replace([',', ' '], '', (string) $m[1]);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $amt = (float) $raw;

        return $amt >= 100 ? $amt : null;
    }

    /**
     * NGN amount from an image caption (e.g. "10000", "send 10000 naira", full send-naira line).
     */
    private function sellParseCaptionNairaAmount(?string $caption): ?float
    {
        if ($caption === null || trim($caption) === '') {
            return null;
        }

        $t = trim($caption);
        $amount = $this->sellParseNairaAmountOnly($t);
        if ($amount !== null) {
            return $amount;
        }

        if (preg_match('/^(?:send|pay)\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    return $amt;
                }
            }
        }

        $intent = $this->sellParseSendNairaIntent($t);
        if ($intent !== null) {
            return $intent['amount_ngn'];
        }

        return null;
    }

    /**
     * After OCR + amount: pick USDT/USDC, then existing send-naira sizing + PIN flow.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function sellOcrProceedToAssetPick(string $from, array $userRow, array $ctx, float $amountNgn, \App\Services\SendraCentiivRampService $agg): array
    {
        $ctx['target_receive_ngn'] = $amountNgn;
        $ctx['step'] = 'await_send_naira_asset';
        $this->setSellChatContext($from, $ctx);

        $n = number_format($amountNgn, 0, '.', ',');
        $acct = (string) ($ctx['account_number'] ?? '');
        $bank = (string) ($ctx['bank_name'] ?? '');
        $name = (string) ($ctx['account_name'] ?? '');

        $this->whatsappService->sendButtonMessage(
            $from,
            "Send *≈ ₦{$n}* to *{$name}*\n`{$acct}` — *{$bank}*\n\nWhich balance should we use?",
            [
                'sell_pick_usdt' => 'USDT',
                'sell_pick_usdc' => 'USDC',
            ]
        );

        return ['success' => true, 'action' => 'ocr_pick_asset'];
    }

    private function sellParseSendNairaIntent(string $message): ?array
    {
        if (!preg_match(
            '/^(?:send|pay)\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)\s+(?:to\s+)?(\d{10})\s+(.+)$/iu',
            $message,
            $m
        )) {
            return null;
        }
        $rawAmt = str_replace([',', ' '], '', (string) $m[1]);
        if ($rawAmt === '' || !is_numeric($rawAmt)) {
            return null;
        }
        $amt = (float) $rawAmt;
        if ($amt < 100) {
            return null;
        }

        return [
            'amount_ngn' => $amt,
            'account' => (string) $m[2],
            'bank_guess' => trim((string) $m[3]),
        ];
    }

    /**
     * @param array{amount_ngn: float, account: string, bank_guess: string} $parsed
     * @return array<string, mixed>
     */
    private function sellSendNairaStart($from, $user, array $parsed, \App\Services\SendraCentiivRampService $agg)
    {
        $this->setSellChatContext($from, [
            'flow' => 'sell',
            'step' => 'await_send_naira_asset',
            'fiat' => 'NGN',
            'target_receive_ngn' => $parsed['amount_ngn'],
            'preset_account' => $parsed['account'],
            'preset_bank_guess' => $parsed['bank_guess'],
        ]);
        $n = number_format($parsed['amount_ngn'], 0, '.', ',');
        $this->whatsappService->sendButtonMessage(
            $from,
            "Send *≈ ₦{$n}* to `{$parsed['account']}` (*{$parsed['bank_guess']}*).\n\nWhich balance should we use?",
            [
                'sell_pick_usdt' => 'USDT',
                'sell_pick_usdc' => 'USDC',
            ]
        );

        return ['success' => true, 'action' => 'sell_send_naira_pick'];
    }

    /**
     * Latest NGN receive for a crypto sale amount (USDC direct; USDT via bridge estimate when configured).
     */
    private function sellNgnReceiveForCryptoAmount(
        string $amountStr,
        string $asset,
        array $user,
        \App\Services\SendraCentiivRampService $agg,
        ?int $oneClickQuoteWaitMs = null
    ): ?float
    {
        $wa = (string) ($user['phone_number'] ?? $user['phone'] ?? '');
        if ($asset === 'USDC') {
            $q = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => (float) $amountStr,
            ]);
            if (!$q['success']) {
                @file_put_contents(
                    '/tmp/sendra_ramp_debug.log',
                    '[' . gmdate('c') . "] sellNgnReceiveForCryptoAmount USDC quote failed wa={$wa} http={$q['http_code']} err={$q['error']} data=" . json_encode($q['data']) . "\n",
                    FILE_APPEND
                );
                return null;
            }
            $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], (float) $amountStr);

            return $s['receive_ngn'];
        }
        if ($asset !== 'USDT' || !$this->oneclickCentiivRampConfigured()) {
            return null;
        }
        if ($this->panoraUsdtOfframpEnabled()) {
            $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
            if ($refundAptos === '') {
                return null;
            }
            $panora = new \App\Services\SendraPanoraService();
            $pq = $panora->requestSwapQuote($amountStr, $refundAptos);
            if (!$pq['success']) {
                return null;
            }
            $parsed = \App\Services\SendraPanoraService::parseSwapResponse($pq['data']);
            $estUsdc = \App\Services\SendraPanoraService::centiivUsdcAmountFromQuote($parsed);
            if ($estUsdc < 0.000001) {
                return null;
            }
            $q = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => $estUsdc,
            ]);
            if (!$q['success']) {
                return null;
            }
            $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], $estUsdc);

            return $s['receive_ngn'];
        }
        $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        if ($refundAptos === '' || $stellarDeposit === '') {
            return null;
        }
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
        $oneClick = new \App\Services\SendraOneClickService();
        $dryBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdt,
            $destStellarUsdc,
            $amountSmallest,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            true,
            $oneClickQuoteWaitMs
        );
        $dryRes = $oneClick->requestQuote($dryBody);
        if (!$dryRes['success']) {
            @file_put_contents(
                '/tmp/sendra_ramp_debug.log',
                '[' . gmdate('c') . "] sellNgnReceiveForCryptoAmount USDT 1click quote failed wa={$wa} http={$dryRes['http_code']} err={$dryRes['error']} data=" . json_encode($dryRes['data']) . " raw=" . substr((string) ($dryRes['raw'] ?? ''), 0, 2000) . "\n",
                FILE_APPEND
            );
            return null;
        }
        $parsedDry = \App\Services\SendraOneClickService::parseQuoteResponse($dryRes['data']);
        $estUsdc = (float) ($parsedDry['amount_out_formatted'] ?? 0);
        if ($estUsdc < 0.000001) {
            @file_put_contents(
                '/tmp/sendra_ramp_debug.log',
                '[' . gmdate('c') . "] sellNgnReceiveForCryptoAmount USDT 1click parsed estUsdc too small wa={$wa} parsed=" . json_encode($parsedDry) . " data=" . json_encode($dryRes['data']) . "\n",
                FILE_APPEND
            );
            return null;
        }
        $q = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $estUsdc,
        ]);
        if (!$q['success']) {
            @file_put_contents(
                '/tmp/sendra_ramp_debug.log',
                '[' . gmdate('c') . "] sellNgnReceiveForCryptoAmount USDT centiiv quote failed wa={$wa} http={$q['http_code']} err={$q['error']} estUsdc={$estUsdc} data=" . json_encode($q['data']) . "\n",
                FILE_APPEND
            );
            return null;
        }
        $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], $estUsdc);

        return $s['receive_ngn'];
    }

    /**
     * USDT amount → NGN via 1Click dry + Centiiv USDC quote (used in send-naira sizing).
     *
     * @return array{recv: float, sum: array<string, mixed>, est_usdc: float, dry_data: array<string, mixed>}|null
     */
    private function sellUsdtBridgePricingStep(
        string $usdtAmountStr,
        string $originUsdt,
        string $destStellarUsdc,
        string $stellarDeposit,
        string $refundAptos,
        string $deadlineIso,
        \App\Services\SendraOneClickService $oneClick,
        \App\Services\SendraCentiivRampService $agg,
        int $oneClickQuoteWaitMs
    ): ?array {
        $midSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($usdtAmountStr, 6);
        $dryBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdt,
            $destStellarUsdc,
            $midSmallest,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            true,
            $oneClickQuoteWaitMs
        );
        $dry = $oneClick->requestQuote($dryBody);
        if (!$dry['success'] || !is_array($dry['data'] ?? null)) {
            return null;
        }
        $parsed = \App\Services\SendraOneClickService::parseQuoteResponse($dry['data']);
        $estUsdc = (float) ($parsed['amount_out_formatted'] ?? 0);
        if ($estUsdc <= 0) {
            return null;
        }
        $sum = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $estUsdc,
        ]);
        if (!$sum['success']) {
            return ['recv' => 0.0, 'sum' => [], 'est_usdc' => $estUsdc, 'dry_data' => $dry['data'], 'centiiv_failed' => true, 'centiiv_error' => $sum['error'] ?? null];
        }
        $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($sum['data'], $estUsdc);
        $recv = (float) ($s['receive_ngn'] ?? 0);
        if ($recv <= 0) {
            return null;
        }

        return [
            'recv' => $recv,
            'sum' => $s,
            'est_usdc' => $estUsdc,
            'dry_data' => $dry['data'],
        ];
    }

    /**
     * USDT → NGN via Panora USDT→USDC (same wallet) + Centiiv USDC quote (no 1Click during sizing).
     *
     * @return array{recv: float, sum: array<string, mixed>, est_usdc: float, panora_data: array<string, mixed>}|null
     */
    private function sellPanoraUsdtPricingStep(
        string $usdtAmountStr,
        string $aptosWallet0x,
        \App\Services\SendraPanoraService $panora,
        \App\Services\SendraCentiivRampService $agg
    ): ?array {
        $pq = $panora->requestSwapQuote($usdtAmountStr, $aptosWallet0x);
        if (!$pq['success'] || !is_array($pq['data'] ?? null)) {
            return null;
        }
        $parsed = \App\Services\SendraPanoraService::parseSwapResponse($pq['data']);
        $estUsdc = \App\Services\SendraPanoraService::centiivUsdcAmountFromQuote($parsed);
        if ($estUsdc <= 0) {
            return null;
        }
        $sum = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $estUsdc,
        ]);
        if (!$sum['success']) {
            return [
                'recv' => 0.0,
                'sum' => [],
                'est_usdc' => $estUsdc,
                'panora_data' => $pq['data'],
                'centiiv_failed' => true,
                'centiiv_error' => $sum['error'] ?? null,
            ];
        }
        $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($sum['data'], $estUsdc);
        $recv = (float) ($s['receive_ngn'] ?? 0);
        if ($recv <= 0) {
            return null;
        }

        return [
            'recv' => $recv,
            'sum' => $s,
            'est_usdc' => $estUsdc,
            'panora_data' => $pq['data'],
        ];
    }

    /**
     * Full NGN quote summary for a ledger crypto sale (USDC direct; USDT via bridge estimate when configured).
     *
     * @return array{
     *   receive_ngn: ?float,
     *   receive_ngn_display: string,
     *   rate_hint: ?string,
     *   fee_hint: ?string,
     *   centiiv_error?: ?string,
     *   centiiv_http_code?: ?int,
     *   centiiv_data?: ?array<string, mixed>,
     *   centiiv_min_usdc?: ?float
     * }|null
     */
    private function sellSummarizeNgnForCryptoSellAmount(string $amountStr, string $asset, array $user, \App\Services\SendraCentiivRampService $agg): ?array
    {
        if ($asset === 'USDC') {
            $q = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => (float) $amountStr,
            ]);
            if (!$q['success']) {
                return [
                    'receive_ngn' => null,
                    'receive_ngn_display' => '',
                    'rate_hint' => null,
                    'fee_hint' => null,
                    'centiiv_error' => $q['error'] ?? null,
                    'centiiv_http_code' => $q['http_code'] ?? null,
                    'centiiv_data' => is_array($q['data'] ?? null) ? $q['data'] : null,
                    'centiiv_min_usdc' => $this->parseCentiivQuoteMinUsdc($q['error'] ?? null),
                ];
            }

            return \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], (float) $amountStr);
        }
        if ($asset !== 'USDT' || !$this->oneclickCentiivRampConfigured()) {
            return null;
        }
        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            return null;
        }
        if ($this->panoraUsdtOfframpEnabled()) {
            $step = $this->sellPanoraUsdtPricingStep($amountStr, $refundAptos, new \App\Services\SendraPanoraService(), $agg);
            if ($step === null) {
                return null;
            }
            if (!empty($step['centiiv_failed'])) {
                return [
                    'receive_ngn' => null,
                    'receive_ngn_display' => '',
                    'rate_hint' => null,
                    'fee_hint' => null,
                    'centiiv_error' => $step['centiiv_error'] ?? null,
                    'centiiv_http_code' => null,
                    'centiiv_data' => null,
                    'centiiv_min_usdc' => $this->parseCentiivQuoteMinUsdc(isset($step['centiiv_error']) && is_string($step['centiiv_error']) ? $step['centiiv_error'] : null),
                ];
            }

            return $step['sum'];
        }
        $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        if ($stellarDeposit === '') {
            return null;
        }
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
        $oneClick = new \App\Services\SendraOneClickService();
        $dryBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdt,
            $destStellarUsdc,
            $amountSmallest,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            true
        );
        $dryRes = $oneClick->requestQuote($dryBody);
        if (!$dryRes['success']) {
            return null;
        }
        $parsedDry = \App\Services\SendraOneClickService::parseQuoteResponse($dryRes['data']);
        $estUsdc = (float) ($parsedDry['amount_out_formatted'] ?? 0);
        if ($estUsdc < 0.000001) {
            return null;
        }
        $q = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $estUsdc,
        ]);
        if (!$q['success']) {
            return [
                'receive_ngn' => null,
                'receive_ngn_display' => '',
                'rate_hint' => null,
                'fee_hint' => null,
                'centiiv_error' => $q['error'] ?? null,
                'centiiv_http_code' => $q['http_code'] ?? null,
                'centiiv_data' => is_array($q['data'] ?? null) ? $q['data'] : null,
                'centiiv_min_usdc' => $this->parseCentiivQuoteMinUsdc($q['error'] ?? null),
            ];
        }

        return \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], $estUsdc);
    }

    private function formatCentiivConversionOrderError(string $error): string
    {
        if (stripos($error, 'timed out') !== false) {
            return 'Our bank payout partner did not respond in time. Nothing was debited from your wallet — please try again in a few minutes.';
        }
        if (stripos($error, 'property reference should not exist') !== false) {
            return 'Payout setup was rejected by our bank partner. Please try again — if it persists, contact support@usesendra.com';
        }

        return $error;
    }

    private function parseCentiivQuoteMinUsdc(?string $error): ?float
    {
        if ($error === null || $error === '') {
            return null;
        }
        if (!preg_match('/minimum transaction amount of\s*([0-9]+(?:\.[0-9]+)?)/i', $error, $m)) {
            return null;
        }
        $v = (float) $m[1];
        if ($v <= 0) {
            return null;
        }

        return $v;
    }

    private function buildCentiivMinPayoutMessage(\App\Services\SendraCentiivRampService $agg, float $minUsdc): string
    {
        // Try to convert Centiiv’s min USDC to an NGN minimum for UX.
        $q = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $minUsdc,
        ]);
        if ($q['success']) {
            $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($q['data'], $minUsdc);
            $minNgn = (float) ($s['receive_ngn'] ?? 0);
            if ($minNgn > 0) {
                return
                    "❌ Amount too small for bank payout right now.\n" .
                    'Minimum is *≈ ₦' . number_format($minNgn, 0, '.', ',') . "*.\n" .
                    'Try a higher amount (e.g. *₦' . number_format(max($minNgn, 1000), 0, '.', ',') . '* or more).';
            }
        }

        return
            "❌ Amount too small for bank payout right now.\n" .
            'Minimum is *' . rtrim(rtrim(number_format($minUsdc, 6, '.', ''), '0'), '.') . "* USDC on this rail.\n" .
            'Try a higher amount.';
    }

    /**
     * Accuracy-first sizing for a target NGN payout, but optimized to avoid many re-quotes.
     *
     * @return array{
     *   crypto_amount: string,
     *   receive_ngn_display: string,
     *   rate_hint?: ?string,
     *   fee_hint?: ?string,
     *   oneclick_quote_data?: ?array<string, mixed>,
     *   oneclick_est_usdc?: ?float
     * }|null
     */
    private function sellSolveCryptoAmountForTargetNgn($from, array $user, string $asset, float $targetNgn, \App\Services\SendraCentiivRampService $agg): ?array
    {
        $balances = $this->fetchAptosBalance($user['aptos_address']);
        if ($balances === null) {
            $this->whatsappService->sendMessage($from, '❌ Could not read your balance. Try again.');

            return null;
        }
        $avail = (float) str_replace(',', '', $balances[$asset] ?? '0');
        if ($avail < 0.01) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ No *{$asset}* balance here. Pick the other token or type *cancel*."
            );

            return null;
        }
        $min = 0.01;

        $toleranceNgn = (float) ($_ENV['SENDRA_SELL_TARGET_NGN_TOLERANCE'] ?? getenv('SENDRA_SELL_TARGET_NGN_TOLERANCE') ?: 1);
        if ($toleranceNgn < 1) {
            $toleranceNgn = 1;
        }
        $maxIters = (int) ($_ENV['SENDRA_SELL_TARGET_NGN_MAX_ITERS'] ?? getenv('SENDRA_SELL_TARGET_NGN_MAX_ITERS') ?: 8);
        if ($maxIters < 4) {
            $maxIters = 4;
        }
        if ($maxIters > 14) {
            $maxIters = 14;
        }

        // USDC: fast rate solve (1–2 Centiiv quotes) or legacy binary search.
        if ($asset === 'USDC') {
            if ($this->sellTargetNgnFastQuoteEnabled()) {
                return $this->sellSolveUsdcFastForTargetNgn($from, $avail, $targetNgn, $toleranceNgn, $agg);
            }
            $hi = $avail;
            $lo = $min;
            $bestAmt = null;
            $bestSum = null;
            $bestAboveAmt = null;
            $bestAboveSum = null;

            // quick infeasibility check
            $hiSum = $this->sellSummarizeNgnForCryptoSellAmount((string) $hi, 'USDC', $user, $agg);
            if ($hiSum === null || empty($hiSum['receive_ngn'])) {
                $this->whatsappService->sendMessage($from, '❌ Could not get a rate right now. Try again.');

                return null;
            }
            if ((float) $hiSum['receive_ngn'] + 1e-6 < $targetNgn) {
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Your balance isn’t enough for *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                    'You have *' . ($balances[$asset] ?? '0') . "* {$asset}. Try a smaller amount."
                );

                return null;
            }

            for ($i = 0; $i < $maxIters; $i++) {
                $mid = ($lo + $hi) / 2.0;
                $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
                if ($midStr === '' || (float) $midStr < $min) {
                    $midStr = '0.01';
                }
                $sum = $this->sellSummarizeNgnForCryptoSellAmount($midStr, 'USDC', $user, $agg);
                if ($sum === null || empty($sum['receive_ngn'])) {
                    $minUsdc = (float) ($sum['centiiv_min_usdc'] ?? 0);
                    if ($minUsdc > 0) {
                        // Too small for the rail; increase the amount and keep searching instead of bailing.
                        $lo = max($lo, (float) $midStr);
                        continue;
                    }
                    $this->whatsappService->sendMessage($from, '❌ Could not refresh the rate. Try again.');

                    return null;
                }
                $recv = (float) $sum['receive_ngn'];
                $diff = $recv - $targetNgn;
                if ($bestSum === null || abs($diff) < abs(((float) $bestSum['receive_ngn']) - $targetNgn)) {
                    $bestAmt = $midStr;
                    $bestSum = $sum;
                }
                // We must not underpay: prefer the smallest quote that is >= target.
                if ($recv + 1e-9 >= $targetNgn) {
                    if ($bestAboveSum === null || (float) $recv < (float) $bestAboveSum['receive_ngn']) {
                        $bestAboveAmt = $midStr;
                        $bestAboveSum = $sum;
                    }
                    if (abs($diff) <= $toleranceNgn) {
                        $bestAboveAmt = $midStr;
                        $bestAboveSum = $sum;
                        break;
                    }
                }
                if ($recv >= $targetNgn) {
                    $hi = $mid;
                } else {
                    $lo = $mid;
                }
            }

            // Enforce >= target
            if ($bestAboveAmt !== null && $bestAboveSum !== null) {
                $bestAmt = $bestAboveAmt;
                $bestSum = $bestAboveSum;
            }

            if ($bestAmt === null || $bestSum === null || empty($bestSum['receive_ngn']) || (float) $bestSum['receive_ngn'] + 1e-9 < $targetNgn) {
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Could not find a *USDC* amount that pays *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* right now.\nTry again or pick *USDT*."
                );

                return null;
            }

            return [
                'crypto_amount' => $bestAmt,
                'receive_ngn_display' => $bestSum['receive_ngn_display'],
                'rate_hint' => $bestSum['rate_hint'],
                'fee_hint' => $bestSum['fee_hint'],
            ];
        }

        // USDT: binary search — Panora USDT→USDC + Centiiv, or legacy 1Click dry + Centiiv.
        if ($asset !== 'USDT' || !$this->oneclickCentiivRampConfigured()) {
            $this->whatsappService->sendMessage($from, '❌ USDT isn’t available right now. Try *USDC*.');

            return null;
        }

        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            $this->whatsappService->sendMessage($from, '❌ Could not start this payout. Try again.');

            return null;
        }

        $usePanoraUsdt = $this->panoraUsdtOfframpEnabled();
        if ($usePanoraUsdt && $this->sellTargetNgnFastQuoteEnabled()) {
            return $this->sellSolveUsdtPanoraFastForTargetNgn(
                $from,
                $avail,
                $targetNgn,
                $toleranceNgn,
                $refundAptos,
                $agg,
                new \App\Services\SendraPanoraService()
            );
        }
        $stellarDeposit = '';
        $originUsdt = '';
        $destStellarUsdc = '';
        $deadlineIso = '';
        $oneClick = null;
        $searchWait = 0;
        $lockWait = 0;
        $panora = null;
        if (!$usePanoraUsdt) {
            $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
            $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
            $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
            if ($stellarDeposit === '') {
                $this->whatsappService->sendMessage($from, '❌ Could not start this payout. Try again.');

                return null;
            }
            $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
            $oneClick = new \App\Services\SendraOneClickService();
            $searchWait = \App\Services\SendraOneClickService::resolveSearchQuoteWaitMs();
            $lockWait = \App\Services\SendraOneClickService::resolveConfiguredQuoteWaitMs();
        } else {
            $panora = new \App\Services\SendraPanoraService();
        }

        $lo = $min;
        $hi = $avail;
        $bestAmt = null;
        $bestRecv = null;
        $bestSum = null;
        $bestAboveAmt = null;
        $bestAboveRecv = null;
        $bestAboveSum = null;
        /** @var array<string, array<string, mixed>> $pricingCache */
        $pricingCache = [];

        $applyPricingStep = function (string $midStr) use (
            &$pricingCache,
            $usePanoraUsdt,
            $originUsdt,
            $destStellarUsdc,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            $oneClick,
            $agg,
            $searchWait,
            $panora,
            $from
        ): ?array {
            if (isset($pricingCache[$midStr])) {
                return $pricingCache[$midStr];
            }
            if ($usePanoraUsdt) {
                $step = $this->sellPanoraUsdtPricingStep($midStr, $refundAptos, $panora, $agg);
            } else {
                $step = $this->sellUsdtBridgePricingStep(
                    $midStr,
                    $originUsdt,
                    $destStellarUsdc,
                    $stellarDeposit,
                    $refundAptos,
                    $deadlineIso,
                    $oneClick,
                    $agg,
                    $searchWait
                );
            }
            if ($step === null) {
                return null;
            }
            if (!empty($step['centiiv_failed'])) {
                $minUsdc = $this->parseCentiivQuoteMinUsdc(isset($step['centiiv_error']) && is_string($step['centiiv_error']) ? $step['centiiv_error'] : null);
                if ($minUsdc !== null) {
                    return ['centiiv_min' => true];
                }
                $this->whatsappService->sendMessage($from, '❌ Could not refresh the rate. Try again.');

                return ['fatal' => true];
            }
            $pricingCache[$midStr] = $step;

            return $step;
        };

        $hiStr = rtrim(rtrim(number_format($hi, 6, '.', ''), '0'), '.');
        $hiStep = $applyPricingStep($hiStr !== '' ? $hiStr : (string) $hi);
        if ($hiStep === null || !empty($hiStep['fatal'])) {
            if ($hiStep === null) {
                $wa = (string) ($user['phone_number'] ?? $user['phone'] ?? '');
                @file_put_contents(
                    '/tmp/sendra_ramp_debug.log',
                    '[' . gmdate('c') . "] sellSolveCryptoAmountForTargetNgn USDT hiRecv null wa={$wa} (1click or centiiv quote failed)\n",
                    FILE_APPEND
                );
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Could not get a rate right now. Please try again in a few minutes or use *USDC*.'
                );
            }

            return null;
        }
        if (!empty($hiStep['centiiv_min'])) {
            $lo = max($lo, (float) $min);
        } else {
            $hiRecv = (float) $hiStep['recv'];
            if ($hiRecv + 1e-6 < $targetNgn) {
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Your balance isn’t enough for *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                    'You have *' . ($balances[$asset] ?? '0') . "* {$asset}. Try a smaller amount."
                );

                return null;
            }
            $loStep = $applyPricingStep((string) $min);
            if ($loStep !== null && empty($loStep['fatal']) && empty($loStep['centiiv_min'])) {
                $loRecv = (float) $loStep['recv'];
                if ($hiRecv > $loRecv + 1e-6 && $targetNgn > $loRecv) {
                    $fraction = ($targetNgn - $loRecv) / ($hiRecv - $loRecv);
                    $fraction = max(0.0, min(1.0, $fraction));
                    $lo = max($min, $lo + $fraction * ($hi - $lo));
                }
            }
        }

        $processStep = function (string $midStr, float $mid) use (
            $usePanoraUsdt,
            $targetNgn,
            $toleranceNgn,
            &$bestAmt,
            &$bestRecv,
            &$bestSum,
            &$bestAboveAmt,
            &$bestAboveRecv,
            &$bestAboveSum,
            &$lo,
            &$hi,
            $applyPricingStep,
            $from
        ): bool {
            $step = $applyPricingStep($midStr);
            if ($step === null) {
                @file_put_contents(
                    '/tmp/sendra_ramp_debug.log',
                    '[' . gmdate('c') . '] sellSolveCryptoAmountForTargetNgn USDT pricing failed wa=' . $from . ' panora=' . ($usePanoraUsdt ? '1' : '0') . "\n",
                    FILE_APPEND
                );
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ Could not get a rate right now. Please try again in a few minutes or use *USDC*.'
                );

                return false;
            }
            if (!empty($step['fatal'])) {
                return false;
            }
            if (!empty($step['centiiv_min'])) {
                $lo = max($lo, $mid);

                return true;
            }
            $recv = (float) $step['recv'];
            $s = $step['sum'];
            $diff = $recv - $targetNgn;
            if ($bestRecv === null || abs($diff) < abs($bestRecv - $targetNgn)) {
                $bestAmt = $midStr;
                $bestRecv = $recv;
                $bestSum = $s;
            }
            if ($recv + 1e-9 >= $targetNgn) {
                if ($bestAboveRecv === null || $recv < $bestAboveRecv) {
                    $bestAboveAmt = $midStr;
                    $bestAboveRecv = $recv;
                    $bestAboveSum = $s;
                }
                if (abs($diff) <= $toleranceNgn) {
                    return false;
                }
            }
            if ($recv >= $targetNgn) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }

            return true;
        };

        for ($i = 0; $i < $maxIters; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
            if ($midStr === '' || (float) $midStr < $min) {
                $midStr = '0.01';
            }
            if (!$processStep($midStr, $mid)) {
                break;
            }
        }

        if ($bestAboveAmt !== null && $bestAboveSum !== null && $bestAboveRecv !== null) {
            $bestAmt = $bestAboveAmt;
            $bestSum = $bestAboveSum;
            $bestRecv = $bestAboveRecv;
        }

        if ($bestAmt === null || $bestSum === null || $bestRecv === null || $bestRecv + 1e-9 < $targetNgn) {
            $this->whatsappService->sendMessage(
                $from,
                '❌ Could not find a *USDT* amount that pays *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* right now.\nTry again, pick *USDC*, or a slightly different amount."
            );

            return null;
        }

        $finalUsdc = 0.0;
        $finalS = $bestSum;
        $finalPanoraData = null;
        $finalOneclickData = null;
        if (isset($pricingCache[$bestAmt])) {
            $finalUsdc = (float) $pricingCache[$bestAmt]['est_usdc'];
            $finalS = $pricingCache[$bestAmt]['sum'];
            if ($usePanoraUsdt && isset($pricingCache[$bestAmt]['panora_data'])) {
                $finalPanoraData = $pricingCache[$bestAmt]['panora_data'];
            } elseif (!$usePanoraUsdt && isset($pricingCache[$bestAmt]['dry_data'])) {
                $finalOneclickData = $pricingCache[$bestAmt]['dry_data'];
            }
        } elseif ($usePanoraUsdt) {
            $lockStep = $this->sellPanoraUsdtPricingStep($bestAmt, $refundAptos, $panora, $agg);
            if ($lockStep === null || !empty($lockStep['centiiv_failed'])) {
                $this->whatsappService->sendMessage($from, '❌ Could not lock a USDT quote right now. Try again.');

                return null;
            }
            $finalUsdc = (float) $lockStep['est_usdc'];
            $finalS = $lockStep['sum'];
            $finalPanoraData = $lockStep['panora_data'];
        } else {
            $finalSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($bestAmt, 6);
            $finalBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
                $originUsdt,
                $destStellarUsdc,
                $finalSmallest,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                true,
                $lockWait
            );
            $finalDry = $oneClick->requestQuote($finalBody);
            if (!$finalDry['success']) {
                $this->whatsappService->sendMessage($from, '❌ Could not lock a USDT quote right now. Try again.');

                return null;
            }
            $finalParsed = \App\Services\SendraOneClickService::parseQuoteResponse($finalDry['data']);
            $finalUsdc = (float) ($finalParsed['amount_out_formatted'] ?? 0);
            if ($finalUsdc <= 0) {
                $this->whatsappService->sendMessage($from, '❌ Quote response incomplete. Try again.');

                return null;
            }
            $finalQ = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => $finalUsdc,
            ]);
            if (!$finalQ['success']) {
                $minUsdc = $this->parseCentiivQuoteMinUsdc($finalQ['error'] ?? null);
                if ($minUsdc !== null) {
                    $this->whatsappService->sendMessage($from, $this->buildCentiivMinPayoutMessage($agg, $minUsdc));
                } else {
                    $this->whatsappService->sendMessage($from, '❌ Could not refresh the rate. Try again.');
                }

                return null;
            }
            $finalS = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($finalQ['data'], $finalUsdc);
            $finalOneclickData = $finalDry['data'];
        }

        $finalRecv = (float) ($finalS['receive_ngn'] ?? 0);
        if ($finalRecv > 0 && $finalRecv + 1e-9 < $targetNgn) {
            $lo = max($lo, (float) $bestAmt);
            for ($j = 0; $j < 3; $j++) {
                $mid = ($lo + $hi) / 2.0;
                $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
                if ($midStr === '' || (float) $midStr < $min) {
                    $midStr = '0.01';
                }
                if ($usePanoraUsdt) {
                    $step = $this->sellPanoraUsdtPricingStep($midStr, $refundAptos, $panora, $agg);
                } else {
                    $step = $this->sellUsdtBridgePricingStep(
                        $midStr,
                        $originUsdt,
                        $destStellarUsdc,
                        $stellarDeposit,
                        $refundAptos,
                        $deadlineIso,
                        $oneClick,
                        $agg,
                        $searchWait
                    );
                }
                if ($step === null || !empty($step['centiiv_failed'])) {
                    break;
                }
                $midRecv = (float) $step['recv'];
                if ($midRecv + 1e-9 >= $targetNgn) {
                    $bestAmt = $midStr;
                    $finalUsdc = (float) $step['est_usdc'];
                    $finalS = $step['sum'];
                    if ($usePanoraUsdt) {
                        $finalPanoraData = $step['panora_data'] ?? null;
                    } else {
                        $finalOneclickData = $step['dry_data'] ?? null;
                    }
                    $pricingCache[$midStr] = $step;
                    break;
                }
                $lo = $mid;
            }
        }

        $solved = [
            'crypto_amount' => $bestAmt,
            'receive_ngn_display' => (string) ($finalS['receive_ngn_display'] ?? $bestSum['receive_ngn_display'] ?? number_format($bestRecv, 2, '.', ',')),
            'rate_hint' => $finalS['rate_hint'] ?? ($bestSum['rate_hint'] ?? null),
            'fee_hint' => $finalS['fee_hint'] ?? ($bestSum['fee_hint'] ?? null),
            'oneclick_est_usdc' => $finalUsdc,
        ];
        if ($usePanoraUsdt) {
            $solved['panora_est_usdc'] = $finalUsdc;
            if (is_array($finalPanoraData)) {
                $solved['panora_quote_data'] = $finalPanoraData;
            }
        } elseif (is_array($finalOneclickData)) {
            $solved['oneclick_quote_data'] = $finalOneclickData;
        }

        return $solved;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function sellSendNairaAfterAssetPick($from, array $userRow, array $ctx, string $asset, \App\Services\SendraCentiivRampService $agg)
    {
        $target = (float) ($ctx['target_receive_ngn'] ?? 0);
        if ($target < 100) {
            $this->clearSellChatContext($from);

            return ['success' => false];
        }
        if ($asset === 'USDT' && !$this->oneclickCentiivRampConfigured()) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ USDT isn’t available for this flow here. Tap *USDC* or type *cancel*."
            );

            $ctx['step'] = 'await_send_naira_asset';
            unset($ctx['asset']);
            $this->setSellChatContext($from, $ctx);

            return ['success' => false];
        }
        $user = $this->userModel->findByPhone($from) ?: $userRow;
        $this->whatsappService->sendMessage($from, '⏳ Getting a rate for *≈ ₦' . number_format($target, 0, '.', ',') . "*…");
        $solved = $this->sellSolveCryptoAmountForTargetNgn($from, $user, $asset, $target, $agg);
        if ($solved === null) {
            $ctx['step'] = 'await_send_naira_asset';
            unset($ctx['asset']);
            $this->setSellChatContext($from, $ctx);

            return ['success' => false];
        }
        $avail = (float) str_replace(',', '', ($this->fetchAptosBalance($user['aptos_address']) ?? [])[$asset] ?? '0');
        if ($avail + 1e-9 < (float) $solved['crypto_amount']) {
            $this->whatsappService->sendMessage($from, '❌ Balance changed. Try again.');

            $ctx['step'] = 'await_send_naira_asset';
            unset($ctx['asset']);
            $this->setSellChatContext($from, $ctx);

            return ['success' => false];
        }
        $ctx['crypto_amount'] = $solved['crypto_amount'];
        $ctx['asset'] = $asset;
        $ctx['receive_ngn_display'] = $solved['receive_ngn_display'];
        $ctx['send_naira_rate_line'] = !empty($solved['rate_hint']) ? ('Rate: *' . $solved['rate_hint'] . '*') : '';
        $ctx['send_naira_fee_line'] = (string) ($solved['fee_hint'] ?? '');
        if ($asset === 'USDT' && !empty($solved['panora_quote_data']) && is_array($solved['panora_quote_data'])) {
            $ctx['panora_quote_data'] = $solved['panora_quote_data'];
            $ctx['panora_est_usdc'] = $solved['panora_est_usdc'] ?? null;
            $ctx['panora_centiiv_usdc'] = $solved['panora_centiiv_usdc'] ?? null;
        } elseif ($asset === 'USDT' && !empty($solved['oneclick_quote_data']) && is_array($solved['oneclick_quote_data'])) {
            $ctx['oneclick_dry_quote_data'] = $solved['oneclick_quote_data'];
            $ctx['oneclick_dry_est_usdc'] = $solved['oneclick_est_usdc'] ?? null;
        }
        $ctx['fiat'] = 'NGN';
        $ctx['send_naira_quick'] = true;

        return $this->sellResolveBankAndConfirmPayee(
            $from,
            $userRow,
            $ctx,
            (string) ($ctx['preset_account'] ?? ''),
            trim((string) ($ctx['preset_bank_guess'] ?? '')),
            $agg,
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sellApplyQuoteAndPrompt($from, $user, string $amountStr, string $asset, string $fiat, \App\Services\SendraCentiivRampService $agg)
    {
        $amount = (float) $amountStr;
        if ($amount < 0.01) {
            $this->whatsappService->sendMessage($from, "⚠️ Minimum amount is *0.01* {$asset}.");

            return ['success' => false];
        }

        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            $this->whatsappService->sendMessage($from, "⚠️ Only *USDC* or *USDT* on Aptos are supported.");

            return ['success' => false];
        }

        if ($fiat !== 'NGN') {
            $this->whatsappService->sendMessage($from, "⚠️ Only *NGN* payouts are available in this chat for now.");

            return ['success' => false];
        }

        $balances = $this->fetchAptosBalance($user['aptos_address']);
        if ($balances === null) {
            $this->whatsappService->sendMessage($from, "❌ Could not read your balance. Try again.");

            return ['success' => false];
        }
        $avail = (float) str_replace(',', '', $balances[$asset] ?? '0');
        if ($avail + 1e-9 < $amount) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Insufficient *{$asset}* balance.\nYou have: *{$balances[$asset]}*\nNeeded: *{$amountStr}*"
            );

            return ['success' => false, 'reason' => 'insufficient_balance'];
        }

        $centiivFromAsset = $asset;
        $centiivQuoteAmount = $amount;
        $bridgeHint = '';

        if ($this->oneclickCentiivRampConfigured() && $asset === 'USDT') {
            $this->whatsappService->sendMessage($from, '⏳ Getting a rate…');
            $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
            if ($refundAptos === '') {
                $this->whatsappService->sendMessage($from, '❌ Missing Aptos address. Contact support.');

                return ['success' => false];
            }
            if ($this->panoraUsdtOfframpEnabled()) {
                $step = $this->sellPanoraUsdtPricingStep($amountStr, $refundAptos, new \App\Services\SendraPanoraService(), $agg);
                if ($step === null || !empty($step['centiiv_failed'])) {
                    $this->whatsappService->sendMessage(
                        $from,
                        "❌ Could not get a rate right now. Try again in a few minutes or use *USDC*."
                    );

                    return ['success' => false];
                }
                $estUsdc = (float) $step['est_usdc'];
                if ($estUsdc < 0.01) {
                    $this->whatsappService->sendMessage($from, '❌ Swap estimate too small. Try a larger amount.');

                    return ['success' => false];
                }
                $centiivFromAsset = 'USDC';
                $centiivQuoteAmount = $estUsdc;
                $bridgeHint = '';
            } else {
                $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
                $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
                $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
                $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
                $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
                $oneClick = new \App\Services\SendraOneClickService();
                $dryBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
                    $originUsdt,
                    $destStellarUsdc,
                    $amountSmallest,
                    $stellarDeposit,
                    $refundAptos,
                    $deadlineIso,
                    true
                );
                $dryRes = $oneClick->requestQuote($dryBody);
                if (!$dryRes['success']) {
                    $err = $dryRes['error'] ?? 'bridge quote failed';
                    error_log("Sendra sell: USDT bridge quote for rate preview failed {$from}: {$err} " . json_encode($dryRes['data']));
                    $this->whatsappService->sendMessage(
                        $from,
                        "❌ Could not get a rate right now. Try again in a few minutes."
                    );

                    return ['success' => false];
                }
                $parsedDry = \App\Services\SendraOneClickService::parseQuoteResponse($dryRes['data']);
                $estUsdc = (float) ($parsedDry['amount_out_formatted'] ?? 0);
                if ($estUsdc < 0.01) {
                    $this->whatsappService->sendMessage($from, '❌ Bridge estimate too small for a rate quote. Try a larger amount.');

                    return ['success' => false];
                }
                $centiivFromAsset = 'USDC';
                $centiivQuoteAmount = $estUsdc;
                $bridgeHint = '';
            }
        } else {
            $this->whatsappService->sendMessage($from, '⏳ Getting a rate…');
        }

        $quote = $agg->getQuote([
            'fromAsset' => $centiivFromAsset,
            'toAsset' => 'NGN',
            'amount' => $centiivQuoteAmount,
        ]);

        if (!$quote['success']) {
            $err = $quote['error'] ?? 'Quote failed';
            error_log("Centiiv quote failed for {$from}: {$err} " . json_encode($quote['data']));
            if (stripos($err, 'pk_sandbox') !== false
                || stripos($err, 'pk_live') !== false
                || stripos($err, 'Invalid API key') !== false
                || stripos($err, 'public key') !== false) {
                error_log(
                    'Sendra: set SENDRA_CENTIIV_JWT to a Centiiv *public* key (pk_sandbox_* or pk_live_* from the dashboard). '
                    . 'cnt-* / Direct Pay keys do not work on /public/quote.'
                );
            }

            $body = "❌ No rate available right now.";
            // Centiiv may return a rail constraint bug like: "maximum transaction amount of 0 for this rail".
            // In practice this means the corridor is effectively disabled/misconfigured, not a user error.
            if (preg_match('/maximum transaction amount of 0/i', $err)) {
                $body = "❌ NGN payout is temporarily unavailable right now.\n\nPlease try again later.";
                $this->whatsappService->sendMessage($from, $body);

                return ['success' => false];
            }
            if (preg_match('/no rail|no route|no liquidity|no corridor|liquidity provider/i', $err)) {
                error_log("Sendra sell: quote liquidity note {$from} asset={$asset} centiiv_from={$centiivFromAsset} err={$err}");
                $body .= "\n\nTry *USDC*, another amount, or again later.";
            } else {
                $body .= "\n\nTry again shortly.";
            }

            $this->whatsappService->sendMessage($from, $body);

            return ['success' => false];
        }

        $summary = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($quote['data'], $centiivQuoteAmount);
        $feeLine = $summary['fee_hint'] ? ("\n" . $summary['fee_hint']) : '';
        $rateLine = $summary['rate_hint'] ? ("\n" . $summary['rate_hint']) : '';

        $ctx = [
            'flow' => 'sell',
            'step' => 'await_quote_confirm',
            'fiat' => $fiat,
            'crypto_amount' => $amountStr,
            'asset' => $asset,
            'receive_ngn_display' => $summary['receive_ngn_display'],
        ];
        $this->setSellChatContext($from, $ctx);

        $this->whatsappService->sendButtonMessage(
            $from,
            "💱 *Sell {$amountStr} {$asset}*\n\n" .
            "Bank payout *≈ ₦{$summary['receive_ngn_display']}* (indicative).{$feeLine}{$rateLine}{$bridgeHint}\n\n" .
            "Continue?",
            [
                'sell_quote_yes' => '✅ Yes',
                'sell_quote_change' => '✏️ Different amount',
            ]
        );

        return ['success' => true, 'action' => 'sell_quote'];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function sellResolveBankAndConfirmPayee($from, $userRow, array $ctx, string $accountNumber, string $bankNameGuess, \App\Services\SendraCentiivRampService $agg, bool $quietVerify = false)
    {
        if (!$this->sellVerifyAndPopulateBankContext($from, $ctx, $accountNumber, $bankNameGuess, $agg, $quietVerify)) {
            return ['success' => false];
        }

        $this->setSellChatContext($from, $ctx);

        return $this->sellCreateCentiivOrderAndQueuePin($from, $userRow, $ctx, $agg);
    }

    /**
     * Centiiv bank list + verify-account; writes payout fields into $ctx.
     *
     * @param array<string, mixed> $ctx
     */
    private function sellVerifyAndPopulateBankContext(
        string $from,
        array &$ctx,
        string $accountNumber,
        string $bankNameGuess,
        \App\Services\SendraCentiivRampService $agg,
        bool $quietVerify = false
    ): bool {
        $banksRes = $agg->getBanks();
        if (!$banksRes['success']) {
            $this->whatsappService->sendMessage($from, "❌ Could not load bank list. Try again shortly.");

            return false;
        }
        $banks = \App\Services\SendraCentiivRampService::normalizeBankList($banksRes['data']);
        if ($banks === []) {
            error_log('Centiiv getBanks returned no parseable banks: ' . json_encode($banksRes['data']));
            $this->whatsappService->sendMessage($from, "❌ Bank list unavailable. Please try again later.");

            return false;
        }

        $code = \App\Services\SendraCentiivRampService::resolveBankCode($bankNameGuess, $banks);
        if ($code === null) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not match *{$bankNameGuess}* to a bank.\nTry the official short name (e.g. *GTBank*, *Access*, *Opay*)."
            );

            return false;
        }

        $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksRes['data'], $code);
        if ($rawBankRow !== null) {
            $ctx['centiiv_banks_raw_row'] = $rawBankRow;
        }

        if (!$quietVerify) {
            $this->whatsappService->sendMessage($from, '⏳ Checking your account…');
        }

        $verify = $agg->verifyBankAccount($code, $accountNumber);
        if (!$verify['success']) {
            $err = $verify['error'] ?? 'Verify failed';
            error_log('Bank verify failed for ' . $from . ': ' . $err);
            $this->whatsappService->sendMessage(
                $from,
                "❌ Account could not be verified.\nCheck the 10 digits and bank name, then try again."
            );

            return false;
        }

        $vdata = $verify['data'];
        $acctName = $this->centiivPickAccountName($vdata);

        $userResolvedCode = \App\Services\SendraCentiivRampService::normalizeCentiivBankCode($code);
        $resolved = \App\Services\SendraCentiivRampService::resolvedBankFromVerifyEnvelope(
            is_array($vdata) ? $vdata : []
        );
        if ($resolved !== null && $resolved['code'] !== '') {
            if ($resolved['code'] !== $userResolvedCode) {
                error_log(
                    'Sendra sell: verify bank differs from user guess; using resolved bankCode. '
                    . json_encode(['wa' => $from, 'guess' => $bankNameGuess, 'user_code' => $userResolvedCode, 'resolved' => $resolved])
                );
            }
            $code = $resolved['code'];
            $bankDisplay = !empty($resolved['name'])
                ? (string) $resolved['name']
                : $this->centiivPickBankLabel($vdata, $banks, $code, $bankNameGuess);
        } else {
            $bankDisplay = $this->centiivPickBankLabel($vdata, $banks, $code, $bankNameGuess);
        }

        if ($acctName === null || $acctName === '') {
            error_log('Centiiv verify missing account name: ' . json_encode($vdata));
            $this->whatsappService->sendMessage($from, "❌ Verification incomplete. Try different details.");

            return false;
        }

        $ctx['account_number'] = $accountNumber;
        $ctx['bank_code'] = $code;
        $ctx['bank_name'] = $bankDisplay;
        $ctx['account_name'] = $acctName;
        $ctx['centiiv_verify_data'] = is_array($vdata) ? $vdata : null;
        $ctx['preset_account'] = $accountNumber;
        $ctx['preset_bank_guess'] = $bankDisplay;

        return true;
    }

    /**
     * Panora USDT→USDC (user wallet) + Centiiv order; 1Click live quote runs after Panora swap on PIN.
     *
     * @param array<string, mixed> $ctx
     */
    private function sellCreatePanoraOneClickCentiivOrderAndQueuePin($from, $userRow, array $ctx, \App\Services\SendraCentiivRampService $agg)
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $acct = (string) ($ctx['account_number'] ?? '');
        $code = (string) ($ctx['bank_code'] ?? '');
        $name = (string) ($ctx['account_name'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $refundAptos = trim((string) ($userRow['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            $this->whatsappService->sendMessage($from, '❌ Missing Aptos address on your account. Contact support.');

            return ['success' => false];
        }

        $panoraEstUsdc = isset($ctx['panora_est_usdc']) ? (float) $ctx['panora_est_usdc'] : 0.0;
        $parsedPanora = null;
        $centiivUsdcAmount = 0.0;
        if (!empty($ctx['panora_quote_data']) && is_array($ctx['panora_quote_data'])) {
            $parsedPanora = \App\Services\SendraPanoraService::parseSwapResponse($ctx['panora_quote_data']);
            $centiivUsdcAmount = \App\Services\SendraPanoraService::centiivUsdcAmountFromQuote($parsedPanora, $panoraEstUsdc > 0 ? $panoraEstUsdc : null);
        }
        if ($centiivUsdcAmount <= 0) {
            $panora = new \App\Services\SendraPanoraService();
            $usdcTarget = $panoraEstUsdc > 0
                ? \App\Services\SendraCentiivRampService::formatUsdcAmountForApi($panoraEstUsdc)
                : '';
            if ($usdcTarget !== '') {
                $pq = $panora->requestSwapQuoteExactOut($usdcTarget, $refundAptos);
            } else {
                $pq = $panora->requestSwapQuote($amountStr, $refundAptos);
            }
            if (!$pq['success']) {
                $err = $pq['error'] ?? 'Panora quote failed';
                error_log("Sendra Panora quote failed {$from}: {$err} " . json_encode($pq['data']));
                $this->whatsappService->sendMessage(
                    $from,
                    "❌ Could not get a quote right now.\n\nTry again in a minute or use *USDC*."
                );

                return ['success' => false];
            }
            $parsedPanora = \App\Services\SendraPanoraService::parseSwapResponse($pq['data']);
            $fallbackUsdc = $panoraEstUsdc > 0 ? $panoraEstUsdc : null;
            if ($fallbackUsdc === null && $usdcTarget !== '') {
                $fallbackUsdc = (float) $usdcTarget;
            }
            $centiivUsdcAmount = \App\Services\SendraPanoraService::centiivUsdcAmountFromQuote($parsedPanora, $fallbackUsdc);
        }
        if ($centiivUsdcAmount <= 0) {
            error_log(
                'Sendra Panora order: no USDC amount from quote wa=' . $from
                . ' est_usdc=' . json_encode($ctx['panora_est_usdc'] ?? null)
                . ' parsed=' . json_encode($parsedPanora)
            );
            $this->whatsappService->sendMessage($from, '❌ Invalid quoted USDC amount. Try a different size.');

            return ['success' => false];
        }

        $panoraWalletUsdc = $centiivUsdcAmount;
        if (isset($ctx['panora_centiiv_usdc']) && (float) $ctx['panora_centiiv_usdc'] > 0) {
            $centiivOrderUsdc = (float) $ctx['panora_centiiv_usdc'];
        } else {
            $centiivOrderUsdc = \App\Services\SendraCentiivRampService::expectedStellarDepositUsdc($panoraWalletUsdc);
        }
        // PIN line: show NGN for wallet USDC (matches “send 1000”). Centiiv order amount stays haircut-adjusted.
        $pinQuoteUsdc = $panoraWalletUsdc;
        $orderQuote = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $pinQuoteUsdc,
        ]);
        if ($orderQuote['success']) {
            $orderSum = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($orderQuote['data'], $pinQuoteUsdc);
            $ctx['receive_ngn_display'] = (string) ($orderSum['receive_ngn_display'] ?? $ctx['receive_ngn_display'] ?? '—');
        }

        $publicBase = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );

        $rawBankRow = isset($ctx['centiiv_banks_raw_row']) && is_array($ctx['centiiv_banks_raw_row'])
            ? $ctx['centiiv_banks_raw_row']
            : null;
        if ($rawBankRow === null) {
            $banksLookup = $agg->getBanks();
            if ($banksLookup['success']) {
                $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
            }
        }

        $body = [
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $centiivOrderUsdc,
            'description' => 'Sendra WhatsApp sell ' . $from . ' (Panora USDT path)',
            'beneficiary' => \App\Services\SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
                $acct,
                $code,
                $name,
                $bankDisplay,
                $rawBankRow,
                isset($ctx['centiiv_verify_data']) && is_array($ctx['centiiv_verify_data']) ? $ctx['centiiv_verify_data'] : null,
                \App\Services\SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($from)
            ),
            'metadata' => [
                'user_phone' => $from,
                'source' => 'sendra_whatsapp',
                'sendra_offramp_path' => 'panora_oneclick_centiiv',
                'sendra_ledger_asset' => 'USDT',
                'sendra_ledger_amount' => $amountStr,
                'sendra_panora_est_usdc' => (string) $panoraWalletUsdc,
                'sendra_centiiv_order_usdc' => (string) $centiivOrderUsdc,
            ],
        ];
        $lp = trim((string) ($_ENV['SENDRA_CENTIIV_LIQUIDITY_PROVIDER'] ?? getenv('SENDRA_CENTIIV_LIQUIDITY_PROVIDER') ?: ''));
        if ($lp !== '') {
            $body['metadata']['liquidity_provider'] = $lp;
        }
        $refundStellar = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_REFUND_ADDRESS'] ?? getenv('SENDRA_CENTIIV_STELLAR_REFUND_ADDRESS') ?: ''));
        if ($refundStellar !== '' && strlen($refundStellar) >= 5 && $refundStellar[0] === 'G') {
            $body['refundAddress'] = $refundStellar;
        }
        $webhookUrlOverride = trim(
            (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_URL'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_URL') ?: '')
        );
        if ($webhookUrlOverride !== '') {
            $body['webhookUrl'] = $webhookUrlOverride;
        } elseif ($publicBase !== '') {
            $path = trim(
                (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_PATH'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_PATH') ?: 'CentiivWebhook.php'),
                '/'
            );
            $body['webhookUrl'] = rtrim($publicBase, '/') . '/' . $path;
        }

        $body = \App\Services\SendraCentiivRampService::enrichOfframpOrderBody($body);
        $payoutRef = '';
        if (is_array($body['metadata'] ?? null)) {
            $payoutRef = trim((string) ($body['metadata']['sendra_payout_reference'] ?? $body['metadata']['trackingId'] ?? ''));
        }

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $this->formatCentiivConversionOrderError($created['error'] ?? 'Order failed');
            error_log("Centiiv create order (Panora path) failed {$from}: {$err} " . json_encode($created['data']));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not start this payout right now.\n\nPlease try again in a few minutes or contact support@usesendra.com."
            );

            return ['success' => false];
        }

        $data = $created['data'];
        $orderId = \App\Services\SendraCentiivRampService::extractOrderId($data);
        $orderIdHex = \App\Services\SendraCentiivRampService::extractOrderIdHex($data);
        $orderStellar = \App\Services\SendraCentiivRampService::extractStellarDepositAddress(is_array($data) ? $data : []);
        $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        $allowPoolFallback = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT'] ?? getenv('SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT') ?: '')));
        if (($orderStellar === null || $orderStellar === '') && $allowPoolFallback === '1' && $stellarDeposit !== '') {
            $orderStellar = $stellarDeposit;
        }
        if ($orderStellar === null || $orderStellar === '' || !\App\Services\SendraCentiivRampService::looksLikeStellarAccountId($orderStellar)) {
            error_log('Sendra Panora path: no Stellar pay-in on Centiiv order for ' . $from . ' raw=' . json_encode($data));
            $this->whatsappService->sendMessage(
                $from,
                '❌ Could not start this payout. Nothing left your wallet. Contact support@usesendra.com'
            );

            return ['success' => false];
        }

        $pendingId = $this->storePendingTransaction([
            'user_phone' => $from,
            'tx_type' => 'offramp_panora_oneclick_centiiv',
            'amount' => $amountStr,
            'currency' => 'USDT',
            'recipient_address' => $refundAptos,
            'human_description' => json_encode([
                'kind' => 'panora_oneclick_centiiv_offramp',
                'centiiv_order_id' => $orderId,
                'centiiv_order_id_hex' => $orderIdHex,
                'centiiv_stellar_payin' => $orderStellar,
                'centiiv_usdc_amount' => $centiivOrderUsdc,
                'panora_to_token_amount' => $panoraWalletUsdc,
                'panora_est_usdc' => $panoraWalletUsdc,
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'sendra_payout_reference' => $payoutRef !== '' ? $payoutRef : null,
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $ctx['bank_name'] ?? '',
                'need_oneclick_poll' => false,
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->clearSellChatContext($from);

        $recvNgn = (string) ($ctx['receive_ngn_display'] ?? '—');
        $this->whatsappService->sendButtonMessage(
            $from,
            "🔐 *Enter your 6-digit PIN*\n\n" .
            "*≈ ₦{$recvNgn}* payout to:\n*{$name}*\n`{$acct}` · *{$bankDisplay}*\n\n" .
            "Wallet debit: *{$amountStr} USDT*\n" .
            "_(Swap to USDC in your wallet, then bridge to bank rail.)_\n\n" .
            "Reply with your *PIN* to continue.",
            ['cancel_' . $pendingId => 'Cancel']
        );

        return ['success' => true, 'action' => 'sell_pin_queued_panora_oneclick'];
    }

    /**
     * Centiiv Stellar USDC rail + 1Click Aptos → Stellar (no Centiiv Aptos deposit).
     * Centiiv order uses USDC amount ≈ 1Click quoted output; metadata links correlation + deposit for support.
     *
     * @param array<string, mixed> $ctx
     */
    private function sellCreateOneClickCentiivOrderAndQueuePin($from, $userRow, array $ctx, \App\Services\SendraCentiivRampService $agg)
    {
        $asset = (string) ($ctx['asset'] ?? '');
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;
        $acct = (string) ($ctx['account_number'] ?? '');
        $code = (string) ($ctx['bank_code'] ?? '');
        $name = (string) ($ctx['account_name'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
        $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        $stellarOutDecimals = (int) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_DECIMALS'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_DECIMALS') ?: 7);
        $aptosTokenDecimals = 6;

        $originAsset = $asset === 'USDC' ? $originUsdc : $originUsdt;
        $refundAptos = trim((string) ($userRow['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            $this->whatsappService->sendMessage($from, '❌ Missing Aptos address on your account. Contact support.');

            return ['success' => false];
        }

        $deadlineTs = time() + 50 * 60;
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', $deadlineTs);
        $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, $aptosTokenDecimals);

        $oneClick = new \App\Services\SendraOneClickService();

        // 1) Dry quote to pooled Stellar rail — use prefetch if available to avoid extra 1Click calls.
        $prefetched = isset($ctx['oneclick_dry_quote_data']) && is_array($ctx['oneclick_dry_quote_data'])
            ? $ctx['oneclick_dry_quote_data']
            : null;

        if ($prefetched !== null) {
            $this->oneClickOfframpLog('quote_dry_prefetched', $from, [
                'amount_ledger' => $amountStr,
                'origin_asset' => $asset,
            ]);
            $parsedDry = \App\Services\SendraOneClickService::parseQuoteResponse($prefetched);
            $quoteDryRaw = $prefetched;
        } else {
            $quoteBodyDry = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
                $originAsset,
                $destStellarUsdc,
                $amountSmallest,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                true
            );
            $this->oneClickOfframpLog('quote_dry_config', $from, [
                'amount_ledger' => $amountStr,
                'origin_asset' => $asset,
                'origin_nep' => $originAsset,
                'stellar_recipient' => $stellarDeposit,
                'refund_aptos' => $refundAptos,
                'deadline' => $deadlineIso,
            ]);
            $quoteDryRes = $oneClick->requestQuote($quoteBodyDry);
            if (!$quoteDryRes['success']) {
                $err = $quoteDryRes['error'] ?? '1Click dry quote failed';
                error_log("Sendra 1Click dry quote failed {$from}: {$err} " . json_encode($quoteDryRes['data']));
                $this->whatsappService->sendMessage(
                    $from,
                    "❌ Could not get a quote right now.\n\nTry again in a minute or contact support@usesendra.com"
                );

                return ['success' => false];
            }
            $quoteDryRaw = $quoteDryRes['data'];
            $parsedDry = \App\Services\SendraOneClickService::parseQuoteResponse($quoteDryRaw);
        }
        if ($parsedDry['correlation_id'] === null) {
            $this->oneClickOfframpLog('quote_dry_parse_incomplete', $from, [
                'parsed' => $parsedDry,
            ]);
            error_log('Sendra 1Click dry quote missing correlation: ' . json_encode($quoteDryRaw));
            $this->whatsappService->sendMessage($from, '❌ Quote response incomplete. Please try again.');

            return ['success' => false];
        }

        $usdcDrySmallest = $parsedDry['amount_out'] ?? '0';
        $centiivUsdcAmount = (float) \App\Services\SendraOneClickService::smallestUnitsToFloatString((string) $usdcDrySmallest, $stellarOutDecimals);
        if ($centiivUsdcAmount <= 0) {
            $this->whatsappService->sendMessage($from, '❌ Invalid quoted USDC amount. Please try a different size.');

            return ['success' => false];
        }
        $this->oneClickOfframpLog('quote_dry_ok', $from, [
            'oneclick_correlation' => $parsedDry['correlation_id'],
            'est_usdc_out_smallest' => $parsedDry['amount_out'] ?? null,
        ]);

        $publicBase = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );

        $rawBankRow = isset($ctx['centiiv_banks_raw_row']) && is_array($ctx['centiiv_banks_raw_row'])
            ? $ctx['centiiv_banks_raw_row']
            : null;
        if ($rawBankRow === null) {
            $banksLookup = $agg->getBanks();
            if ($banksLookup['success']) {
                $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
            }
        }

        $body = [
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $centiivUsdcAmount,
            'description' => 'Sendra WhatsApp sell ' . $from . ' (1Click dry ' . $parsedDry['correlation_id'] . ')',
            'beneficiary' => \App\Services\SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
                $acct,
                $code,
                $name,
                $bankDisplay,
                $rawBankRow,
                isset($ctx['centiiv_verify_data']) && is_array($ctx['centiiv_verify_data']) ? $ctx['centiiv_verify_data'] : null,
                \App\Services\SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($from)
            ),
            'metadata' => [
                'user_phone' => $from,
                'source' => 'sendra_whatsapp',
                'sendra_oneclick_correlation_id' => $parsedDry['correlation_id'],
                'sendra_oneclick_quote_phase' => 'dry_pool_pricing',
                'sendra_ledger_asset' => $asset,
                'sendra_ledger_amount' => $amountStr,
            ],
        ];
        $lp = trim((string) ($_ENV['SENDRA_CENTIIV_LIQUIDITY_PROVIDER'] ?? getenv('SENDRA_CENTIIV_LIQUIDITY_PROVIDER') ?: ''));
        if ($lp !== '') {
            $body['metadata']['liquidity_provider'] = $lp;
        }
        $refundStellar = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_REFUND_ADDRESS'] ?? getenv('SENDRA_CENTIIV_STELLAR_REFUND_ADDRESS') ?: ''));
        if ($refundStellar !== '' && strlen($refundStellar) >= 5 && $refundStellar[0] === 'G') {
            $body['refundAddress'] = $refundStellar;
        }
        $webhookUrlOverride = trim(
            (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_URL'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_URL') ?: '')
        );
        if ($webhookUrlOverride !== '') {
            $body['webhookUrl'] = $webhookUrlOverride;
        } elseif ($publicBase !== '') {
            $path = trim(
                (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_PATH'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_PATH') ?: 'CentiivWebhook.php'),
                '/'
            );
            $body['webhookUrl'] = rtrim($publicBase, '/') . '/' . $path;
        }

        $body = \App\Services\SendraCentiivRampService::enrichOfframpOrderBody($body);
        $payoutRef = '';
        if (is_array($body['metadata'] ?? null)) {
            $payoutRef = trim((string) ($body['metadata']['sendra_payout_reference'] ?? $body['metadata']['trackingId'] ?? ''));
        }

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $this->formatCentiivConversionOrderError($created['error'] ?? 'Order failed');
            error_log("Centiiv create order (1Click path) failed {$from}: {$err} " . json_encode($created['data']));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not start this payout right now.\n\nPlease try again in a few minutes or contact support@usesendra.com."
            );

            return ['success' => false];
        }

        $data = $created['data'];
        $orderId = \App\Services\SendraCentiivRampService::extractOrderId($data);
        $orderIdHex = \App\Services\SendraCentiivRampService::extractOrderIdHex($data);

        $orderStellar = \App\Services\SendraCentiivRampService::extractStellarDepositAddress(
            is_array($data) ? $data : []
        );
        $allowPoolFallback = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT'] ?? getenv('SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT') ?: '')));
        if (($orderStellar === null || $orderStellar === '') && $allowPoolFallback === '1' && $stellarDeposit !== '') {
            $orderStellar = $stellarDeposit;
            error_log('Sendra 1Click: Centiiv order missing Stellar pay-in; using SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT (SENDRA_ONECLICK_ALLOW_POOL_STELLAR_RECIPIENT=1).');
        }
        if ($orderStellar === null || $orderStellar === '' || !\App\Services\SendraCentiivRampService::looksLikeStellarAccountId($orderStellar)) {
            error_log('Sendra 1Click: no Stellar pay-in on Centiiv order for ' . $from . ' raw=' . json_encode($data));
            error_log('Sendra sell: missing Stellar pay-in on order for ' . $from . ' orderId=' . (string) $orderId);
            $this->whatsappService->sendMessage(
                $from,
                '❌ Could not start this payout. Nothing left your wallet. Contact support@usesendra.com'
            );

            return ['success' => false];
        }

        // 2) Live 1Click quote: recipient must be this order’s Stellar G so bridged USDC lands where Centiiv expects it.
        $quoteBodyLive = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originAsset,
            $destStellarUsdc,
            $amountSmallest,
            $orderStellar,
            $refundAptos,
            $deadlineIso,
            false
        );
        $this->oneClickOfframpLog('quote_live_config', $from, [
            'stellar_recipient' => $orderStellar,
            'centiiv_order_id' => $orderId,
        ]);
        $quoteRes = $oneClick->requestQuote($quoteBodyLive);
        if (!$quoteRes['success']) {
            $err = $quoteRes['error'] ?? '1Click live quote failed';
            error_log("Sendra 1Click live quote failed {$from}: {$err} " . json_encode($quoteRes['data']));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not start this payout right now. Nothing left your wallet.\n\nTry again in a few minutes or contact support@usesendra.com"
            );

            return ['success' => false];
        }

        $parsed = \App\Services\SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        if ($parsed['deposit_address'] === null || $parsed['correlation_id'] === null) {
            $this->oneClickOfframpLog('quote_live_parse_incomplete', $from, ['parsed' => $parsed]);
            error_log('Sendra 1Click live quote missing deposit or correlation: ' . json_encode($quoteRes['data']));
            $this->whatsappService->sendMessage($from, '❌ Quote response incomplete. Please try selling again.');

            return ['success' => false];
        }

        $usdcLiveSmallest = (string) ($parsed['amount_out'] ?? '0');
        $slippageBps = (int) ($_ENV['SENDRA_ONECLICK_CENTIIV_SLIPPAGE_BPS'] ?? getenv('SENDRA_ONECLICK_CENTIIV_SLIPPAGE_BPS') ?: 200);
        if ($slippageBps < 0 || $slippageBps > 5000) {
            $slippageBps = 200;
        }
        if (ctype_digit($usdcDrySmallest) && ctype_digit($usdcLiveSmallest) && bccomp($usdcDrySmallest, '0', 0) > 0) {
            $minLive = bcdiv(bcmul($usdcDrySmallest, (string) (10000 - $slippageBps), 0), '10000', 0);
            if (bccomp($usdcLiveSmallest, $minLive, 0) < 0) {
                $this->oneClickOfframpLog('quote_live_slippage_abort', $from, [
                    'usdc_dry_smallest' => $usdcDrySmallest,
                    'usdc_live_smallest' => $usdcLiveSmallest,
                    'min_live_smallest' => $minLive,
                    'centiiv_order_id' => $orderId,
                ]);
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ The live rate moved too much before we could start. Nothing was sent — try *sell* again.'
                );

                return ['success' => false];
            }
        }

        $this->oneClickOfframpLog('quote_live_ok', $from, [
            'oneclick_deposit_aptos' => $parsed['deposit_address'],
            'oneclick_correlation' => $parsed['correlation_id'],
            'oneclick_memo' => $parsed['deposit_memo'] ?? null,
            'est_usdc_out_smallest' => $parsed['amount_out'] ?? null,
            'centiiv_stellar_payin' => $orderStellar,
        ]);

        $this->oneClickOfframpLog('centiiv_order_ok', $from, [
            'centiiv_order_id' => $orderId,
            'centiiv_order_id_hex' => $orderIdHex,
            'usdc_quoted_for_order' => $centiivUsdcAmount,
            'oneclick_correlation_dry' => $parsedDry['correlation_id'],
            'oneclick_correlation_live' => $parsed['correlation_id'],
            'centiiv_stellar_payin' => $orderStellar,
        ]);

        $quoteDeadline = null;
        $rq = $quoteRes['data']['quote'] ?? null;
        if (is_array($rq) && !empty($rq['deadline'])) {
            $quoteDeadline = strtotime((string) $rq['deadline']);
        }
        $cap30 = time() + 30 * 60;
        if ($quoteDeadline !== null && $quoteDeadline > time()) {
            $expiresAt = min($cap30, $quoteDeadline);
        } else {
            $expiresAt = $cap30;
        }

        $pendingId = $this->storePendingTransaction([
            'user_phone' => $from,
            'tx_type' => 'offramp_oneclick_centiiv',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => $parsed['deposit_address'],
            'human_description' => json_encode([
                'kind' => 'oneclick_centiiv_offramp',
                'centiiv_order_id' => $orderId,
                'sendra_payout_reference' => $payoutRef !== '' ? $payoutRef : null,
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $ctx['bank_name'] ?? '',
                'oneclick_correlation_id' => $parsed['correlation_id'],
                'oneclick_correlation_id_dry' => $parsedDry['correlation_id'],
                'centiiv_stellar_payin' => $orderStellar,
                'oneclick_deposit_memo' => $parsed['deposit_memo'],
                'oneclick_stellar_usdc_out' => $parsed['amount_out_formatted'],
                'oneclick_quote_signature' => $parsed['signature'],
                'centiiv_order_id_hex' => $orderIdHex,
                'need_oneclick_poll' => false,
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->clearSellChatContext($from);

        $recvNgn = (string) ($ctx['receive_ngn_display'] ?? '—');
        $this->whatsappService->sendButtonMessage(
            $from,
            "🔐 *Enter your 6-digit PIN*\n\n" .
            "*≈ ₦{$recvNgn}* payout to:\n*{$name}*\n`{$acct}` · *{$bankDisplay}*\n\n" .
            "Wallet debit: *{$amountStr} {$asset}*\n\n" .
            "Reply with your *PIN* to continue.",
            ['cancel_' . $pendingId => 'Cancel']
        );

        return ['success' => true, 'action' => 'sell_pin_queued_oneclick'];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function sellCreateCentiivOrderAndQueuePin($from, $userRow, array $ctx, \App\Services\SendraCentiivRampService $agg)
    {
        $ledgerAsset = (string) ($ctx['asset'] ?? '');
        if ($ledgerAsset === 'USDT' && $this->panoraUsdtOfframpEnabled()) {
            return $this->sellCreatePanoraOneClickCentiivOrderAndQueuePin($from, $userRow, $ctx, $agg);
        }
        if ($this->oneclickCentiivRampConfigured()) {
            return $this->sellCreateOneClickCentiivOrderAndQueuePin($from, $userRow, $ctx, $agg);
        }

        $asset = (string) ($ctx['asset'] ?? '');
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;
        $acct = (string) ($ctx['account_number'] ?? '');
        $code = (string) ($ctx['bank_code'] ?? '');
        $name = (string) ($ctx['account_name'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $publicBase = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );

        $rawBankRow = isset($ctx['centiiv_banks_raw_row']) && is_array($ctx['centiiv_banks_raw_row'])
            ? $ctx['centiiv_banks_raw_row']
            : null;
        if ($rawBankRow === null) {
            $banksLookup = $agg->getBanks();
            if ($banksLookup['success']) {
                $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
            }
        }

        $body = [
            'fromAsset' => $asset,
            'toAsset' => 'NGN',
            'amount' => $amount,
            'description' => 'Sendra WhatsApp sell ' . $from,
            'beneficiary' => \App\Services\SendraCentiivRampService::buildOfframpBeneficiaryWithVerify(
                $acct,
                $code,
                $name,
                $bankDisplay,
                $rawBankRow,
                isset($ctx['centiiv_verify_data']) && is_array($ctx['centiiv_verify_data']) ? $ctx['centiiv_verify_data'] : null,
                \App\Services\SendraCentiivRampService::beneficiaryExternalIdFromWaPhone($from)
            ),
            'metadata' => [
                'user_phone' => $from,
                'source' => 'sendra_whatsapp',
            ],
        ];
        $lp = trim((string) ($_ENV['SENDRA_CENTIIV_LIQUIDITY_PROVIDER'] ?? getenv('SENDRA_CENTIIV_LIQUIDITY_PROVIDER') ?: ''));
        if ($lp !== '') {
            $body['metadata']['liquidity_provider'] = $lp;
        }
        $webhookUrlOverride = trim(
            (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_URL'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_URL') ?: '')
        );
        if ($webhookUrlOverride !== '') {
            $body['webhookUrl'] = $webhookUrlOverride;
        } elseif ($publicBase !== '') {
            $path = trim(
                (string) ($_ENV['SENDRA_CENTIIV_WEBHOOK_PATH'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_PATH') ?: 'CentiivWebhook.php'),
                '/'
            );
            $body['webhookUrl'] = rtrim($publicBase, '/') . '/' . $path;
        }

        $body = \App\Services\SendraCentiivRampService::enrichOfframpOrderBody($body);
        $payoutRefDirect = '';
        if (is_array($body['metadata'] ?? null)) {
            $payoutRefDirect = trim((string) ($body['metadata']['sendra_payout_reference'] ?? $body['metadata']['trackingId'] ?? ''));
        }

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $this->formatCentiivConversionOrderError($created['error'] ?? 'Order failed');
            error_log("Centiiv create order failed {$from}: {$err} " . json_encode($created['data']));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not open a conversion order. Please try again or contact support.\n({$err})"
            );

            return ['success' => false];
        }

        $data = $created['data'];
        $deposit = \App\Services\SendraCentiivRampService::extractAptosDepositAddress($data);
        $orderId = \App\Services\SendraCentiivRampService::extractOrderId($data);

        if ($deposit === null || $deposit === '') {
            error_log('Centiiv create order: no Aptos deposit address in payload for ' . $from . ' raw=' . json_encode($data));
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Something went wrong reading your deposit details.\nContact support@usesendra.com with your phone number."
            );
            $this->clearSellChatContext($from);

            return ['success' => false];
        }

        $pendingId = $this->storePendingTransaction([
            'user_phone' => $from,
            'tx_type' => 'offramp_centiiv',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => $deposit,
            'human_description' => json_encode([
                'kind' => 'centiiv_offramp',
                'order_id' => $orderId,
                'centiiv_order_id' => $orderId,
                'sendra_payout_reference' => $payoutRefDirect !== '' ? $payoutRefDirect : null,
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $ctx['bank_name'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->clearSellChatContext($from);

        $recvNgn = (string) ($ctx['receive_ngn_display'] ?? '—');
        $this->whatsappService->sendButtonMessage(
            $from,
            "🔐 *Enter your 6-digit PIN*\n\n" .
            "*≈ ₦{$recvNgn}* payout to:\n*{$name}*\n`{$acct}` · *{$bankDisplay}*\n\n" .
            "Wallet debit: *{$amountStr} {$asset}*\n\n" .
            "Deposit:\n`{$deposit}`\n\n" .
            "Reply with your *PIN* to continue.",
            ['cancel_' . $pendingId => 'Cancel']
        );

        return ['success' => true, 'action' => 'sell_pin_queued'];
    }

    /**
     * Panora USDT→USDC (credited to user wallet), then 1Click USDC→Stellar for Centiiv order.
     *
     * @param array<string, mixed> $pending
     */
    private function executeCentiivPanoraOneClickOfframpTransfer($from, $user, $pending)
    {
        $meta = json_decode($pending['human_description'] ?? '{}', true) ?: [];
        $orderStellar = (string) ($meta['centiiv_stellar_payin'] ?? '');
        $centiivUsdcOrder = (float) ($meta['centiiv_usdc_amount'] ?? 0);
        $usdtAmount = (string) ($pending['amount'] ?? '');

        $this->whatsappService->sendMessage($from, "⏳ Converting *{$usdtAmount} USDT* to USDC in your wallet…");

        $panoraToUsdc = (float) ($meta['panora_to_token_amount'] ?? $meta['panora_est_usdc'] ?? 0);
        $panoraPayload = [
            'sender_address' => $user['aptos_address'],
            'encrypted_private_key' => $user['encrypted_private_key'],
        ];
        if ($panoraToUsdc > 0) {
            $panoraPayload['to_token_amount'] = \App\Services\SendraCentiivRampService::formatUsdcAmountForApi($panoraToUsdc);
        } else {
            $panoraPayload['from_token_amount'] = $usdtAmount;
        }

        $chPanora = curl_init('http://127.0.0.1:3001/transactions/panora-swap');
        curl_setopt_array($chPanora, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($panoraPayload),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $panoraRaw = curl_exec($chPanora);
        $panoraCurlErr = curl_errno($chPanora) ? curl_error($chPanora) : null;
        curl_close($chPanora);
        $panoraResult = json_decode((string) $panoraRaw, true);

        if ($panoraCurlErr || empty($panoraResult['success'])) {
            $err = is_array($panoraResult) ? ($panoraResult['error'] ?? 'Panora swap failed') : ($panoraCurlErr ?: 'Service error');
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            error_log("Panora swap failed {$from}: {$err} raw={$panoraRaw}");
            $this->whatsappService->sendMessage(
                $from,
                "❌ We couldn't start your payout.\n\nYour wallet balance is unchanged. Please try again in a few minutes."
            );

            return ['success' => false];
        }

        $panoraHash = (string) ($panoraResult['tx_hash'] ?? '');
        $panoraExplorer = $this->aptosExplorerUrlForTransaction(is_array($panoraResult) ? $panoraResult : [], $panoraHash);
        $this->recordTransaction([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $usdtAmount,
            'currency' => 'USDT',
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => 'panora_swap',
            'tx_hash' => $panoraHash,
            'explorer_url' => $panoraExplorer,
        ]);

        $panoraOut = is_array($panoraResult['panora'] ?? null) ? $panoraResult['panora'] : [];
        $usdcToSend = \App\Services\SendraPanoraService::usdcOutFromAptosPanoraSwapResponse(
            $panoraOut,
            $panoraToUsdc > 0 ? $panoraToUsdc : null
        );
        if ($usdcToSend <= 0) {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            error_log(
                'Panora swap ok but no USDC out parsed wa=' . $from
                . ' panora=' . json_encode($panoraOut)
                . ' requested_to=' . json_encode($panoraToUsdc)
            );
            $this->whatsappService->sendMessage(
                $from,
                "❌ We couldn't confirm the converted amount.\n\nPlease check your wallet balance or contact support@usesendra.com"
            );

            return ['success' => false];
        }

        $slippageBps = (int) ($_ENV['SENDRA_PANORA_CENTIIV_SLIPPAGE_BPS'] ?? getenv('SENDRA_PANORA_CENTIIV_SLIPPAGE_BPS') ?: 200);
        if ($slippageBps < 0 || $slippageBps > 5000) {
            $slippageBps = 200;
        }
        // centiiv_usdc_amount is haircut-adjusted (≤ wallet USDC); wallet out should meet or exceed it.
        if ($centiivUsdcOrder > 0) {
            $minOk = $centiivUsdcOrder * (10000 - $slippageBps) / 10000;
            if ($usdcToSend + 1e-9 < $minOk) {
                $this->updatePendingTransactionStatus($pending['id'], 'failed');
                $this->userModel->clearPendingTransaction($from);
                $usdcFmt = rtrim(rtrim(number_format($usdcToSend, 6, '.', ''), '0'), '.');
                $this->whatsappService->sendMessage(
                    $from,
                    "⚠️ The converted amount was lower than expected (*{$usdcFmt} USDC*).\n\n" .
                    "Your payout was *not* started. Try again using *USDC*, or contact support@usesendra.com"
                );

                return ['success' => false];
            }
        }

        if ($orderStellar === '' || !\App\Services\SendraCentiivRampService::looksLikeStellarAccountId($orderStellar)) {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            $usdcFmt = rtrim(rtrim(number_format($usdcToSend, 6, '.', ''), '0'), '.');
            $this->whatsappService->sendMessage(
                $from,
                "✅ Conversion complete (*{$usdcFmt} USDC* in your wallet).\n\n" .
                "❌ Could not start your payout right now. Please contact support@usesendra.com"
            );

            return ['success' => false];
        }

        $this->whatsappService->sendMessage($from, '⏳ Sending USDC to the payout rail…');

        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
        $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $usdcStr = rtrim(rtrim(number_format($usdcToSend, 6, '.', ''), '0'), '.');
        if ($usdcStr === '') {
            $usdcStr = (string) $usdcToSend;
        }
        $usdcFmt = $usdcStr;
        $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($usdcStr, 6);
        $oneClick = new \App\Services\SendraOneClickService();
        $quoteBodyLive = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdc,
            $destStellarUsdc,
            $amountSmallest,
            $orderStellar,
            $refundAptos,
            $deadlineIso,
            false
        );
        $quoteRes = $oneClick->requestQuote($quoteBodyLive);
        if (!$quoteRes['success']) {
            $err = $quoteRes['error'] ?? '1Click live quote failed';
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            error_log("Panora path 1Click live quote failed {$from}: {$err}");
            $this->whatsappService->sendMessage(
                $from,
                "✅ *{$usdcFmt} USDC* is in your Sendra wallet.\n\n" .
                "❌ Could not start your payout right now. Try again shortly using *USDC*."
            );

            return ['success' => false];
        }

        $parsed = \App\Services\SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        if ($parsed['deposit_address'] === null || $parsed['correlation_id'] === null) {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            $this->whatsappService->sendMessage(
                $from,
                "✅ *{$usdcFmt} USDC* is in your wallet.\n\n❌ Bridge quote was incomplete. Try *sell* with *USDC*."
            );

            return ['success' => false];
        }

        $depositMemo = isset($parsed['deposit_memo']) && is_string($parsed['deposit_memo']) && $parsed['deposit_memo'] !== ''
            ? $parsed['deposit_memo'] : null;
        $correlation = (string) $parsed['correlation_id'];

        $this->mergePendingTransactionHumanDescription($pending['id'], [
            'panora_swap_tx_hash' => $panoraHash,
            'panora_usdc_out' => $usdcToSend,
            'oneclick_correlation_id' => $correlation,
            'oneclick_deposit_memo' => $depositMemo,
        ]);

        $ch = curl_init('http://127.0.0.1:3001/transactions/execute-gasless');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'recipient' => $parsed['deposit_address'],
                'amount' => $usdcStr,
                'currency' => 'USDC',
            ]),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $rawResponse = curl_exec($ch);
        curl_close($ch);
        $result = json_decode((string) $rawResponse, true);

        $this->userModel->clearPendingTransaction($from);

        if (empty($result['success'])) {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $err = is_array($result) ? ($result['error'] ?? 'Unknown error') : 'Service error';
            error_log("Panora path USDC→1Click send failed {$from}: {$err} raw={$rawResponse}");
            $this->whatsappService->sendMessage(
                $from,
                "✅ Conversion complete.\n\n❌ Could not start your payout right now.\n\n" .
                "*{$usdcFmt} USDC* remains in your wallet — try again using *USDC*."
            );

            return ['success' => false];
        }

        $hash = (string) $result['tx_hash'];
        $explorerUrl = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);
        $this->updatePendingTransactionStatus($pending['id'], 'confirmed', $hash);
        $this->recordTransaction([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $usdcStr,
            'currency' => 'USDC',
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => $parsed['deposit_address'],
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $this->mergePendingTransactionHumanDescription($pending['id'], [
            'aptos_funding_tx_hash' => $hash,
            'need_oneclick_poll' => true,
        ]);

        $submit = $oneClick->submitDeposit($parsed['deposit_address'], $hash, $depositMemo);
        if (!$submit['success']) {
            error_log('1Click deposit/submit after Panora (non-fatal): ' . ($submit['error'] ?? ''));
        }

        $pollIters = (int) ($_ENV['SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS'] ?? getenv('SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS') ?: 4);
        if ($pollIters < 1) {
            $pollIters = 1;
        }
        if ($pollIters > 12) {
            $pollIters = 12;
        }
        $swapDone = false;
        for ($i = 0; $i < $pollIters; $i++) {
            if ($i > 0) {
                sleep(2);
            }
            $st = $oneClick->getStatus($parsed['deposit_address'], $depositMemo);
            if (!$st['success']) {
                break;
            }
            $code = \App\Services\SendraOneClickService::parseStatus($st['data']);
            if ($code === 'SUCCESS') {
                $swapDone = true;
                break;
            }
            if (in_array($code, ['FAILED', 'REFUNDED'], true)) {
                break;
            }
        }

        $ngn = $meta['receive_ngn_display'] ?? '—';
        $acct = $meta['account_name'] ?? '';
        if ($swapDone) {
            $this->mergePendingTransactionHumanDescription($pending['id'], [
                'need_oneclick_poll' => false,
                'oneclick_swap_status' => 'SUCCESS',
            ]);
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Sent* — swap complete\n\n" .
                "USDT→USDC: {$panoraExplorer}\n" .
                "USDC bridge: {$explorerUrl}\n\n" .
                "Your *≈ ₦{$ngn}* payout to *{$acct}* is processing."
            );
        } else {
            $this->whatsappService->sendMessage(
                $from,
                "✅ *USDT converted & USDC sent*\n\n" .
                "USDT→USDC: {$panoraExplorer}\n" .
                "Bridge: {$explorerUrl}\n\n" .
                "⏳ Finalizing exchange (usually a few minutes). Your *≈ ₦{$ngn}* payout starts right after."
            );
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function executeCentiivOneClickOfframpTransfer($from, $user, $pending)
    {
        $this->oneClickOfframpLog('execute_begin', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'tx_type' => (string) ($pending['tx_type'] ?? ''),
            'amount' => (string) ($pending['amount'] ?? ''),
            'currency' => (string) ($pending['currency'] ?? ''),
            'oneclick_deposit_aptos' => (string) ($pending['recipient_address'] ?? ''),
            'sender_aptos' => (string) ($user['aptos_address'] ?? ''),
        ]);
        $this->whatsappService->sendMessage($from, "⏳ Sending {$pending['amount']} {$pending['currency']} from your wallet…");

        $ch = curl_init('http://127.0.0.1:3001/transactions/execute-gasless');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'recipient' => $pending['recipient_address'],
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
            ]),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $rawResponse = curl_exec($ch);
        $curlNo = (int) curl_errno($ch);
        $curlErr = $curlNo ? curl_error($ch) : null;
        $httpAptos = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode((string) $rawResponse, true);
        if ($curlNo) {
            $this->oneClickOfframpLog('aptos_curl_error', $from, [
                'pending_id' => (string) ($pending['id'] ?? ''),
                'http' => $httpAptos,
                'curl_errno' => $curlNo,
                'curl_error' => $curlErr,
            ]);
            error_log('aptos-service oneclick offramp curl: ' . (string) $curlErr);
        }

        $this->userModel->clearPendingTransaction($from);

        $meta = json_decode($pending['human_description'] ?? '{}', true) ?: [];
        $correlation = (string) ($meta['oneclick_correlation_id'] ?? '');
        $depositMemo = isset($meta['oneclick_deposit_memo']) && is_string($meta['oneclick_deposit_memo']) && $meta['oneclick_deposit_memo'] !== ''
            ? $meta['oneclick_deposit_memo'] : null;
        $this->oneClickOfframpLog('context_after_aptos_call', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'oneclick_correlation' => $correlation,
            'oneclick_memo_set' => $depositMemo !== null,
            'centiiv_order_id' => $meta['centiiv_order_id'] ?? null,
            'centiiv_order_id_hex' => $meta['centiiv_order_id_hex'] ?? null,
            'aptos_service_http' => $httpAptos,
        ]);

        if (empty($result['success'])) {
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $err = is_array($result) ? ($result['error'] ?? 'Unknown error') : 'Service error';
            $this->oneClickOfframpLog('aptos_execute_failed', $from, [
                'pending_id' => (string) ($pending['id'] ?? ''),
                'error' => (string) $err,
                'http' => $httpAptos,
                'result_preview' => is_string($rawResponse) ? (strlen($rawResponse) > 1200 ? (substr($rawResponse, 0, 1200) . '…') : $rawResponse) : null,
            ]);
            error_log("1Click offramp aptos send failed {$from}: {$err} raw={$rawResponse}");
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not start your payout right now.\n\nYour wallet balance is unchanged. Please try again in a few minutes."
            );

            return ['success' => false];
        }

        $hash = (string) $result['tx_hash'];
        $explorerUrl = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);
        $this->oneClickOfframpLog('aptos_tx_confirmed', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
            'oneclick_correlation' => $correlation,
            'http' => $httpAptos,
        ]);
        $this->updatePendingTransactionStatus($pending['id'], 'confirmed', $hash);
        $this->recordTransaction([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $pending['amount'],
            'currency' => $pending['currency'],
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => $pending['recipient_address'],
            'tx_hash' => $hash,
            'explorer_url' => $explorerUrl,
        ]);

        $this->mergePendingTransactionHumanDescription($pending['id'], [
            'aptos_funding_tx_hash' => $hash,
            'need_oneclick_poll' => true,
        ]);

        $oneClick = new \App\Services\SendraOneClickService();
        $submit = $oneClick->submitDeposit($pending['recipient_address'], $hash, $depositMemo);
        $this->oneClickOfframpLog('deposit_submit_result', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'ok' => (bool) ($submit['success'] ?? false),
            'http' => (int) ($submit['http_code'] ?? 0),
            'error' => (string) ($submit['error'] ?? ''),
        ]);
        if (!$submit['success']) {
            error_log('1Click deposit/submit (non-fatal): ' . ($submit['error'] ?? '') . ' ' . json_encode($submit['data']));
        }

        $pollIters = (int) ($_ENV['SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS'] ?? getenv('SENDRA_ONECLICK_POST_SEND_POLL_ITERATIONS') ?: 4);
        if ($pollIters < 1) {
            $pollIters = 1;
        }
        if ($pollIters > 12) {
            $pollIters = 12;
        }
        $swapDone = false;
        for ($i = 0; $i < $pollIters; $i++) {
            if ($i > 0) {
                sleep(2);
            }
            $st = $oneClick->getStatus($pending['recipient_address'], $depositMemo);
            if (!$st['success']) {
                $this->oneClickOfframpLog('status_poll_stopped', $from, [
                    'pending_id' => (string) ($pending['id'] ?? ''),
                    'iteration' => $i,
                    'error' => (string) ($st['error'] ?? ''),
                    'http' => (int) ($st['http_code'] ?? 0),
                ]);
                break;
            }
            $code = \App\Services\SendraOneClickService::parseStatus($st['data']);
            $stSummary = is_array($st['data'] ?? null) ? (strlen($sj = (string) json_encode($st['data'], JSON_UNESCAPED_UNICODE)) > 1800 ? substr($sj, 0, 1800) . '…' : $sj) : '';
            $this->oneClickOfframpLog('status_poll', $from, [
                'pending_id' => (string) ($pending['id'] ?? ''),
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
                $this->oneClickOfframpLog('oneclick_swap_terminal', $from, [
                    'pending_id' => (string) ($pending['id'] ?? ''),
                    'code' => $code,
                    'oneclick_correlation' => $correlation,
                ]);
                error_log("1Click swap terminal {$code} for {$from} correlation={$correlation}");
                break;
            }
        }
        $this->oneClickOfframpLog('post_poll_summary', $from, [
            'pending_id' => (string) ($pending['id'] ?? ''),
            'swap_done' => $swapDone,
            'oneclick_correlation' => $correlation,
        ]);

        $ngn = $meta['receive_ngn_display'] ?? '—';
        $acct = $meta['account_name'] ?? '';
        $centiivOrder = isset($meta['centiiv_order_id']) && is_string($meta['centiiv_order_id']) && $meta['centiiv_order_id'] !== ''
            ? $meta['centiiv_order_id'] : null;
        $centiivHex = isset($meta['centiiv_order_id_hex']) && is_string($meta['centiiv_order_id_hex']) && $meta['centiiv_order_id_hex'] !== ''
            ? $meta['centiiv_order_id_hex'] : null;
        if ($centiivOrder !== null) {
            error_log("Sendra sell offramp context {$from} order={$centiivOrder}" . ($centiivHex !== null ? " hex={$centiivHex}" : '') . " correlation={$correlation}");
        }

        if ($swapDone) {
            $this->mergePendingTransactionHumanDescription($pending['id'], [
                'need_oneclick_poll' => false,
                'oneclick_swap_status' => 'SUCCESS',
                'oneclick_bridge_user_notified' => true,
            ]);
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Sent* — swap complete\n\n" .
                "Explorer: {$explorerUrl}\n\n" .
                "Your *≈ ₦{$ngn}* payout to *{$acct}* is processing. We’ll update you when the bank transfer finishes."
            );
        } else {
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Sent from wallet*\n\n" .
                "Explorer: {$explorerUrl}\n\n" .
                "⏳ Finalizing exchange (usually a few minutes). Your *≈ ₦{$ngn}* payout starts right after."
            );
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function executeCentiivOfframpTransfer($from, $user, $pending)
    {
        $this->whatsappService->sendMessage($from, "⏳ Sending {$pending['amount']} {$pending['currency']}…");

        $ch = curl_init('http://127.0.0.1:3001/transactions/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'recipient' => $pending['recipient_address'],
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
            ]),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $rawResponse = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('aptos-service offramp curl: ' . curl_error($ch));
        }
        curl_close($ch);
        $result = json_decode((string) $rawResponse, true);

        $this->userModel->clearPendingTransaction($from);

        if (!empty($result['success'])) {
            $hash = (string) $result['tx_hash'];
            $explorerUrl = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);
            $this->updatePendingTransactionStatus($pending['id'], 'confirmed', $hash);
            $meta = json_decode($pending['human_description'] ?? '{}', true) ?: [];
            $this->recordTransaction([
                'user_phone' => $from,
                'direction' => 'out',
                'amount' => $pending['amount'],
                'currency' => $pending['currency'],
                'status' => 'confirmed',
                'aptos_address' => $user['aptos_address'],
                'counterparty' => $pending['recipient_address'],
                'tx_hash' => $hash,
                'explorer_url' => $explorerUrl,
            ]);

            $ngn = $meta['receive_ngn_display'] ?? '—';
            $acct = $meta['account_name'] ?? '';
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Crypto sent*\n\n" .
                "Sent: *{$pending['amount']} {$pending['currency']}*\n" .
                "Explorer: {$explorerUrl}\n\n" .
                "⏳ Your bank payout (*≈ ₦{$ngn}* to *{$acct}*) will complete after deposit confirmation.\n" .
                "We’ll message you when it’s done."
            );

            return ['success' => true];
        }

        $this->updatePendingTransactionStatus($pending['id'], 'failed');
        $err = is_array($result) ? ($result['error'] ?? 'Unknown error') : 'Service error';
        error_log("Centiiv offramp chain send failed {$from}: {$err} raw={$rawResponse}");
        $this->whatsappService->sendMessage(
            $from,
            "❌ Could not send crypto: {$err}\n\nYour balance is unchanged. Try again or contact support."
        );

        return ['success' => false];
    }

    /**
     * @param array<string, mixed> $vdata
     */
    private function centiivPickAccountName(array $vdata): ?string
    {
        $d = $vdata['data'] ?? $vdata;
        if (!is_array($d)) {
            $d = $vdata;
        }
        foreach (['accountName', 'account_name', 'accountHolderName', 'name'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) {
                return $d[$k];
            }
        }

        return null;
    }

    /**
     * @param list<array{code: string, name: string}> $banks
     */
    private function centiivPickBankLabel(array $vdata, array $banks, string $code, string $fallbackGuess): string
    {
        $d = $vdata['data'] ?? $vdata;
        if (is_array($d)) {
            foreach (['bankName', 'bank_name'] as $k) {
                if (!empty($d[$k]) && is_string($d[$k])) {
                    return $d[$k];
                }
            }
            if (isset($d['bank']) && is_string($d['bank']) && $d['bank'] !== '') {
                return $d['bank'];
            }
            if (isset($d['bank']) && is_array($d['bank'])) {
                $bk = $d['bank'];
                foreach (['bankName', 'name', 'longName', 'shortName'] as $k) {
                    if (!empty($bk[$k]) && is_string($bk[$k])) {
                        return $bk[$k];
                    }
                }
            }
        }
        foreach ($banks as $b) {
            if ($b['code'] === $code) {
                return $b['name'];
            }
        }

        return $fallbackGuess;
    }

    /**
     * 1Click offramp + Centiiv prepare → see php-debug.log when error_log and SENDRA_PHP_ERROR_LOG point to it. Disable: SENDRA_1CLICK_TRACE=0.
     *
     * @param array<string, mixed> $context
     */
    private function oneClickOfframpLog(string $step, string $from, array $context = []): void
    {
        if (!$this->oneClickOfframpTraceEnabled()) {
            return;
        }
        $context['step'] = $step;
        $context['user'] = $from;
        $context['ts'] = date('c');
        error_log('[Sendra 1Click offramp] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function oneClickOfframpTraceEnabled(): bool
    {
        $v = strtolower(trim((string) ($_ENV['SENDRA_1CLICK_TRACE'] ?? getenv('SENDRA_1CLICK_TRACE') ?: '')));
        if ($v === '') {
            return true;
        }

        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Must match aptos-service INTERNAL_API_SECRET (trimmed to survive stray newlines in .env).
     *
     * @return list<string>
     */
    /**
     * Framework transfer (sender pays APT). Matches aptos-service: mainnet defaults to gasless.
     */
    private function aptosUsesFrameworkTransfer(): bool
    {
        $explicit = strtolower(trim((string) ($_ENV['APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER'] ?? getenv('APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER') ?: '')));
        if ($explicit !== '') {
            return in_array($explicit, ['1', 'true', 'yes', 'on'], true);
        }
        $net = strtolower(trim((string) (
            $_ENV['APTOS_EXPLORER_NETWORK']
            ?? getenv('APTOS_EXPLORER_NETWORK')
            ?: $_ENV['NODIT_APTOS_NETWORK']
            ?? getenv('NODIT_APTOS_NETWORK')
            ?: 'mainnet'
        )));

        return $net !== 'mainnet';
    }

    /**
     * WhatsApp PIN sends: SmoothSend execute-gasless when METHOD2 is on and framework transfer is off.
     */
    private function aptosSendUsesGaslessMethod2(): bool
    {
        $method2 = strtolower(trim((string) ($_ENV['APTOS_SMOOTHSEND_METHOD2'] ?? getenv('APTOS_SMOOTHSEND_METHOD2') ?: '0')));
        if (!in_array($method2, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        return !$this->aptosUsesFrameworkTransfer();
    }

    private function aptosServiceAuthHeaders(): array
    {
        $secret = trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));

        return [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ];
    }

    /**
     * Executes an arbitrary entry-function call via aptos-service SmoothSend gasless (Method 2).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function aptosServiceExecuteGasless(array $payload): array
    {
        $ch = curl_init('http://127.0.0.1:3001/transactions/execute-gasless');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $curlError = null;
        $rawResponse = curl_exec($ch);
        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            error_log("aptos-service execute-gasless curl error: $curlError");
        }
        curl_close($ch);

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error'   => $curlError ? 'Service unreachable' : 'Invalid response from aptos-service',
                'raw'     => (string) $rawResponse,
            ];
        }
        return $decoded;
    }

    /**
     * Prefer `explorer_url` from aptos-service POST /transactions/execute (same network the tx was built on).
     * Fallback: APTOS_EXPLORER_NETWORK (default mainnet) — set to testnet for testnet users.
     *
     * @param array<string, mixed> $executeResult
     */
    private function aptosExplorerUrlForTransaction(array $executeResult, string $txHash): string
    {
        $u = $executeResult['explorer_url'] ?? null;
        if (is_string($u) && $u !== '') {
            return $u;
        }

        return $this->aptosExplorerTxnUrl($txHash);
    }

    private function aptosExplorerTxnUrl(string $txHash): string
    {
        $net = strtoupper(trim((string) ($_ENV['APTOS_EXPLORER_NETWORK'] ?? getenv('APTOS_EXPLORER_NETWORK') ?: 'MAINNET')));
        $base = 'https://explorer.aptoslabs.com/txn/' . ltrim($txHash, " \t\n\r\0\x0B");
        if ($net === 'MAINNET' || $net === '') {
            return $base;
        }
        if ($net === 'TESTNET') {
            return $base . '?network=testnet';
        }
        if ($net === 'DEVNET') {
            return $base . '?network=devnet';
        }

        return $base;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUY (NGN → Aptos USDT/USDC via Centiiv + 1Click)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return ?array<string, mixed> non-null = handled
     */
    private function handleBuyFlowMessage($from, $message, $messageLower, $user)
    {
        $userRow = $this->userModel->findByPhone($from) ?: $user;
        $ctx = $this->getBuyChatContext($userRow);
        if ($ctx === null) {
            return null;
        }

        if ($messageLower === 'cancel') {
            $this->clearBuyChatContext($from);
            $this->whatsappService->sendMessage($from, "Cancelled. Type *menu* for options.");

            return ['success' => true, 'action' => 'buy_cancelled'];
        }

        if ($messageLower === 'menu' || $messageLower === '/menu') {
            $this->clearBuyChatContext($from);

            return $this->handleGreeting($from, $this->userModel->findByPhone($from) ?: $userRow);
        }

        $buySvc = new \App\Services\SendraBuyRampService();
        if (!$buySvc->isCentiivConfigured()) {
            $this->clearBuyChatContext($from);
            $this->whatsappService->sendMessage($from, "❌ Buy is temporarily unavailable. Try again later.");

            return ['success' => false, 'action' => 'buy_unconfigured'];
        }

        $step = $ctx['step'] ?? '';

        if ($step === 'await_amount') {
            $amount = $this->buyParseNairaAmount($message);
            if ($amount === null) {
                $this->whatsappService->sendMessage(
                    $from,
                    "How much Naira do you want to spend?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
                );

                return ['success' => false, 'action' => 'buy_amount_reprompt'];
            }

            $ctx['step'] = 'await_asset';
            $ctx['pay_ngn'] = $amount;
            $this->setBuyChatContext($from, $ctx);
            $this->whatsappService->sendButtonMessage(
                $from,
                "You’ll pay *≈ ₦" . number_format($amount, 0, '.', ',') . "*.\nWhich token should land in your wallet?",
                [
                    'buy_pick_usdt' => 'USDT',
                    'buy_pick_usdc' => 'USDC',
                ]
            );

            return ['success' => true, 'action' => 'buy_pick_asset'];
        }

        if ($step === 'await_asset') {
            $asset = null;
            if ($messageLower === 'buy_pick_usdt' || $messageLower === 'usdt') {
                $asset = 'USDT';
            } elseif ($messageLower === 'buy_pick_usdc' || $messageLower === 'usdc') {
                $asset = 'USDC';
            }
            if ($asset === null) {
                $this->whatsappService->sendMessage($from, "Tap *USDT* or *USDC*, or type the name.\n*cancel* stops.");

                return ['success' => false, 'action' => 'buy_asset_reprompt'];
            }

            return $this->buyApplyQuoteAndPrompt($from, $userRow, (float) ($ctx['pay_ngn'] ?? 0), $asset, $buySvc);
        }

        if ($step === 'await_quote_confirm') {
            if ($this->buyMessageMeansYes($message, $messageLower)) {
                return $this->buyCreateOrderAndSendBankDetails($from, $userRow, $ctx, $buySvc);
            }
            if ($messageLower === 'buy_quote_change') {
                $this->setBuyChatContext($from, ['flow' => 'buy', 'step' => 'await_amount', 'fiat' => 'NGN']);
                $this->whatsappService->sendMessage($from, "How much Naira do you want to spend? (e.g. *5000*)");

                return ['success' => true, 'action' => 'buy_change_amount'];
            }
            $this->whatsappService->sendMessage($from, "Reply *yes* to get bank details, or tap *Different amount*.\n*cancel* stops.");

            return ['success' => false, 'action' => 'buy_quote_reprompt'];
        }

        return null;
    }

    private function handleBuyEntry($from, $message, $messageLower, $user)
    {
        if (!$this->buyPrerequisitesOk($from, $user)) {
            return ['success' => false, 'action' => 'buy_blocked'];
        }

        $buySvc = new \App\Services\SendraBuyRampService();
        if (!$buySvc->isCentiivConfigured()) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Buy isn’t available right now. Try again later or email support@usesendra.com"
            );

            return ['success' => false, 'action' => 'buy_unconfigured'];
        }

        if ($messageLower === 'buy_fiat_ngn' || $messageLower === 'buy') {
            $this->setBuyChatContext($from, ['flow' => 'buy', 'step' => 'await_amount', 'fiat' => 'NGN']);
            $this->whatsappService->sendMessage(
                $from,
                "Buy USDT or USDC with Naira.\n\n" .
                "• Naira amount: *5000* or *10000 naira*\n" .
                "• Crypto amount: *5 USDC* or *10 USDT*\n\n" .
                "*cancel* stops."
            );

            return ['success' => true, 'action' => 'buy_ngn_amount'];
        }

        if (preg_match('/^buy\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', trim($message), $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    $this->setBuyChatContext($from, [
                        'flow' => 'buy',
                        'step' => 'await_asset',
                        'fiat' => 'NGN',
                        'pay_ngn' => $amt,
                    ]);
                    $this->whatsappService->sendButtonMessage(
                        $from,
                        "You’ll pay *≈ ₦" . number_format($amt, 0, '.', ',') . "*.\nWhich token should land in your wallet?",
                        [
                            'buy_pick_usdt' => 'USDT',
                            'buy_pick_usdc' => 'USDC',
                        ]
                    );

                    return ['success' => true, 'action' => 'buy_pick_asset'];
                }
            }
        }

        if (preg_match('/^buy\s+(\d+(?:\.\d+)?)\s*(usdc|usdt)\b/i', trim($message), $m)) {
            $cryptoAmt = (float) $m[1];
            $asset = strtoupper($m[2]);
            if ($cryptoAmt >= 0.01) {
                return $this->buyApplyCryptoTargetQuote($from, $user, $cryptoAmt, $asset, $buySvc);
            }
        }

        $this->whatsappService->sendMessage(
            $from,
            "Try:\n• *buy 5000* (spend Naira)\n• *buy 5 USDC* (receive crypto)\n• *buy* then enter an amount"
        );

        return ['success' => false, 'action' => 'buy_usage'];
    }

    private function buyPrerequisitesOk($from, $user): bool
    {
        if (empty($user['aptos_address'])) {
            $this->whatsappService->sendMessage($from, "❌ No wallet on file. Type *hi* to get started.");

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getBuyChatContext($userRow): ?array
    {
        $raw = $userRow['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || ($raw['flow'] ?? '') !== 'buy') {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function setBuyChatContext($from, array $ctx): void
    {
        $this->userModel->update($from, ['chat_context' => json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
    }

    private function clearBuyChatContext($from): void
    {
        $user = $this->userModel->findByPhone($from);
        if ($user === null) {
            return;
        }
        $raw = $user['chat_context'] ?? null;
        if ($raw === null || $raw === '') {
            return;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw) && ($raw['flow'] ?? '') === 'buy') {
            $this->userModel->update($from, ['chat_context' => null]);
        }
    }

    private function buyMessageMeansYes(string $message, string $messageLower): bool
    {
        if (in_array($messageLower, ['buy_quote_yes', 'yes', 'y'], true)) {
            return true;
        }
        $stripped = trim((string) preg_replace('/^[\s\p{So}\p{Sk}]+/u', '', $messageLower));

        return in_array($stripped, ['yes', 'y', 'buy_quote_yes'], true);
    }

    private function buyParseNairaAmount(string $message): ?float
    {
        $t = trim($message);
        if (preg_match('/^([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    return $amt;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyApplyCryptoTargetQuote($from, $userRow, float $targetCrypto, string $asset, \App\Services\SendraBuyRampService $buySvc)
    {
        $this->whatsappService->sendMessage($from, '⏳ Getting a rate…');
        $quote = $buySvc->quoteCryptoBuy($targetCrypto, $asset, (string) ($userRow['aptos_address'] ?? ''));
        if (!$quote['success']) {
            $err = (string) ($quote['error'] ?? 'quote_failed');
            error_log("Sendra buy crypto quote failed {$from}: {$err}");
            $body = "❌ No buy rate available for *{$targetCrypto} {$asset}* right now.";
            if (preg_match('/no liquidity|no rail|no route|liquidity provider/i', $err)) {
                $body .= "\n\nThe NGN→crypto corridor may be temporarily down. Try again later.";
            } elseif ($err === 'min_ngn') {
                $body .= "\n\nTry a larger amount (minimum spend is about *₦100*).";
            } else {
                $body .= "\n\nTry again shortly.";
            }
            $this->whatsappService->sendMessage($from, $body);

            return ['success' => false];
        }

        $payNgn = (float) ($quote['pay_ngn'] ?? 0);
        if ($payNgn < 100) {
            $this->whatsappService->sendMessage($from, "❌ Amount too small. Try at least *0.01 {$asset}* or use *buy 1000*.");

            return ['success' => false];
        }

        $feeLine = !empty($quote['fee_hint']) ? ("\n" . $quote['fee_hint']) : '';
        $rateLine = !empty($quote['rate_hint']) ? ("\n" . $quote['rate_hint']) : '';
        $bridgeNote = $buySvc->isOneClickBridgeConfigured()
            ? "\n\nFunds are routed via our payout network to your Aptos wallet."
            : '';

        $targetDisplay = rtrim(rtrim(number_format($targetCrypto, 6, '.', ''), '0'), '.');
        $ctx = [
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'fiat' => 'NGN',
            'pay_ngn' => $payNgn,
            'asset' => $asset,
            'target_crypto' => $targetCrypto,
            'receive_crypto_display' => $quote['receive_crypto_display'],
            'centiiv_usdc' => $quote['centiiv_usdc'],
            'oneclick_dry_quote_data' => $quote['oneclick_dry_quote_data'],
        ];
        $this->setBuyChatContext($from, $ctx);

        $payDisplay = number_format($payNgn, 0, '.', ',');
        $this->whatsappService->sendButtonMessage(
            $from,
            "🛒 *Buy *{$targetDisplay} {$asset}*\n\n" .
            "Pay *≈ ₦{$payDisplay}* to receive *≈ {$quote['receive_crypto_display']} {$asset}* (indicative).{$feeLine}{$rateLine}{$bridgeNote}\n\n" .
            "Continue to get a *virtual bank account*?",
            [
                'buy_quote_yes' => '✅ Yes',
                'buy_quote_change' => '✏️ Different amount',
            ]
        );

        return ['success' => true, 'action' => 'buy_crypto_quote'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyApplyQuoteAndPrompt($from, $userRow, float $payNgn, string $asset, \App\Services\SendraBuyRampService $buySvc)
    {
        $this->whatsappService->sendMessage($from, '⏳ Getting a rate…');
        $quote = $buySvc->quoteNairaBuy($payNgn, $asset, (string) ($userRow['aptos_address'] ?? ''));
        if (!$quote['success']) {
            $err = (string) ($quote['error'] ?? 'quote_failed');
            error_log("Sendra buy quote failed {$from}: {$err}");
            $body = "❌ No buy rate available right now.";
            if (preg_match('/no liquidity|no rail|no route|liquidity provider/i', $err)) {
                $body .= "\n\nThe NGN→crypto corridor may be temporarily down. Try again later.";
            } else {
                $body .= "\n\nTry again shortly.";
            }
            $this->whatsappService->sendMessage($from, $body);

            return ['success' => false];
        }

        $feeLine = !empty($quote['fee_hint']) ? ("\n" . $quote['fee_hint']) : '';
        $rateLine = !empty($quote['rate_hint']) ? ("\n" . $quote['rate_hint']) : '';
        $bridgeNote = $buySvc->isOneClickBridgeConfigured()
            ? "\n\nFunds are routed via our payout network to your Aptos wallet."
            : '';

        $ctx = [
            'flow' => 'buy',
            'step' => 'await_quote_confirm',
            'fiat' => 'NGN',
            'pay_ngn' => $payNgn,
            'asset' => $asset,
            'receive_crypto_display' => $quote['receive_crypto_display'],
            'centiiv_usdc' => $quote['centiiv_usdc'],
            'oneclick_dry_quote_data' => $quote['oneclick_dry_quote_data'],
        ];
        $this->setBuyChatContext($from, $ctx);

        $payDisplay = number_format($payNgn, 0, '.', ',');
        $this->whatsappService->sendButtonMessage(
            $from,
            "🛒 *Buy with ₦{$payDisplay}*\n\n" .
            "You receive *≈ {$quote['receive_crypto_display']} {$asset}* (indicative).{$feeLine}{$rateLine}{$bridgeNote}\n\n" .
            "Continue to get a *virtual bank account*?",
            [
                'buy_quote_yes' => '✅ Yes',
                'buy_quote_change' => '✏️ Different amount',
            ]
        );

        return ['success' => true, 'action' => 'buy_quote'];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function buyCreateOrderAndSendBankDetails($from, $userRow, array $ctx, \App\Services\SendraBuyRampService $buySvc)
    {
        $this->whatsappService->sendMessage($from, '⏳ Opening your payment account…');
        $created = $buySvc->createBuyOrder($from, (string) ($userRow['aptos_address'] ?? ''), $ctx);
        if (!$created['success']) {
            $err = (string) ($created['error'] ?? 'order_failed');
            error_log("Sendra buy create order failed {$from}: {$err}");
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not open a buy order.\n({$err})\n\nTry again or contact support@usesendra.com"
            );

            return ['success' => false];
        }

        $bank = $created['bank'];
        $meta = $created['meta'];
        $orderId = (string) ($created['centiiv_order_id'] ?? '');

        $this->storeOnrampOrder([
            'user_phone' => $from,
            'tx_type' => 'onramp_oneclick_centiiv',
            'amount' => (string) ($ctx['receive_crypto_display'] ?? ''),
            'currency' => (string) ($ctx['asset'] ?? 'USDC'),
            'recipient_address' => (string) ($meta['sendra_oneclick_deposit_stellar'] ?? $userRow['aptos_address'] ?? ''),
            'human_description' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->clearBuyChatContext($from);

        $payDisplay = number_format((float) ($ctx['pay_ngn'] ?? 0), 0, '.', ',');
        $recv = (string) ($ctx['receive_crypto_display'] ?? '—');
        $asset = (string) ($ctx['asset'] ?? 'USDC');

        if ($bank === null) {
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Buy order opened*\n\n" .
                "Order `{$orderId}`\n" .
                "Pay *₦{$payDisplay}* to fund it.\n" .
                "You should receive *≈ {$recv} {$asset}* in your wallet after payment.\n\n" .
                "Bank details were not returned by the provider — check WhatsApp again shortly or contact support@usesendra.com"
            );

            return ['success' => true, 'action' => 'buy_order_no_bank'];
        }

        $this->whatsappService->sendMessage(
            $from,
            "🏦 *Pay to complete your buy*\n\n" .
            "Transfer *exactly ₦{$payDisplay}* to:\n\n" .
            "*{$bank['account_name']}*\n" .
            "`{$bank['account_number']}`\n" .
            "*{$bank['bank_name']}*\n\n" .
            "Order: `{$orderId}`\n" .
            "You receive: *≈ {$recv} {$asset}* → your Sendra wallet\n\n" .
            "⏱ Complete payment within *30 minutes*. We’ll notify you when crypto lands."
        );

        return ['success' => true, 'action' => 'buy_bank_sent'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeOnrampOrder(array $data): string
    {
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("
            INSERT INTO pending_transactions
                (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
            VALUES
                (:id, :user_phone, :tx_type, :amount, :currency, :recipient_address, :human_description, :expires_at, 'awaiting_payment', NOW())
        ");
        $stmt->execute(array_merge(['id' => $id], $data));

        return $id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Panora in-wallet USDT ↔ USDC swap
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return ?array{amount: float, from_asset: string, to_asset: string, direction: string}
     */
    private function parseSwapIntent(string $message): ?array
    {
        $t = trim($message);
        if (!preg_match('/^(?:swap|convert)\s+/i', $t)) {
            return null;
        }
        $patterns = [
            '/^(?:swap|convert)\s+([\d,\s]+(?:\.\d+)?)\s+(usdt|usdc)\s+(?:to|for|into|→|->)\s+(usdt|usdc)\b/i',
            '/^(?:swap|convert)\s+([\d,\s]+(?:\.\d+)?)\s+(usdt|usdc)\s+(usdt|usdc)\b/i',
        ];
        foreach ($patterns as $pat) {
            if (!preg_match($pat, $t, $m)) {
                continue;
            }
            $rawAmt = str_replace([',', ' '], '', (string) $m[1]);
            if ($rawAmt === '' || !is_numeric($rawAmt)) {
                return null;
            }
            $amount = (float) $rawAmt;
            if ($amount < 0.01) {
                return null;
            }
            $from = strtoupper((string) $m[2]);
            $to = strtoupper((string) $m[3]);
            if ($from === $to) {
                return null;
            }
            $direction = \App\Services\SendraPanoraService::normalizeSwapDirection($from, $to);
            if ($direction === null) {
                return null;
            }

            return [
                'amount' => $amount,
                'from_asset' => $from,
                'to_asset' => $to,
                'direction' => $direction,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleSwapEntry($from, $message, $user)
    {
        if (!$this->panoraWalletSwapEnabled()) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Token swaps aren't available right now.\n\nAsk support to enable *SENDRA_PANORA_WALLET_SWAP*."
            );

            return ['success' => false, 'action' => 'swap_disabled'];
        }
        if (!$this->sellPrerequisitesOk($from, $user)) {
            return ['success' => false, 'action' => 'swap_blocked'];
        }

        $parsed = $this->parseSwapIntent($message);
        if ($parsed === null) {
            $this->whatsappService->sendMessage(
                $from,
                "🔄 *Swap USDT ↔ USDC* (same wallet, via Panora)\n\n" .
                "Examples:\n" .
                "`swap 2 USDT to USDC`\n" .
                "`swap 5 USDC to USDT`\n\n" .
                "Minimum *0.01*. Type *cancel* if you have a pending transaction."
            );

            return ['success' => false, 'action' => 'swap_help'];
        }

        return $this->swapStart($from, $user, $parsed);
    }

    /**
     * @param array{amount: float, from_asset: string, to_asset: string, direction: string} $parsed
     * @return array<string, mixed>
     */
    private function swapStart($from, $user, array $parsed)
    {
        if (!empty($user['pending_tx_id'])) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ You already have a pending transaction. Enter your PIN or type *cancel* first."
            );

            return ['success' => false, 'action' => 'swap_pending_exists'];
        }

        $amount = (float) $parsed['amount'];
        $fromAsset = (string) $parsed['from_asset'];
        $toAsset = (string) $parsed['to_asset'];
        $direction = (string) $parsed['direction'];
        $wallet = (string) ($user['aptos_address'] ?? '');
        if ($wallet === '') {
            $this->whatsappService->sendMessage($from, '❌ No wallet on file. Type *hi* to get started.');

            return ['success' => false];
        }

        $balances = $this->fetchAptosBalance($wallet);
        if ($balances === null) {
            $this->whatsappService->sendMessage($from, '❌ Could not read your balance. Try again.');

            return ['success' => false];
        }
        $avail = (float) str_replace(',', '', $balances[$fromAsset] ?? '0');
        if ($avail + 1e-9 < $amount) {
            $this->whatsappService->sendMessage(
                $from,
                '❌ Not enough *' . $fromAsset . "*.\nYou have *" . number_format($avail, 6, '.', ',') . "* {$fromAsset}."
            );

            return ['success' => false];
        }

        $amountStr = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        if ($amountStr === '') {
            $amountStr = (string) $amount;
        }

        $this->whatsappService->sendMessage($from, '⏳ Getting a *' . $fromAsset . ' → ' . $toAsset . '* quote…');

        $panora = new \App\Services\SendraPanoraService();
        $quote = $panora->requestSwapQuoteForDirection($direction, $amountStr, $wallet);
        if (!$quote['success'] || !is_array($quote['data'] ?? null)) {
            $err = (string) ($quote['error'] ?? 'Quote failed');
            error_log("Panora wallet swap quote failed {$from}: {$err}");
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not get a swap quote: {$err}\n\nTry again in a moment."
            );

            return ['success' => false];
        }

        $parsedQuote = \App\Services\SendraPanoraService::parseSwapResponse($quote['data']);
        $estOut = $parsedQuote['to_token_amount'];
        $minOut = $parsedQuote['min_to_token_amount'] ?? $estOut;
        if ($estOut === null || $estOut <= 0) {
            $this->whatsappService->sendMessage($from, '❌ Swap quote incomplete. Try again.');

            return ['success' => false];
        }

        $estFmt = rtrim(rtrim(number_format($estOut, 6, '.', ''), '0'), '.');
        $minFmt = ($minOut !== null && $minOut > 0)
            ? rtrim(rtrim(number_format($minOut, 6, '.', ''), '0'), '.')
            : $estFmt;

        $pendingId = $this->storePendingTransaction([
            'user_phone' => $from,
            'tx_type' => 'panora_wallet_swap',
            'amount' => $amountStr,
            'currency' => $fromAsset,
            'recipient_address' => $wallet,
            'human_description' => json_encode([
                'kind' => 'panora_wallet_swap',
                'direction' => $direction,
                'from_asset' => $fromAsset,
                'to_asset' => $toAsset,
                'expected_out' => $estOut,
                'min_out' => $minOut,
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 5 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        $this->whatsappService->sendButtonMessage(
            $from,
            "🔐 *Enter your 6-digit PIN*\n\n" .
            "Swap *{$amountStr} {$fromAsset}* → *≈ {$estFmt} {$toAsset}*\n" .
            "(minimum *{$minFmt} {$toAsset}* after slippage)\n\n" .
            "Funds stay in your Sendra wallet.",
            ['cancel_' . $pendingId => 'Cancel']
        );

        return ['success' => true, 'action' => 'swap_pin_queued'];
    }

    /**
     * @param array<string, mixed> $pending
     * @return array<string, mixed>
     */
    private function executePanoraWalletSwap($from, $user, array $pending)
    {
        $meta = json_decode($pending['human_description'] ?? '{}', true) ?: [];
        $direction = (string) ($meta['direction'] ?? 'usdt_to_usdc');
        if ($direction !== 'usdc_to_usdt') {
            $direction = 'usdt_to_usdc';
        }
        $fromAsset = (string) ($meta['from_asset'] ?? $pending['currency'] ?? 'USDT');
        $toAsset = (string) ($meta['to_asset'] ?? ($direction === 'usdc_to_usdt' ? 'USDT' : 'USDC'));
        $amountStr = (string) ($pending['amount'] ?? '');

        $this->whatsappService->sendMessage(
            $from,
            "⏳ Swapping *{$amountStr} {$fromAsset}* → *{$toAsset}*…"
        );

        $payload = [
            'sender_address' => $user['aptos_address'],
            'encrypted_private_key' => $user['encrypted_private_key'],
            'from_token_amount' => $amountStr,
            'direction' => $direction,
        ];

        $ch = curl_init('http://127.0.0.1:3001/transactions/panora-swap');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $this->aptosServiceAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $curlErr = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        $result = json_decode((string) $raw, true);

        if ($curlErr || empty($result['success'])) {
            $err = is_array($result) ? ($result['error'] ?? 'Swap failed') : ($curlErr ?: 'Service error');
            $this->updatePendingTransactionStatus($pending['id'], 'failed');
            $this->userModel->clearPendingTransaction($from);
            error_log("Panora wallet swap failed {$from}: {$err} raw={$raw}");
            $alreadyDone = $this->pendingPanoraSwapAlreadySucceeded($pending['id']);
            $hint = $alreadyDone
                ? "\n\nA swap for this request may have already completed (duplicate PIN). Check *balance*."
                : "\n\nYour *{$fromAsset}* balance should be unchanged.";
            $this->whatsappService->sendMessage(
                $from,
                "❌ Swap failed: {$err}{$hint}"
            );

            return ['success' => false];
        }

        $panoraBlock = is_array($result['panora'] ?? null) ? $result['panora'] : [];
        $outAmt = null;
        foreach (['to_token_amount', 'min_to_token_amount'] as $k) {
            if (isset($panoraBlock[$k]) && is_numeric($panoraBlock[$k])) {
                $f = (float) $panoraBlock[$k];
                if ($f > 0) {
                    $outAmt = $f;
                    break;
                }
            }
        }
        if ($outAmt === null || $outAmt <= 0) {
            $outAmt = isset($meta['expected_out']) ? (float) $meta['expected_out'] : null;
        }
        $outFmt = $outAmt !== null && $outAmt > 0
            ? rtrim(rtrim(number_format($outAmt, 6, '.', ''), '0'), '.')
            : '—';

        $hash = (string) ($result['tx_hash'] ?? '');
        $explorer = $this->aptosExplorerUrlForTransaction(is_array($result) ? $result : [], $hash);

        $this->updatePendingTransactionStatus($pending['id'], 'confirmed', $hash !== '' ? $hash : null);
        $this->mergePendingTransactionHumanDescription($pending['id'], [
            'panora_swap_tx_hash' => $hash,
            'swap_completed_at' => gmdate('c'),
        ]);

        $this->recordTransaction([
            'user_phone' => $from,
            'direction' => 'out',
            'amount' => $amountStr,
            'currency' => $fromAsset,
            'status' => 'confirmed',
            'aptos_address' => $user['aptos_address'],
            'counterparty' => 'panora_wallet_swap',
            'tx_hash' => $hash,
            'explorer_url' => $explorer,
        ]);

        $explorerLine = $explorer !== '' ? "\n{$explorer}" : '';
        $this->whatsappService->sendMessage(
            $from,
            "✅ *Swap complete*\n\n" .
            "*{$amountStr} {$fromAsset}* → *≈ {$outFmt} {$toAsset}* in your wallet.\n\n" .
            ($hash !== '' ? "Tx: `{$hash}`{$explorerLine}\n\n" : '') .
            "Type *balance* to refresh."
        );

        return ['success' => true, 'tx_hash' => $hash];
    }

    private function pendingPanoraSwapAlreadySucceeded(string $pendingId): bool
    {
        $row = $this->getPendingTransactionRow($pendingId);
        if ($row === null) {
            return false;
        }
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            return false;
        }
        $h = trim((string) ($meta['panora_swap_tx_hash'] ?? ''));
        if ($h !== '') {
            return true;
        }
        $st = (string) ($row['status'] ?? '');

        return $st === 'confirmed' && !empty($row['tx_hash']);
    }

}
