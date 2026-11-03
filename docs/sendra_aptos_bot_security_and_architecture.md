# Sendra Aptos Bot: Encryption Security & System Architecture Blueprint

> **Document Version:** 1.0  
> **Audience:** Developers, Technical Leads & Security Auditors  
> **Repository Target:** `coinectra` / `aptos-service`  

---

## Table of Contents
1. [Encryption & Key Management Deep-Dive](#1-encryption--key-management-deep-dive)
   - [1.1 ENCRYPTION_KEY Generation](#11-encryption_key-generation)
   - [1.2 Cryptographic Algorithm & Strength](#12-cryptographic-algorithm--strength)
   - [1.3 Vulnerability & Security Risk Assessment](#13-vulnerability--security-risk-assessment)
   - [1.4 Security Recommendations & Hardening](#14-security-recommendations--hardening)
2. [Sendra Aptos Bot System Architecture Outline](#2-sendra-aptos-bot-system-architecture-outline)
   - [2.1 High-Level System Overview](#21-high-level-system-overview)
   - [2.2 Microservice Breakdown (`aptos-service`)](#22-microservice-breakdown-aptos-service)
   - [2.3 PHP Core Backend (`src/` & `aptos-v2/`)](#23-php-core-backend-src--aptos-v2)
   - [2.4 Deposit Indexer Webhook System](#24-deposit-indexer-webhook-system)
3. [Transaction & Control Flow Mechanics](#3-transaction--control-flow-mechanics)
   - [3.1 Account Creation Flow](#31-account-creation-flow)
   - [3.2 Balance Fetch Flow](#32-balance-fetch-flow)
   - [3.3 Gasless Transfer Flow (SmoothSend Paymaster)](#33-gasless-transfer-flow-smoothsend-paymaster)
   - [3.4 In-Wallet Token Swap Flow (Panora DEX)](#34-in-wallet-token-swap-flow-panora-dex)
   - [3.5 Deposit Monitoring & Webhook Alert Flow](#35-deposit-monitoring--webhook-alert-flow)

---

## 1. Encryption & Key Management Deep-Dive

The private key encryption logic is isolated in [`aptos-service/src/services/keyManager.ts`](file:///var/www/html/coinectra/aptos-service/src/services/keyManager.ts).

### 1.1 ENCRYPTION_KEY Generation
In `keyManager.ts`, the application reads `ENCRYPTION_KEY` (or `NEW_ENCRYPTION_KEY`) from environment variables:

```typescript
const ACTIVE_KEY_HEX = process.env.NEW_ENCRYPTION_KEY?.trim() || process.env.ENCRYPTION_KEY?.trim();
const ENCRYPTION_KEY = Buffer.from(ACTIVE_KEY_HEX, 'hex');
```

* **Expected Format**: A **64-character hexadecimal string**, which converts into a **32-byte (256-bit)** raw binary key.
* **How It Is Generated**: The key must be generated using a Cryptographically Secure Pseudorandom Number Generator (CSPRNG):
  * **Via OpenSSL (Terminal)**:
    ```bash
    openssl rand -hex 32
    ```
  * **Via Node.js**:
    ```javascript
    require('crypto').randomBytes(32).toString('hex')
    ```
* **Key Characteristic**: The key is a raw 256-bit random symmetric key. It is not derived from a passphrase using PBKDF2/Argon2.

---

### 1.2 Cryptographic Algorithm & Strength

```typescript
const ALGORITHM = 'aes-256-gcm';
```

1. **Algorithm**: `aes-256-gcm` (Galois/Counter Mode) — an Authenticated Encryption with Associated Data (AEAD) cipher.
2. **Key Length**: 256 bits (32 bytes). AES-256 is quantum-resistant against Grover's algorithm (providing 128 bits of post-quantum security) and computationally unbreakable with modern hardware.
3. **Authenticity & Integrity Verification (AEAD)**:
   * Every encryption generates a 16-byte authentication tag (`cipher.getAuthTag()`).
   * During decryption, `decipher.setAuthTag(tag)` verifies that the ciphertext and Initialization Vector (IV) have not been modified. If tampered with, decryption immediately throws an error.
4. **Random Initialization Vector (IV)**:
   * A unique 16-byte random IV (`crypto.randomBytes(16)`) is created for *every* key encryption.
   * Ensures two users generating identical private keys produce completely different ciphertexts in the database.
5. **Stored Ciphertext Structure**:
   * Formatted as a colon-separated string: `iv_hex:authTag_hex:ciphertext_hex` stored in the database `encrypted_private_key` column.

---

### 1.3 Vulnerability & Security Risk Assessment

| Risk Vector | Severity | Technical Description & Impact |
| :--- | :---: | :--- |
| **Plaintext `.env` Storage** | 🔴 **HIGH** | The master key is stored in plain text inside `aptos-service/.env`. If an attacker gains file-read access (RCE, local file inclusion, server backup leak, or compromised admin SSH), they can steal both `.env` and the MySQL database, allowing bulk decryption of all user private keys. |
| **Non-Standard IV Length for GCM** | 🟡 **MEDIUM** | `keyManager.ts` uses a 16-byte (128-bit) IV. Standard NIST SP 800-38D guidelines recommend a **12-byte (96-bit)** IV for AES-GCM to avoid GHASH preprocessing overhead and potential hash collision risks at extreme scale. |
| **In-Memory RAM Exposure** | 🟡 **MEDIUM** | Decrypted private keys exist as standard V8 JavaScript strings in memory. V8 immutable string garbage collection prevents immediate zeroizing/wiping from process RAM, leaving keys vulnerable to process RAM dumps. |
| **Lack of IAM & Access Auditing** | 🟡 **MEDIUM** | Any internal request with a valid `X-Internal-Secret` header can request key creation/decryption. There is no per-user authorization checks or audit log for individual key usage. |

---

### 1.4 Security Recommendations & Hardening

1. **Cloud KMS Integration (AWS KMS / GCP KMS / Vault)**:
   * Replace static `.env` key storage with a Cloud KMS Key ARN. Use Envelope Encryption (KMS decrypts data keys in memory).
2. **Standardize IV Length to 96 bits (12 bytes)**:
   * Change `crypto.randomBytes(16)` to `crypto.randomBytes(12)` in [`keyManager.ts`](file:///var/www/html/coinectra/aptos-service/src/services/keyManager.ts#L27).
3. **Use Trusted Execution Environments (TEE)**:
   * Deploy `aptos-service` inside AWS Nitro Enclaves or GCP Confidential VMs so raw keys cannot be extracted even by a root user on the host OS.
4. **Automated Key Rotation & Audit Logging**:
   * Enhance [`rotate-wallets.ts`](file:///var/www/html/coinectra/aptos-service/src/scripts/rotate-wallets.ts) to support re-encrypting existing database keys under a new master key version without replacing user on-chain Aptos wallet addresses.

---

## 2. Sendra Aptos Bot System Architecture Outline

### 2.1 High-Level System Overview

```
                      +------------------------+
                      |     WhatsApp User      |
                      +-----------+------------+
                                  |
                                  | (WhatsApp Message)
                                  v
                      +------------------------+
                      |    PHP Core Backend    |
                      | (MessageController.php)|
                      +-----------+------------+
                                  |
                                  | (HTTP REST + X-Internal-Secret)
                                  v
                      +------------------------+
                      | Node.js aptos-service  | (Express on port 3001)
                      +----+--------------+----+
                           |              |
         (SmoothSend SDK)  |              | (Aptos TS SDK / Panora DEX)
                           v              v
                      +------------------------+
                      |    Aptos Blockchain    |
                      +-----------+------------+
                                  |
                                  | (GraphQL / REST Deposit Events)
                      +-----------v------------+
                      | aptos_indexer_webhook  |
                      |   (Deposit Indexer)    |
                      +------------------------+
```

---

### 2.2 Microservice Breakdown (`aptos-service`)

Located in directory `aptos-service/`:

* **[`src/index.ts`](file:///var/www/html/coinectra/aptos-service/src/index.ts)**:
  * Express server entry point on port `3001` (binds to `127.0.0.1` in production).
  * Exposes `/health`, `/accounts`, and `/transactions` routes.
* **[`src/middleware/internalSecretAuth.ts`](file:///var/www/html/coinectra/aptos-service/src/middleware/internalSecretAuth.ts)**:
  * Verifies `X-Internal-Secret` header against `INTERNAL_API_SECRET` to restrict endpoint access.
* **[`src/services/keyManager.ts`](file:///var/www/html/coinectra/aptos-service/src/services/keyManager.ts)**:
  * Performs AES-256-GCM key encryption and decryption.
* **[`src/services/aptosClient.ts`](file:///var/www/html/coinectra/aptos-service/src/services/aptosClient.ts)**:
  * Initializes `@aptos-labs/ts-sdk` for `MAINNET`, `TESTNET`, or custom RPC node.
* **[`src/config/aptosAssets.ts`](file:///var/www/html/coinectra/aptos-service/src/config/aptosAssets.ts)**:
  * Configures native Circle USDC and Aptos Foundation USDT Fungible Asset metadata addresses.
* **[`src/routes/accounts.ts`](file:///var/www/html/coinectra/aptos-service/src/routes/accounts.ts)**:
  * `POST /accounts/create`: Generates Ed25519 account, returns address & encrypted key.
  * `POST /accounts/balance`: Returns APT, USDC, and USDT balances with 8-second caching and backoff retries.
  * `POST /accounts/validate-key`: Dev utility for key testing.
* **[`src/routes/transactions.ts`](file:///var/www/html/coinectra/aptos-service/src/routes/transactions.ts)**:
  * `POST /transactions/execute-gasless`: Gasless transfer using `@smoothsend/sdk` paymaster.
  * `POST /transactions/execute`: Direct framework transfer where sender pays gas.
  * `POST /transactions/simulate`: Gas simulation route.
  * `POST /transactions/panora-swap`: In-wallet token swap route via Panora DEX aggregator.
* **[`src/scripts/rotate-wallets.ts`](file:///var/www/html/coinectra/aptos-service/src/scripts/rotate-wallets.ts)**:
  * CLI tool for wallet rotation (`npx tsx src/scripts/rotate-wallets.ts --dry-run`).

---

### 2.3 PHP Core Backend (`src/` & `aptos-v2/`)

* **[`src/Controllers/MessageController.php`](file:///var/www/html/coinectra/src/Controllers/MessageController.php)**:
  * Orchestrates WhatsApp command flows, PIN checks, user wallet records, and off-ramp processing.
* **[`aptos-v2/src/Support/AptosServiceClient.php`](file:///var/www/html/coinectra/aptos-v2/src/Support/AptosServiceClient.php)**:
  * cURL client wrapper for calling `aptos-service` HTTP endpoints securely.

---

### 2.4 Deposit Indexer Webhook System

* **[`aptos_indexer_webhook.php`](file:///var/www/html/coinectra/aptos_indexer_webhook.php)**:
  * Monitors Aptos blockchain GraphQL/Nodit endpoints for `FungibleAsset::DepositEvent` transfers.
  * Matches recipient address with `users` table and notifies WhatsApp user automatically.

---

## 3. Transaction & Control Flow Mechanics

### 3.1 Account Creation Flow
1. User requests wallet creation on WhatsApp.
2. PHP calls `POST /accounts/create` on `aptos-service`.
3. `aptos-service` calls `Account.generate()`, encrypts private key with `encryptKey()`.
4. Returns `aptos_address` and `encrypted_private_key`.
5. PHP stores both values in MySQL `users` table.

---

### 3.2 Balance Fetch Flow
1. User checks balance on WhatsApp.
2. PHP calls `POST /accounts/balance` on `aptos-service`.
3. `aptos-service` checks 8-second memory cache. If missed, queries Aptos RPC for APT coin store and USDC/USDT Fungible Asset stores.
4. Returns formatted balances (`APT`, `USDC`, `USDT`) to PHP.

---

### 3.3 Gasless Transfer Flow (SmoothSend Paymaster)
1. User enters send command & confirms PIN on WhatsApp.
2. PHP calls `POST /transactions/execute-gasless` on `aptos-service`.
3. `aptos-service` decrypts sender's private key in memory.
4. Builds payload via Aptos SDK, signs with sender authenticator.
5. Submits to **SmoothSend Relayer** (sponsor pays gas).
6. **Chain Recovery Safeguard**: If SmoothSend API times out, `submitGaslessWithChainRecovery()` polls the Aptos ledger for sequence number to verify if transaction landed on-chain.
7. Returns transaction hash & explorer URL to PHP -> WhatsApp confirmation sent.

---

### 3.4 In-Wallet Token Swap Flow (Panora DEX)
1. User requests swap (e.g. USDT to USDC).
2. PHP calls `POST /transactions/panora-swap` on `aptos-service`.
3. `aptos-service` queries Panora DEX aggregator for route, builds swap transaction, signs, and executes.
4. Returns swap result to PHP.

---

### 3.5 Deposit Monitoring & Webhook Alert Flow
1. External user sends crypto to user's Sendra Aptos address.
2. `aptos_indexer_webhook.php` detects `FungibleAsset::DepositEvent` via GraphQL.
3. Matches recipient address to user in MySQL database.
4. Sends WhatsApp notification: *"You received X USDC"*.
