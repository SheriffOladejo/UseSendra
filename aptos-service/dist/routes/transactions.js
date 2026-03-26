"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.transactionRoutes = void 0;
// src/routes/transactions.ts
const express_1 = require("express");
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
const aptosClient_1 = require("../services/aptosClient");
const keyManager_1 = require("../services/keyManager");
const router = (0, express_1.Router)();
exports.transactionRoutes = router;
const ASSET_METADATA = {
    USDC: '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832',
    USDT: '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a',
};
// ─── Middleware: verify internal secret ───────────────────────────────────────
router.use((req, res, next) => {
    const secret = req.headers['x-internal-secret'];
    if (secret !== process.env.INTERNAL_API_SECRET) {
        res.status(401).json({ success: false, error: 'Unauthorized' });
        return;
    }
    next();
});
// ─── POST /transactions/execute ───────────────────────────────────────────────
/**
 * Signs and submits a USDC or USDT transfer on Aptos.
 *
 * Body:
 *   sender_address        — Aptos address of sender
 *   encrypted_private_key — AES-256-GCM encrypted private key from DB
 *   recipient             — Recipient Aptos address
 *   amount                — Human-readable amount e.g. "10.50"
 *   currency              — "USDC" or "USDT"
 */
router.post('/execute', async (req, res) => {
    const { sender_address, encrypted_private_key, recipient, amount, currency } = req.body;
    // ── Validate inputs ──
    if (!sender_address || !encrypted_private_key || !recipient || !amount || !currency) {
        res.status(400).json({
            success: false,
            error: 'Missing required fields: sender_address, encrypted_private_key, recipient, amount, currency',
        });
        return;
    }
    const upperCurrency = currency.toUpperCase();
    const metadataAddress = ASSET_METADATA[upperCurrency];
    if (!metadataAddress) {
        res.status(400).json({
            success: false,
            error: `Unsupported currency "${currency}". Supported: USDC, USDT`,
        });
        return;
    }
    const parsedAmount = parseFloat(amount);
    if (isNaN(parsedAmount) || parsedAmount <= 0) {
        res.status(400).json({ success: false, error: 'amount must be a positive number' });
        return;
    }
    try {
        // ── Decrypt private key (lives in memory only for duration of request) ──
        const privateKeyHex = (0, keyManager_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        // Confirm the derived address matches what was passed in (safety check)
        if (senderAccount.accountAddress.toString().toLowerCase() !== sender_address.toLowerCase()) {
            res.status(400).json({
                success: false,
                error: 'sender_address does not match the decrypted private key',
            });
            return;
        }
        // ── Build transaction ──
        // Amount in smallest unit — USDC/USDT have 6 decimal places
        const rawAmount = Math.round(parsedAmount * 1000000);
        console.log(`[transactions/execute] ${sender_address} → ${recipient}: ${parsedAmount} ${upperCurrency} (raw: ${rawAmount})`);
        const tx = await aptosClient_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: '0x1::primary_fungible_store::transfer',
                typeArguments: ['0x1::fungible_asset::Metadata'],
                functionArguments: [metadataAddress, recipient, rawAmount],
            },
        });
        // ── Simulate first to catch errors before paying gas ──
        const simulation = await aptosClient_1.aptos.transaction.simulate.simple({
            signerPublicKey: senderAccount.publicKey,
            transaction: tx,
        });
        const simResult = simulation[0];
        if (!simResult.success) {
            res.status(400).json({
                success: false,
                error: `Transaction simulation failed: ${simResult.vm_status}`,
                simulation: simResult,
            });
            return;
        }
        // ── Sign and submit ──
        const submitted = await aptosClient_1.aptos.signAndSubmitTransaction({
            transaction: tx,
            signer: senderAccount,
        });
        // ── Wait for confirmation ──
        const confirmed = await aptosClient_1.aptos.waitForTransaction({
            transactionHash: submitted.hash,
        });
        console.log(`[transactions/execute] Confirmed tx: ${submitted.hash}`);
        res.json({
            success: true,
            tx_hash: submitted.hash,
            explorer_url: `https://explorer.aptoslabs.com/txn/${submitted.hash}?network=testnet`,
            gas_used: confirmed.gas_used,
        });
    }
    catch (e) {
        console.error('[transactions/execute] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
// ─── POST /transactions/simulate ─────────────────────────────────────────────
/**
 * Simulates a transaction without submitting it.
 * Useful for showing users estimated gas before they confirm with PIN.
 *
 * Body: same as /execute
 */
router.post('/simulate', async (req, res) => {
    const { sender_address, encrypted_private_key, recipient, amount, currency } = req.body;
    if (!sender_address || !encrypted_private_key || !recipient || !amount || !currency) {
        res.status(400).json({ success: false, error: 'Missing required fields' });
        return;
    }
    const upperCurrency = currency.toUpperCase();
    const metadataAddress = ASSET_METADATA[upperCurrency];
    if (!metadataAddress) {
        res.status(400).json({ success: false, error: `Unsupported currency: ${currency}` });
        return;
    }
    try {
        const privateKeyHex = (0, keyManager_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        const rawAmount = Math.round(parseFloat(amount) * 1000000);
        const tx = await aptosClient_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: '0x1::primary_fungible_store::transfer',
                typeArguments: ['0x1::fungible_asset::Metadata'],
                functionArguments: [metadataAddress, recipient, rawAmount],
            },
        });
        const simulation = await aptosClient_1.aptos.transaction.simulate.simple({
            signerPublicKey: senderAccount.publicKey,
            transaction: tx,
        });
        const simResult = simulation[0];
        res.json({
            success: true,
            will_succeed: simResult.success,
            vm_status: simResult.vm_status,
            gas_used: simResult.gas_used,
            estimated_gas_cost_apt: (Number(simResult.gas_used) * Number(simResult.gas_unit_price)) / 1e8,
        });
    }
    catch (e) {
        console.error('[transactions/simulate] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
