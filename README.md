# Sendra — Project Tracker

> WhatsApp-based crypto wallet on Aptos. Users send/receive USDC/USDT entirely through WhatsApp.
> Last updated: April 2026

---

## Stack

| Layer | Tech |
|---|---|
| Bot / Backend | PHP (webhook.php + Controllers) |
| Blockchain | Aptos testnet (USDC + USDT) |
| Wallet service | Node.js `aptos-service` on port 3001 (pm2) |
| Web client | React + Vite + TypeScript (`usesendra.com/client`) |
| Wallet adapter | `@aptos-labs/wallet-adapter-react` (Aptos Connect / Google) |
| DB | MySQL via PDO |
| Hosting | VPS — Nginx, PHP, pm2 |
| WhatsApp API | Meta Cloud API v20.0 |

---

## URLs & Endpoints

| Resource | Value |
|---|---|
| Webhook | `https://coinectra.stipendly.app/webhook.php` |
| Web client | `https://usesendra.com/client` |
| aptos-service (internal) | `http://127.0.0.1:3001` |
| PHP debug log | `/var/www/html/php-debug.log` |
| Webhook source | `/var/www/html/coinectra/webhook.php` |
| aptos-service source | `/var/www/html/coinectra/aptos-service/` |
| React source | `/var/www/usesendra.com/public_html/client/` |

---

## Key Credentials

> ⚠️ Never commit real credentials here. Store in `.env` / AWS SSM / server environment only.

| Key | Where to find it |
|---|---|
| `PHONE_NUMBER_ID` | Meta Developer Console → WhatsApp → Phone Numbers |
| `VERIFY_TOKEN` | Set in Meta webhook config + `webhook.php` |
| `INTERNAL_API_SECRET` | `.env` on server, shared between `webhook.php` and `aptos-service` |
| `ACCESS_TOKEN` | Meta Developer Console → WhatsApp → API Setup |
| WhatsApp bot number | `+2348144111831` |

---

## Architecture

```
User (WhatsApp)
     │
     ▼
webhook.php  ──────────────────────────────────────────────┐
  │                                                         │
  ├── /signup          (Petra wallet registration)          │
  ├── /sendSuccess     (Petra send confirmation)            │
  ├── ?action=signup   (same, via query string)             │
  ├── ?action=sendSuccess                                   │
  ├── ?action=voucherClaim                                  │
  ├── ?action=deposit  (Nodit webhook)                      │
  └── WhatsApp message handler                             │
        │                                                   │
        ▼                                                   │
  MessageController.php                                     │
        │                                                   │
        ├── handleCreateAccount()  ──► aptos-service /accounts/create
        ├── handleImportAccount()  ──► redirects to usesendra.com/client
        ├── handleSendInitiation() ──► aptos-service /transactions/execute
        ├── handleBalanceCommand() ──► Aptos REST API (testnet)
        ├── handleVoucherCreation()
        └── executeVoucherClaim()  ──► aptos-service /accounts/create
                                   ──► aptos-service /transactions/execute
```

---

## Account Types

| Type | Description | Key storage |
|---|---|---|
| `sendra` | Created in-app via WhatsApp | `encrypted_private_key` stored in DB |
| `petra` | Connected via Aptos Connect (Google wallet) | No key stored — user signs on website |

Routing logic: `if (!empty($user['encrypted_private_key']))` → sendra flow, else → Petra CTA redirect.

---

## Webhook Routes

All routes support **both** path-based (`/route`) and query-string (`?action=route`) matching.

```php
// Pattern used throughout webhook.php:
if (strpos($requestUri, '/routeName') !== false || ($_GET['action'] ?? '') === 'routeName') {
```

| Route | Trigger | Handler |
|---|---|---|
| `/signup` or `?action=signup` | Petra wallet connected on website | `$authController->signup()` + WhatsApp welcome message |
| `/sendSuccess` or `?action=sendSuccess` | Petra send confirmed on website | `$whatsappService->sendMessage()` with tx summary |
| `/voucher/claim` or `?action=voucherClaim` | Voucher claimed on website | `$messageController->executeVoucherClaim()` |
| `/deposit` or `?action=deposit` | Nodit webhook (on-chain deposit detected) | `handleDepositNotification()` |
| `/sendTemplate` | Send a WhatsApp template | `$whatsappService->sendTemplate()` |
| `/sendTemplateBatch` | Bulk template send | loop over recipients |
| _(default)_ | Incoming WhatsApp message | `$messageController->handleIncomingMessage()` |

---

## React Client (ConnectionFlow.tsx)

URL params the client reads:

| Param | Used for |
|---|---|
| `sender` | Phone number of WhatsApp user (signup / send flows) |
| `username` | Display name passed from bot |
| `action` | `send`, `balance`, or omitted (signup) |
| `recipient` | Aptos address for send flow |
| `amount` | Amount for send flow |
| `currency` | `USDC` or `USDT` |
| `voucher` | Voucher token (bypasses wallet connect, shows claim form) |
| `phoneNumber` | Recipient phone for voucher claim |
| `mode` | `import` for Petra import flow |

