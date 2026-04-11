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

            case str_starts_with($messageLower, 'send '):
                return $this->handleSendInitiation($from, $messageLower, $user);

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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . $_ENV['INTERNAL_API_SECRET'],
            ],
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

        $this->whatsappService->sendMessage($from, "⏳ Processing your transaction...");

        // ── Call Node.js to execute ───────────────────────────────────────────
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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . $_ENV['INTERNAL_API_SECRET'],
            ],
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

        $this->userModel->clearPendingTransaction($from);

        if (!empty($result['success'])) {
            $hash = $result['tx_hash'];
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
                'explorer_url'  => "https://explorer.aptoslabs.com/txn/{$hash}?network=testnet",
            ]);
            $this->whatsappService->sendMessage(
                $from,
                "✅ *Transaction Confirmed!*\n\n" .
                    "Sent: *{$pending['amount']} {$pending['currency']}*\n" .
                    "To: `{$pending['recipient_address']}`\n\n" .
                    "🔗 https://explorer.aptoslabs.com/txn/{$hash}?network=testnet"
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
            ['cancel_' . $pendingId => '❌ Cancel']
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

    private function fetchAptosBalance($walletAddress)
    {
        $USDC = "0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832";
        $USDT = "0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a";
        $baseUrl = "https://api.testnet.aptoslabs.com/v1";

        try {
            return [
                'APT'  => number_format($this->getAptBalance($walletAddress, $baseUrl) / 1e8, 4),
                'USDC' => number_format($this->getFungibleAssetBalance($walletAddress, $USDC, $baseUrl) / 1e6, 2),
                'USDT' => number_format($this->getFungibleAssetBalance($walletAddress, $USDT, $baseUrl) / 1e6, 2),
            ];
        } catch (\Exception $e) {
            error_log("fetchAptosBalance error: " . $e->getMessage());
            return null;
        }
    }

    private function getAptBalance($walletAddress, $baseUrl)
    {
        $url = "$baseUrl/accounts/$walletAddress/resource/0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return (int) ($data['data']['coin']['value'] ?? 0);
        }
        return 0;
    }

    private function getFungibleAssetBalance($walletAddress, $metadataAddress, $baseUrl)
    {
        $ch = curl_init("$baseUrl/view");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                "function"        => "0x1::primary_fungible_store::balance",
                "type_arguments"  => ["0x1::fungible_asset::Metadata"],
                "arguments"       => [$walletAddress, $metadataAddress],
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return isset($data[0]) ? (int) $data[0] : 0;
        }
        return 0;
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
        $name = $user['username'] ?? 'there';

        $message = '';

        if ($user['is_logistics'] === 1) {
            $message = "Welcome back, {$name}! 🚚\n\n";
        }
        else {
            $message = "Hi {$name} 🙋🏽‍♀️ I'm Sendra.\n\n" .
                "*Main Menu*\n" .
                "💰 Check balance — type `/balance`\n" .
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
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Internal-Secret: ' . $_ENV['INTERNAL_API_SECRET'],
                ],
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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . $_ENV['INTERNAL_API_SECRET'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($result['success'])) {
            $hash = $result['tx_hash'];

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
                'explorer_url'  => "https://explorer.aptoslabs.com/txn/{$hash}?network=testnet",
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
                'explorer_url'  => "https://explorer.aptoslabs.com/txn/{$hash}?network=testnet",
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
                    "🔗 https://explorer.aptoslabs.com/txn/{$hash}?network=testnet"
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


    /**
     * Parse offramp command: "send 20000 naira to 8164727987 opay"
     */
    private function handleOfframpCommand($from, $message, $user)
    {
        // Parse: send [amount] naira to [account_number] [bank_code/name]
        if (!preg_match('/send\s+(\d+(?:\.\d{2})?)\s+naira\s+to\s+(\d{10})\s+(\w+)/i', $message, $matches)) {
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Format: *send [amount] naira to [account] [bank]*\n\n" .
                "Example: `send 20000 naira to 8164727987 opay`\n\n" .
                "Supported banks: opay, kuda, gtb, access, zenith, etc."
            );
            return ['success' => false];
        }

        $amountNaira = $matches[1];
        $accountNumber = $matches[2];
        $bankIdentifier = strtolower($matches[3]);

        // Map bank names to codes (you'll need a complete mapping)
        $bankCodes = [
            'opay' => '999992',
            'kuda' => '090267',
            'gtb' => '058',
            'access' => '044',
            'zenith' => '057',
            // Add more banks
        ];

        $bankCode = $bankCodes[$bankIdentifier] ?? null;

        if (!$bankCode) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Unknown bank: *{$bankIdentifier}*\n\n" .
                "Supported: opay, kuda, gtb, access, zenith"
            );
            return ['success' => false];
        }

        return $this->processOfframpRequest($from, $user, $amountNaira, $accountNumber, $bankCode);
    }

    /**
     * Step 1: Lookup account and get rate
     */
    private function processOfframpRequest($from, $user, $amountNaira, $accountNumber, $bankCode)
    {
        $centivService = new \App\Services\CentivService();

        $this->whatsappService->sendMessage($from, "🔍 Looking up account details...");

        // Account lookup
        $accountInfo = $centivService->lookupBankAccount($accountNumber, $bankCode);

        if (!$accountInfo['success']) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not verify account. Please check the details and try again."
            );
            return ['success' => false];
        }

        // Get exchange rate
        $rateInfo = $centivService->getExchangeRate('USDC', $amountNaira);

        if (!$rateInfo['success']) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Could not fetch exchange rate. Please try again."
            );
            return ['success' => false];
        }

        $cryptoAmount = $rateInfo['crypto_amount'];
        $rate = $rateInfo['rate'];
        $fee = $rateInfo['fee'];

        // Check user balance
        $balances = $this->fetchAptosBalance($user['aptos_address']);
        $usdcBalance = floatval(str_replace(',', '', $balances['USDC']));

        if ($usdcBalance < $cryptoAmount) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Insufficient balance\n\n" .
                "Required: *{$cryptoAmount} USDC*\n" .
                "Available: *{$usdcBalance} USDC*\n\n" .
                "Type `/balance` to check your wallet."
            );
            return ['success' => false, 'reason' => 'insufficient_balance'];
        }

        // Store pending offramp transaction
        $pendingId = $this->storePendingOfframp([
            'user_phone' => $from,
            'amount_naira' => $amountNaira,
            'crypto_amount' => $cryptoAmount,
            'currency' => 'USDC',
            'rate' => $rate,
            'fee' => $fee,
            'account_number' => $accountNumber,
            'account_name' => $accountInfo['account_name'],
            'bank_code' => $bankCode,
            'bank_name' => $accountInfo['bank_name'],
            'expires_at' => date('Y-m-d H:i:s', time() + 300)
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        // Show confirmation
        $this->whatsappService->sendButtonMessage(
            $from,
            "💸 *Confirm Withdrawal*\n\n" .
            "📤 Send: ₦{$amountNaira}\n" .
            "💰 Cost: *{$cryptoAmount} USDC*\n" .
            "📊 Rate: ₦{$rate}/USDC\n" .
            "💵 Fee: ₦{$fee}\n\n" .
            "🏦 *Recipient Details:*\n" .
            "Name: *{$accountInfo['account_name']}*\n" .
            "Account: {$accountNumber}\n" .
            "Bank: {$accountInfo['bank_name']}\n\n" .
            "⚠️ Please verify the account name is correct.\n\n" .
            "Enter your *6-digit PIN* to confirm:",
            ['cancel_' . $pendingId => '❌ Cancel']
        );

        return ['success' => true, 'pending_id' => $pendingId];
    }

    /**
     * Execute offramp after PIN confirmation
     */
    private function executeOfframp($from, $user, $pending)
    {
        $centivService = new \App\Services\CentivService();

        $this->whatsappService->sendMessage($from, "⏳ Processing withdrawal...");

        // Step 1: Burn/transfer USDC from user's wallet to Sendra's settlement wallet
        // (You'll need a settlement wallet address for this)
        $settlementWallet = $_ENV['SENDRA_SETTLEMENT_WALLET'];

        $ch = curl_init('http://127.0.0.1:3001/transactions/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'sender_address' => $user['aptos_address'],
                'encrypted_private_key' => $user['encrypted_private_key'],
                'recipient' => $settlementWallet,
                'amount' => $pending['crypto_amount'],
                'currency' => $pending['currency']
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Secret: ' . $_ENV['INTERNAL_API_SECRET']
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        $burnResult = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($burnResult['success'])) {
            $this->whatsappService->sendMessage(
                $from,
                "❌ Blockchain transaction failed. Your funds are safe.\n\n" .
                "Please try again or contact support."
            );
            return ['success' => false];
        }

        $txHash = $burnResult['tx_hash'];

        // Step 2: Create Centiv order
        $orderRef = 'SENDRA_' . bin2hex(random_bytes(8));
        
        $orderResult = $centivService->createOfframpOrder([
            'currency' => $pending['currency'],
            'crypto_amount' => $pending['crypto_amount'],
            'fiat_amount' => $pending['amount_naira'],
            'account_number' => $pending['account_number'],
            'account_name' => $pending['account_name'],
            'bank_code' => $pending['bank_code'],
            'customer_phone' => $from,
            'reference' => $orderRef
        ]);

        if (!$orderResult['success']) {
            // Order failed but crypto already sent - needs manual reconciliation
            error_log("CRITICAL: Offramp crypto sent but Centiv order failed | user=$from tx=$txHash");
            
            $this->whatsappService->sendMessage(
                $from,
                "⚠️ Payment processing issue detected.\n\n" .
                "Your crypto has been secured. Our team will complete your transfer within 24 hours.\n\n" .
                "Reference: {$orderRef}\n" .
                "Support: support@usesendra.com"
            );
            
            return ['success' => false, 'needs_reconciliation' => true];
        }

        // Success!
        $receiptUrl = $orderResult['receipt_url'];
        
        $this->updatePendingOfframpStatus($pending['id'], 'completed', $txHash, $orderResult['order_id']);

        $message = "✅ *Withdrawal Successful!*\n\n" .
            "💸 ₦{$pending['amount_naira']} sent to:\n" .
            "*{$pending['account_name']}*\n" .
            "{$pending['account_number']} ({$pending['bank_name']})\n\n" .
            "📊 *Transaction Details:*\n" .
            "USDC Spent: {$pending['crypto_amount']}\n" .
            "Rate: ₦{$pending['rate']}/USDC\n" .
            "Fee: ₦{$pending['fee']}\n\n" .
            "🔗 Blockchain: https://explorer.aptoslabs.com/txn/{$txHash}?network=testnet\n";

        if ($receiptUrl) {
            $message .= "📄 Receipt: {$receiptUrl}\n";
        }

        $message .= "\n⏱️ Funds should arrive within 5-10 minutes.";

        $this->whatsappService->sendMessage($from, $message);

        return ['success' => true];
    }

}
