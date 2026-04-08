"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.transactionRoutes = void 0;
// src/routes/transactions.ts
const express_1 = require("express");
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
const aptosClient_js_1 = require("../services/aptosClient.js");
const keyManager_js_1 = require("../services/keyManager.js");
const sdk_1 = require("@smoothsend/sdk");
const router = (0, express_1.Router)();
exports.transactionRoutes = router;
const ASSET_METADATA = {
    USDC: '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832',
    USDT: '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a',
};
const smoothClient = new sdk_1.ScriptComposerClient({
    apiKey: process.env.SMOOTHSEND_API_KEY,
    network: 'testnet',
});
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
        const privateKeyHex = (0, keyManager_js_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        if (senderAccount.accountAddress.toString().toLowerCase() !== sender_address.toLowerCase()) {
            res.status(400).json({
                success: false,
                error: 'sender_address does not match the decrypted private key',
            });
            return;
        }
        const rawAmount = Math.round(parsedAmount * 1000000);
        console.log(`[transactions/execute] ${JSON.stringify({
            sender: sender_address,
            recipient: recipient,
            amount: String(rawAmount),
            assetType: metadataAddress,
            decimals: 6,
            symbol: upperCurrency,
        })}`);
        // ── CHANGED: Build via SmoothSend instead of aptos.transaction.build.simple ──
        const { transactionBytes, fee, totalAmount } = await smoothClient.buildTransfer({
            sender: sender_address,
            recipient: recipient,
            amount: String(rawAmount),
            assetType: metadataAddress,
            decimals: 6,
            symbol: upperCurrency
        });
        // ── Deserialize the transaction bytes SmoothSend returned ──
        const txBytes = new Uint8Array(transactionBytes);
        const transaction = ts_sdk_1.SimpleTransaction.deserialize(new ts_sdk_1.Deserializer(txBytes));
        // ── Simulate against the SmoothSend-built tx ──
        const simulation = await aptosClient_js_1.aptos.transaction.simulate.simple({
            signerPublicKey: senderAccount.publicKey,
            transaction: transaction,
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
        // ── CHANGED: Sign only (not sign+submit) ──
        const signedTx = aptosClient_js_1.aptos.transaction.sign({ signer: senderAccount, transaction });
        // ── CHANGED: Submit via SmoothSend relayer ──
        const result = await smoothClient.submitSignedTransaction({
            transactionBytes: Array.from(txBytes),
            authenticatorBytes: Array.from(signedTx.bcsToBytes()),
        });
        console.log(`[transactions/execute] Confirmed tx: ${result.txHash}`);
        res.json({
            success: true,
            tx_hash: result.txHash,
            explorer_url: `https://explorer.aptoslabs.com/txn/${result.txHash}?network=testnet`,
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
        const privateKeyHex = (0, keyManager_js_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        const rawAmount = Math.round(parseFloat(amount) * 1000000);
        const tx = await aptosClient_js_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: '0x1::primary_fungible_store::transfer',
                typeArguments: ['0x1::fungible_asset::Metadata'],
                functionArguments: [metadataAddress, recipient, rawAmount],
            },
        });
        const simulation = await aptosClient_js_1.aptos.transaction.simulate.simple({
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