Flow logic:
- `voucher` present → skip wallet connect, show username form → POST `?action=voucherClaim`
- `action=send` → connect wallet → sign transaction → POST `?action=sendSuccess`
- `action=balance` → connect wallet → fetch balances → show balance UI
- default → connect wallet → POST `?action=signup`

---

## Voucher System

Flow when sender tries to pay an unregistered phone number:

1. `handleVoucherCreation()` — creates DB record, sends link to sender
2. Sender forwards `usesendra.com/client?voucher=TOKEN&phoneNumber=RECIPIENT` to recipient
3. Recipient opens link → enters username (no wallet needed)
4. React POSTs `{ token, phone_number, username }` to `?action=voucherClaim`
5. `executeVoucherClaim()` — creates Sendra keypair via aptos-service, registers user, executes transfer
6. Sends private key to recipient via WhatsApp (with "I've saved it" button)
7. Notifies both sender and recipient with real names

DB table: `vouchers` — fields: `id, token, created_by, amount, currency, recipient_phone, status (pending/claimed/expired/cancelled), expires_at, claimed_by, claimed_at, tx_hash`

TTL: 48 hours. Status locked immediately on claim to prevent double-spend.

---

## User Onboarding Flows

### Sendra (in-chat)
```
User types "hi"
  → sendWelcomeMessage() [Create Account | Import Account buttons]
  → taps "Create Account"
  → handleCreateAccount() → aptos-service generates keypair
  → bot sends private key (ONCE) with "I've saved it" button
  → handleKeyConfirmation() → deletes key message
  → user sets 6-digit PIN (awaiting_pin_setup = 1)
  → handlePinSetup() → PIN stored as bcrypt hash
  → account fully active
```

### Petra (web)
```
User taps "Import Account"
  → handleImportAccount() sends CTA button → usesendra.com/client?sender=PHONE&mode=import
  → user connects Aptos Connect (Google) wallet on website
  → React POSTs { phoneNumber, aptos_address, username } to ?action=signup
  → webhook registers user (account_type=petra, no encrypted_private_key)
  → webhook sends WhatsApp welcome message
```

---

## Transaction Flow (Sendra users)

```
User: "send 10 USDC to 2348012345678"
  → handleSendInitiation() → resolves phone to address
  → queueTransactionAndAskForPin() → stores pending_transactions row (5 min TTL)
  → bot asks for PIN with Cancel button
  → user enters PIN
  → handlePinConfirmation() → verifies PIN → calls aptos-service /transactions/execute
  → records in transaction_history
  → sends confirmation with explorer link
```

---

## aptos-service (Node.js)

Runs on `http://127.0.0.1:3001`. Manages key encryption/decryption and transaction signing.

| Endpoint | Purpose |
|---|---|
| `GET /health` | Health check |
| `POST /accounts/create` | Generate keypair, encrypt private key, return `aptos_address + encrypted_private_key + private_key_for_display` |
| `POST /transactions/execute` | Decrypt key, sign + submit transaction, return `tx_hash` |

All requests require header: `X-Internal-Secret: <INTERNAL_API_SECRET>`

pm2 commands:
```bash
cd /var/www/html/coinectra/aptos-service
npm run build
pm2 start dist/index.js --name aptos-service --env production
pm2 save && pm2 startup
pm2 logs aptos-service
curl http://127.0.0.1:3001/health
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `users` | Registered users — phone, username, aptos_address, encrypted_private_key, account_type, pin_hash, awaiting_key_confirm, awaiting_pin_setup, pending_tx_id, key_message_wamid |
| `pending_transactions` | Queued txs awaiting PIN — 5 min TTL, status: pending/confirmed/failed/cancelled |
| `transaction_history` | Permanent ledger — direction (in/out), tx_hash, counterparty, explorer_url |
| `vouchers` | Pending vouchers — token, created_by, recipient_phone, amount, currency, 48hr TTL |

---

## Phone Number Normalization

All phone numbers stored in international format without `+`:

```php
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) === 11 && $digits[0] === '0') {
    $digits = '234' . substr($digits, 1);  // 08012345678 → 2348012345678
}
```

Applied in: `webhook.php` signup block, `MessageController::normalizePhone()`.

---

## Token Addresses (Aptos Testnet)

| Token | Address |
|---|---|
| USDC | `0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832` |
| USDT | `0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a` |

---

## Bugs Fixed (history)

| Bug | Fix |
|---|---|
| Nginx redirect loop on `/client/` | Named location pattern for SPA fallback |
| All `?action=` routes not matching | Added `($_GET['action'] ?? '') === 'route'` checks to every handler |
| Signup "missing required fields" with null username | Removed `username` from `isset()` check, added fallback `user_XXXX` |
| Petra signup "body stream already read" | Read `response.text()` once, then `JSON.parse()` |
| Phone `08...` not normalized on signup | Added normalization in webhook signup block |
| No welcome message after Petra signup | Added `sendMessage()` after `authController->signup()` in webhook |
| No send confirmation for Petra users | Added `?action=sendSuccess` query string check |
| `sendSuccess` returning `EVENT_RECEIVED` | Added `|| ($_GET['action'] ?? '') === 'sendSuccess'` to route check |
| `MessageController` constructor mismatch | Changed `new MessageController($whatsappService, $db)` → `new MessageController($whatsappService, $authController, $db)` |
| PHP errors breaking WhatsApp 200 response | Set `display_errors = 0`, `log_errors = 1` |
| Status update payloads (read receipts) crashing bot | Added `if (isset($value['statuses']) && !isset($value['messages'])) continue;` |
| `/sendTemplateBatch` matched by `/sendTemplate` check | Moved batch check before single check |

---

## Useful curl Test Commands

```bash
# Test sendSuccess
curl -X POST "https://coinectra.stipendly.app/webhook.php?action=sendSuccess" \
  -H "Content-Type: application/json" \
  -d '{
    "senderPhoneNumber": "2348164727987",
    "sender": "0x1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b",
    "recipient": "0x9z8y7x6w5v4u3t2s1r0q9p8o7n6m5l4k3j2i1h0g9f8e7d6c5b4a3z2y1x0w9v",
    "amount": "10",
    "currency": "USDC",
    "transactionHash": "0xabc123def456abc123def456abc123def456abc123def456abc123def456abc123",
    "timestamp": "2026-03-13T12:00:00.000Z"
  }'

