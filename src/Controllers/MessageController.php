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

        // ── 3b. Sell (off-ramp) multi-step — before generic commands ───────────
        $sellHandled = $this->handleSellFlowMessage($from, $message, $messageLower, $user);
        if ($sellHandled !== null) {
            return $sellHandled;
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

            case $messageLower === 'sell':
            case str_starts_with($messageLower, 'sell '):
            case $messageLower === 'sell_fiat_ngn':
            case $messageLower === 'sell_fiat_ghs':
            case str_starts_with($messageLower, 'send '):
            case str_starts_with($messageLower, 'pay '):
                return $this->handleSellEntry($from, $message, $messageLower, $user);

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

        // Fetch pending transaction
        $pending = $this->getPendingTransaction($user['pending_tx_id']);

        if (!$pending) {
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

        if (($pending['tx_type'] ?? '') === 'offramp_oneclick_centiiv') {
            return $this->executeCentiivOneClickOfframpTransfer($from, $user, $pending);
        }

        if (($pending['tx_type'] ?? '') === 'offramp_centiiv') {
            return $this->executeCentiivOfframpTransfer($from, $user, $pending);
        }

        $this->whatsappService->sendMessage($from, "⏳ Processing your transaction...");

        // Hardcoded: always use SmoothSend Method 2 (TransactionSubmitter) for sends.
        // This prevents accidental fallback to the SmoothSend Script (Method 1) path.
        $useMethod2Gasless = false;

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
        $name = $user['username'] ?? 'there';

        $message = '';

        if ($user['is_logistics'] === 1) {
            $message = "Welcome back, {$name}! 🚚\n\n";
        }
        else {
            $message = "Hi {$name} 🙋🏽‍♀️ I'm Sendra.\n\n" .
                "*Main Menu*\n" .
                "💰 Check balance — type `/balance`\n" .
                "📈 Sell crypto for Naira — type `sell` or `sell 20 USDC`\n" .
                "📤 Send USDT/USDC — type `send 50 USDT to 0x...`\n" .
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
            $this->whatsappService->sendMessage($from, '⏳ Working on it…');

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
                "Reply with amount and token, e.g. *20 USDC* or *10 USDT*.\nType *cancel* to stop."
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
                "How much do you want to cash out?\nExample: *20 USDC* or *10 USDT*\n\n*cancel* stops."
            );

            return ['success' => true, 'action' => 'sell_ngn_amount'];
        }

        if ($messageLower === 'sell') {
            $this->setSellChatContext($from, ['flow' => 'sell', 'step' => 'await_amount', 'fiat' => 'NGN']);
            $this->whatsappService->sendMessage(
                $from,
                "Cash out to your Nigerian bank.\n\nHow much? e.g. *20 USDC* or *10 USDT*\n\n*cancel* stops."
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
            "Try: *sell 20 USDC* or *send 20000 naira to 0123456789 GTBank*"
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
    private function sellNgnReceiveForCryptoAmount(string $amountStr, string $asset, array $user, \App\Services\SendraCentiivRampService $agg): ?float
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
            true
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
        $maxIters = (int) ($_ENV['SENDRA_SELL_TARGET_NGN_MAX_ITERS'] ?? getenv('SENDRA_SELL_TARGET_NGN_MAX_ITERS') ?: 14);
        if ($maxIters < 4) {
            $maxIters = 4;
        }
        if ($maxIters > 18) {
            $maxIters = 18;
        }

        // USDC: binary search by live quote until within tolerance.
        if ($asset === 'USDC') {
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
                return null;
            }

            return [
                'crypto_amount' => $bestAmt,
                'receive_ngn_display' => $bestSum['receive_ngn_display'],
                'rate_hint' => $bestSum['rate_hint'],
                'fee_hint' => $bestSum['fee_hint'],
            ];
        }

        // USDT: maximum accuracy — binary search using real 1Click dry (USDT→USDC) + Centiiv quote (USDC→NGN) each step.
        if ($asset !== 'USDT' || !$this->oneclickCentiivRampConfigured()) {
            $this->whatsappService->sendMessage($from, '❌ USDT isn’t available right now. Try *USDC*.');

            return null;
        }

        $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        if ($refundAptos === '' || $stellarDeposit === '') {
            $this->whatsappService->sendMessage($from, '❌ Could not start this payout. Try again.');

            return null;
        }
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $oneClick = new \App\Services\SendraOneClickService();

        $lo = $min;
        $hi = $avail;
        $bestAmt = null;
        $bestRecv = null;
        $bestSum = null;
        $bestAboveAmt = null;
        $bestAboveRecv = null;
        $bestAboveSum = null;

        // quick infeasibility check at max (real pricing)
        $hiRecv = $this->sellNgnReceiveForCryptoAmount((string) $hi, 'USDT', $user, $agg);
        if ($hiRecv === null) {
            $wa = (string) ($user['phone_number'] ?? $user['phone'] ?? '');
            @file_put_contents(
                '/tmp/sendra_ramp_debug.log',
                '[' . gmdate('c') . "] sellSolveCryptoAmountForTargetNgn USDT hiRecv null wa={$wa} (1click or centiiv quote failed)\n",
                FILE_APPEND
            );
            $this->whatsappService->sendMessage(
                $from,
                '❌ The *Stellar payout* swap quote failed (1Click / NEAR Intents). This is usually on the provider side, not your wallet. Try again in a few minutes. *USDC* uses the same bridge.'
            );

            return null;
        }
        if ($hiRecv + 1e-6 < $targetNgn) {
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

            // 1Click dry quote for this USDT amount -> est USDC
            $midSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($midStr, 6);
            $dryBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
                $originUsdt,
                $destStellarUsdc,
                $midSmallest,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                true
            );
            $dry = $oneClick->requestQuote($dryBody);
            if (!$dry['success']) {
                $wa = (string) ($user['phone_number'] ?? $user['phone'] ?? '');
                @file_put_contents(
                    '/tmp/sendra_ramp_debug.log',
                    '[' . gmdate('c') . "] sellSolveCryptoAmountForTargetNgn USDT 1click dry quote failed wa={$wa} http={$dry['http_code']} err={$dry['error']} data=" . json_encode($dry['data']) . " raw=" . substr((string) ($dry['raw'] ?? ''), 0, 2000) . "\n",
                    FILE_APPEND
                );
                $this->whatsappService->sendMessage(
                    $from,
                    '❌ The *Stellar payout* swap quote failed (1Click / NEAR Intents). This is usually on the provider side, not your wallet. Try again in a few minutes. *USDC* uses the same bridge.'
                );

                return null;
            }
            $parsed = \App\Services\SendraOneClickService::parseQuoteResponse($dry['data']);
            $estUsdc = (float) ($parsed['amount_out_formatted'] ?? 0);
            if ($estUsdc <= 0) {
                $this->whatsappService->sendMessage($from, '❌ Quote response incomplete. Try again.');

                return null;
            }

            $sum = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => $estUsdc,
            ]);
            if (!$sum['success']) {
                $minUsdc = $this->parseCentiivQuoteMinUsdc($sum['error'] ?? null);
                if ($minUsdc !== null) {
                    // This USDT amount yields too little USDC for Centiiv; push amount upward and continue.
                    $lo = max($lo, $mid);
                    continue;
                } else {
                    $this->whatsappService->sendMessage($from, '❌ Could not refresh the rate. Try again.');
                }

                return null;
            }
            $s = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($sum['data'], $estUsdc);
            $recv = (float) ($s['receive_ngn'] ?? 0);
            if ($recv <= 0) {
                $this->whatsappService->sendMessage($from, '❌ Rate unavailable. Try again.');

                return null;
            }

            $diff = $recv - $targetNgn;
            if ($bestRecv === null || abs($diff) < abs($bestRecv - $targetNgn)) {
                $bestAmt = $midStr;
                $bestRecv = $recv;
                $bestSum = $s;
            }
            // Must not underpay: track best (smallest) recv >= target.
            if ($recv + 1e-9 >= $targetNgn) {
                if ($bestAboveRecv === null || $recv < $bestAboveRecv) {
                    $bestAboveAmt = $midStr;
                    $bestAboveRecv = $recv;
                    $bestAboveSum = $s;
                }
                if (abs($diff) <= $toleranceNgn) {
                    break;
                }
            }
            if ($recv >= $targetNgn) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        if ($bestAboveAmt !== null && $bestAboveSum !== null && $bestAboveRecv !== null) {
            $bestAmt = $bestAboveAmt;
            $bestSum = $bestAboveSum;
            $bestRecv = $bestAboveRecv;
        }

        if ($bestAmt === null || $bestSum === null || $bestRecv === null || $bestRecv + 1e-9 < $targetNgn) {
            return null;
        }

        // One final 1Click dry quote at the chosen amount for accurate order sizing + reuse.
        $finalSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($bestAmt, 6);
        $finalBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdt,
            $destStellarUsdc,
            $finalSmallest,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            true
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
                // Final sizing still too small: prompt user with minimum and stop.
                $this->whatsappService->sendMessage($from, $this->buildCentiivMinPayoutMessage($agg, $minUsdc));
            } else {
                $this->whatsappService->sendMessage($from, '❌ Could not refresh the rate. Try again.');
            }

            return null;
        }
        $finalS = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($finalQ['data'], $finalUsdc);
        // Enforce "at least target": if final quote underpays, bump amount slightly upward and re-lock once.
        $finalRecv = (float) ($finalS['receive_ngn'] ?? 0);
        if ($finalRecv > 0 && $finalRecv + 1e-9 < $targetNgn) {
            // Move the low bound up to current best and re-run a few more steps upward.
            $lo = max($lo, (float) $bestAmt);
            for ($j = 0; $j < 4; $j++) {
                $mid = ($lo + $hi) / 2.0;
                $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
                if ($midStr === '' || (float) $midStr < $min) {
                    $midStr = '0.01';
                }
                $midSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits($midStr, 6);
                $midBody = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
                    $originUsdt,
                    $destStellarUsdc,
                    $midSmallest,
                    $stellarDeposit,
                    $refundAptos,
                    $deadlineIso,
                    true
                );
                $midDry = $oneClick->requestQuote($midBody);
                if (!$midDry['success']) {
                    break;
                }
                $midParsed = \App\Services\SendraOneClickService::parseQuoteResponse($midDry['data']);
                $midUsdc = (float) ($midParsed['amount_out_formatted'] ?? 0);
                if ($midUsdc <= 0) {
                    break;
                }
                $midQ = $agg->getQuote([
                    'fromAsset' => 'USDC',
                    'toAsset' => 'NGN',
                    'amount' => $midUsdc,
                ]);
                if (!$midQ['success']) {
                    break;
                }
                $midS = \App\Services\SendraCentiivRampService::summarizeQuoteNgn($midQ['data'], $midUsdc);
                $midRecv = (float) ($midS['receive_ngn'] ?? 0);
                if ($midRecv <= 0) {
                    break;
                }
                if ($midRecv + 1e-9 >= $targetNgn) {
                    $bestAmt = $midStr;
                    $finalDry = $midDry;
                    $finalUsdc = $midUsdc;
                    $finalS = $midS;
                    break;
                }
                $lo = $mid;
            }
        }

        return [
            'crypto_amount' => $bestAmt,
            'receive_ngn_display' => (string) ($finalS['receive_ngn_display'] ?? $bestSum['receive_ngn_display'] ?? number_format($bestRecv, 2, '.', ',')),
            'rate_hint' => $finalS['rate_hint'] ?? ($bestSum['rate_hint'] ?? null),
            'fee_hint' => $finalS['fee_hint'] ?? ($bestSum['fee_hint'] ?? null),
            'oneclick_quote_data' => is_array($finalDry['data']) ? $finalDry['data'] : null,
            'oneclick_est_usdc' => $finalUsdc,
        ];
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
        if ($asset === 'USDT' && !empty($solved['oneclick_quote_data']) && is_array($solved['oneclick_quote_data'])) {
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
            $stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
            $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
            $destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
            $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
            if ($refundAptos === '') {
                $this->whatsappService->sendMessage($from, '❌ Missing Aptos address. Contact support.');

                return ['success' => false];
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
                $err = $dryRes['error'] ?? 'bridge quote failed';
                error_log("Sendra sell: USDT bridge quote for rate preview failed {$from}: {$err} " . json_encode($dryRes['data']));
                $this->whatsappService->sendMessage(
                    $from,
                    "❌ The Stellar bridge quote failed (1Click), so we can't price USDT→Naira right now.\nTry again in a few minutes. *USDC* uses the same bridge."
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
        $banksRes = $agg->getBanks();
        if (!$banksRes['success']) {
            $this->whatsappService->sendMessage($from, "❌ Could not load bank list. Try again shortly.");

            return ['success' => false];
        }
        $banks = \App\Services\SendraCentiivRampService::normalizeBankList($banksRes['data']);
        if ($banks === []) {
            error_log('Centiiv getBanks returned no parseable banks: ' . json_encode($banksRes['data']));
            $this->whatsappService->sendMessage($from, "❌ Bank list unavailable. Please try again later.");

            return ['success' => false];
        }

        $code = \App\Services\SendraCentiivRampService::resolveBankCode($bankNameGuess, $banks);
        if ($code === null) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not match *{$bankNameGuess}* to a bank.\nTry the official short name (e.g. *GTBank*, *Access*, *Opay*)."
            );

            return ['success' => false];
        }

        if (!$quietVerify) {
            $this->whatsappService->sendMessage($from, '⏳ Checking your account…');
        }

        $verify = $agg->verifyBankAccount($code, $accountNumber);
        if (!$verify['success']) {
            $err = $verify['error'] ?? 'Verify failed';
            $this->whatsappService->sendMessage(
                $from,
                "❌ Account could not be verified.\nCheck the 10 digits and bank name.\n({$err})"
            );

            return ['success' => false];
        }

        $vdata = $verify['data'];
        $acctName = $this->centiivPickAccountName($vdata);

        $userResolvedCode = \App\Services\SendraCentiivRampService::normalizeCentiivBankCode($code);
        $resolved = \App\Services\SendraCentiivRampService::resolvedBankFromVerifyEnvelope(
            is_array($vdata) ? $vdata : []
        );
        $mismatchNote = '';
        if ($resolved !== null && $resolved['code'] !== '') {
            if ($resolved['code'] !== $userResolvedCode) {
                $mismatchNote = "\n\n\u{26A0}\u{FE0F} *Bank auto-corrected:* this account is with *"
                    . ($resolved['name'] ?? 'the bank below')
                    . '*, not *' . $bankNameGuess . '*. Payout will use the matched bank.';
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

            return ['success' => false];
        }

        // Save verified payout details, then go straight to PIN (no extra “confirm/yes” step).
        $ctx['account_number'] = $accountNumber;
        $ctx['bank_code'] = $code;
        $ctx['bank_name'] = $bankDisplay;
        $ctx['account_name'] = $acctName;
        $ctx['centiiv_verify_data'] = is_array($vdata) ? $vdata : null;
        $this->setSellChatContext($from, $ctx);

        return $this->sellCreateCentiivOrderAndQueuePin($from, $userRow, $ctx, $agg);
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
                    "❌ Could not get a cross-chain quote right now.\n({$err})\n\nTry again in a minute or contact support@usesendra.com"
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

        $rawBankRow = null;
        $banksLookup = $agg->getBanks();
        if ($banksLookup['success']) {
            $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
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

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $created['error'] ?? 'Order failed';
            error_log("Centiiv create order (1Click path) failed {$from}: {$err} " . json_encode($created['data']));
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not open a conversion order. Please try again or contact support.\n({$err})"
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
                "❌ Could not lock the final quote. Nothing left your wallet.\nTry *sell* again or contact support@usesendra.com"
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
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
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

        $rawBankRow = null;
        $banksLookup = $agg->getBanks();
        if ($banksLookup['success']) {
            $rawBankRow = \App\Services\SendraCentiivRampService::findRawBankRowInBanksResponse($banksLookup['data'], $code);
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

        $created = $agg->createConversionOrder($body);
        if (!$created['success']) {
            $err = $created['error'] ?? 'Order failed';
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
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $ctx['bank_name'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
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
                "❌ Could not send crypto: {$err}\n\nYour balance is unchanged. Try again or contact support."
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

        $swapDone = false;
        for ($i = 0; $i < 8; $i++) {
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

}
