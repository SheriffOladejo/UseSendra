"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.accountRoutes = void 0;
// src/routes/accounts.ts
const express_1 = require("express");
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
const aptosClient_js_1 = require("../services/aptosClient.js");
const keyManager_js_1 = require("../services/keyManager.js");
const internalSecretAuth_js_1 = require("../middleware/internalSecretAuth.js");
const aptosAssets_js_1 = require("../config/aptosAssets.js");
const router = (0, express_1.Router)();
exports.accountRoutes = router;
router.use(internalSecretAuth_js_1.requireInternalSecret);
// ─── POST /accounts/create ────────────────────────────────────────────────────
/**
 * Generates a new Aptos account for a user.
 *
 * Body: { phone_number: string }
 *
 * Returns:
 *   aptos_address          — store in users table
 *   encrypted_private_key  — store in users table (encrypted, safe to store)
 *   private_key_for_display — show to user ONCE then discard, never store plain
 */
router.post('/create', async (req, res) => {
    const { phone_number } = req.body;
    if (!phone_number) {
        res.status(400).json({ success: false, error: 'phone_number is required' });
        return;
    }
    try {
        // 1. Generate a brand new Ed25519 Aptos account
        const account = ts_sdk_1.Account.generate();
        const address = account.accountAddress.toString();
        const privateKeyHex = account.privateKey.toString();
        // 2. Encrypt private key — this is what gets stored in your DB
        const encryptedKey = (0, keyManager_js_1.encryptKey)(privateKeyHex);
        console.log(`[accounts/create] New account created for ${phone_number}: ${address}`);
        res.json({
            success: true,
            aptos_address: address,
            encrypted_private_key: encryptedKey,
            // Return plain key ONCE so PHP can show it to user as backup
            // PHP must NOT store this — display and discard
            private_key_for_display: 'privateKeyHex',
        });
    }
    catch (e) {
        console.error('[accounts/create] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
// ─── POST /accounts/balance ───────────────────────────────────────────────────
/**
 * Returns APT, USDC, and USDT balances for a given address.
 *
 * Body: { aptos_address: string }
 */
router.post('/balance', async (req, res) => {
    const { aptos_address } = req.body;
    if (!aptos_address) {
        res.status(400).json({ success: false, error: 'aptos_address is required' });
        return;
    }
    const usdcMeta = (0, aptosAssets_js_1.metadataForSymbol)('USDC');
    const usdtMeta = (0, aptosAssets_js_1.metadataForSymbol)('USDT');
    if (!usdcMeta || !usdtMeta) {
        res.status(500).json({
            success: false,
            error: 'USDC/USDT metadata not configured for APTOS_NETWORK (set APTOS_NETWORK or metadata env overrides).',
        });
        return;
    }
    try {
        // APT balance
        let aptRaw = 0;
        try {
            const aptRes = await aptosClient_js_1.aptos.getAccountResource({
                accountAddress: aptos_address,
                resourceType: '0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>',
            });
            aptRaw = Number(aptRes.coin?.value ?? 0);
        }
        catch {
            // Account not yet on-chain — balance is 0
        }
        // Fungible asset balances (USDC / USDT)
        const getFaBalance = async (metadataAddress) => {
            try {
                const result = await aptosClient_js_1.aptos.view({
                    payload: {
                        function: '0x1::primary_fungible_store::balance',
                        typeArguments: ['0x1::fungible_asset::Metadata'],
                        functionArguments: [aptos_address, metadataAddress],
                    },
                });
                return Number(result[0] ?? 0);
            }
            catch {
                return 0;
            }
        };
        const [usdcRaw, usdtRaw] = await Promise.all([
            getFaBalance(usdcMeta),
            getFaBalance(usdtMeta),
        ]);
        res.json({
            success: true,
            address: aptos_address,
            balances: {
                APT: (aptRaw / 1e8).toFixed(8),
                USDC: (usdcRaw / 1e6).toFixed(6),
                USDT: (usdtRaw / 1e6).toFixed(6),
            },
            raw: { APT: aptRaw, USDC: usdcRaw, USDT: usdtRaw },
        });
    }
    catch (e) {
        console.error('[accounts/balance] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
// ─── POST /accounts/validate-key ─────────────────────────────────────────────
/**
 * Dev/test utility — verifies that an encrypted key round-trips correctly.
 * Do NOT expose this in production.
 *
 * Body: { encrypted_private_key: string }
 */
router.post('/validate-key', (req, res) => {
    const { encrypted_private_key } = req.body;
    if (!encrypted_private_key) {
        res.status(400).json({ success: false, error: 'encrypted_private_key is required' });
        return;
    }
    try {
        const decrypted = (0, keyManager_js_1.decryptKey)(encrypted_private_key);
        // Only return a partial key so this can't be used to extract full keys in prod
        res.json({
            success: true,
            decrypted_preview: decrypted.slice(0, 8) + '...',
            length: decrypted.length,
        });
    }
    catch (e) {
        res.status(400).json({ success: false, error: 'Decryption failed: ' + e.message });
    }
});