# Test signup
curl -X POST "https://coinectra.stipendly.app/webhook.php?action=signup" \
  -H "Content-Type: application/json" \
  -d '{
    "phoneNumber": "2348164727987",
    "aptos_address": "0x1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b",
    "username": "Deen"
  }'

# Check aptos-service health
curl http://127.0.0.1:3001/health

# Tail PHP debug log
tail -f /var/www/html/php-debug.log
```

---

## Third-Party Integrations (Planned)

### Centiv — Buy/Sell
- Status: **TODO**
- Purpose: Fiat on/off ramp — buy and sell USDT/USDC via the bot

### SmoothSend — Gasless Transactions
- Status: **Under review — security concern (see below)**
- Website: https://www.smoothsend.xyz
- npm: `@smoothsend/sdk`
- Purpose: Sponsor gas fees so users never need APT to send USDC/USDT
- How it works: Aptos sponsored transactions — user signs a message, SmoothSend relayer pays gas
- Chains supported: Aptos (mainnet + testnet), Arbitrum, Base, Avalanche
- Pilot program: Free gas credits available at https://pilot.smoothsend.xyz
- API key dashboard: https://dashboard.smoothsend.xyz
- Docs: https://docs.smoothsend.xyz

**⚠️ Security Hold — Axios Supply Chain Attack (March 31, 2026)**

The `@smoothsend/sdk` package lists `axios` as its only production npm dependency. On March 31, 2026, axios was compromised in a North Korean (Sapphire Sleet / UNC1069) supply chain attack:

- Malicious versions: `axios@1.14.1` and `axios@0.30.4`
- Attack vector: Compromised maintainer account published versions with a hidden dependency (`plain-crypto-js@4.2.1`) that ran a postinstall RAT dropper targeting macOS, Windows, and Linux
- The RAT stole credentials, API keys, and cloud tokens, then contacted a C2 server (`sfrclak.com:8000`)
- Malicious versions were live for ~3 hours (00:21–03:30 UTC) before removal
- Safe versions: `axios@1.14.0` (1.x) or `axios@0.30.3` (0.x)

**Before integrating SmoothSend:**
1. Check which axios version `@smoothsend/sdk` pins in its `package.json`
2. Confirm it is NOT `1.14.1` or `0.30.4`
3. Run `npm audit` and verify no `plain-crypto-js` in the dependency tree
4. Check if SmoothSend has published a statement or updated their SDK since March 31
5. Consider waiting for a new SmoothSend release that explicitly pins a safe axios version

---

## Pending / TODO

- [ ] Integrate Centiv API for buy/sell (fiat on/off ramp)
- [ ] Integrate SmoothSend gasless transactions — **blocked pending axios security review above**
- [ ] BVN/NIN KYC integration (Youverify / Smile Identity / Prembly)
- [ ] Daily transaction limits per KYC tier in MessageController
- [ ] Sanctions screening API (ComplyAdvantage or World-Check)
- [ ] Rate limiting on PIN attempts
- [ ] Voucher expiry cronjob (mark expired, notify sender)
- [ ] MLRO dashboard for compliance case management
- [ ] AWS KMS migration for encrypted private keys
- [ ] Africa's Talking USSD integration (sandbox testing pending AT account)
- [ ] Structuring/dormancy detection rules in monitor.php
- [ ] SEC Nigeria ARIP application
- [ ] Move from Aptos testnet → mainnet (update token addresses + explorer URLs)
