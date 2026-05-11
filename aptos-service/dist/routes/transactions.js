"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.transactionRoutes = void 0;
// src/routes/transactions.ts
const express_1 = require("express");
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
const aptosClient_js_1 = require("../services/aptosClient.js");
const keyManager_js_1 = require("../services/keyManager.js");
const sdk_1 = require("@smoothsend/sdk");
const internalSecretAuth_js_1 = require("../middleware/internalSecretAuth.js");
const aptosAssets_js_1 = require("../config/aptosAssets.js");
const router = (0, express_1.Router)();
exports.transactionRoutes = router;
/** Explorers show `0x1::aptos_account::transfer_fungible_assets` instead of SmoothSend script payloads. Sender pays gas (needs APT). */
function useAptosAccountFungibleTransfer() {
    const v = process.env.APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER;
    if (v === undefined || v === '') {
        return false;
    }
    return ['1', 'true', 'yes', 'on'].includes(v.trim().toLowerCase());
}
/**
 * Force SmoothSend relay (gasless) regardless of APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER.
 * Use for offramp funding to third-party deposit addresses (e.g. 1Click).
 */
function forceSmoothSend() {
    const v = process.env.APTOS_FORCE_SMOOTHSEND;
    if (v === undefined || v === '') {
        return false;
    }
    return ['1', 'true', 'yes', 'on'].includes(v.trim().toLowerCase());
}
/**
 * Default SDK max gas can exceed small hot-wallet APT; FA transfers are cheap.
 */
function frameworkTransferBuildOptions() {
    const raw = process.env.APTOS_TRANSFER_MAX_GAS_AMOUNT?.trim();
    const parsed = raw ? parseInt(raw, 10) : 50000;
    const maxGasAmount = Number.isFinite(parsed) && parsed >= 2000 ? parsed : 50000;
    return { maxGasAmount };
}
let smoothClientSingleton = null;
let smoothSubmitterSingleton = null;
function getSmoothClient() {
    if (!smoothClientSingleton) {
        const apiKey = process.env.SMOOTHSEND_API_KEY?.trim();
        if (!apiKey) {
            throw new Error('SMOOTHSEND_API_KEY is required for gasless transfers, or set APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER=1 for direct framework transfers (sender pays gas)');
        }
        smoothClientSingleton = new sdk_1.ScriptComposerClient({
            apiKey,
            network: (0, aptosAssets_js_1.smoothSendNetwork)(),
        });
    }
    return smoothClientSingleton;
}
function getSmoothSubmitter() {
    if (!smoothSubmitterSingleton) {
        const apiKey = process.env.SMOOTHSEND_API_KEY?.trim();
        if (!apiKey) {
            throw new Error('SMOOTHSEND_API_KEY is required for gasless transactions');
        }
        smoothSubmitterSingleton = new sdk_1.SmoothSendTransactionSubmitter({
            apiKey,
            network: (0, aptosAssets_js_1.smoothSendNetwork)(),
        });
    }
    return smoothSubmitterSingleton;
}
router.use(internalSecretAuth_js_1.requireInternalSecret);
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
 *
 * Env:
 *   APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER=1 — 0x1::aptos_account::transfer_fungible_assets (explorer-friendly; user pays gas)
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
    const metadataAddress = (0, aptosAssets_js_1.metadataForSymbol)(upperCurrency);
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
        const forceSmooth = forceSmoothSend();
        const viaFramework = forceSmooth ? false : useAptosAccountFungibleTransfer();
        console.log(`[transactions/execute] ${JSON.stringify({
            sender: sender_address,
            recipient,
            amount: String(rawAmount),
            assetType: metadataAddress,
            decimals: 6,
            symbol: upperCurrency,
            transfer_mode: viaFramework ? 'aptos_account::transfer_fungible_assets' : 'smoothsend',
            force_smoothsend: forceSmooth,
        })}`);
        let txHash;
        if (viaFramework) {
            const transaction = await aptosClient_js_1.aptos.transaction.build.simple({
                sender: sender_address,
                data: {
                    function: '0x1::aptos_account::transfer_fungible_assets',
                    typeArguments: [],
                    functionArguments: [metadataAddress, recipient, rawAmount],
                },
                options: frameworkTransferBuildOptions(),
            });
            const simulation = await aptosClient_js_1.aptos.transaction.simulate.simple({
                signerPublicKey: senderAccount.publicKey,
                transaction,
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
            const pending = await aptosClient_js_1.aptos.signAndSubmitTransaction({
                signer: senderAccount,
                transaction,
            });
            txHash = pending.hash;
        }
        else {
            const smoothClient = getSmoothClient();
            const { transactionBytes } = await smoothClient.buildTransfer({
                sender: sender_address,
                recipient,
                amount: String(rawAmount),
                assetType: metadataAddress,
                decimals: 6,
                symbol: upperCurrency,
            });
            const txBytes = new Uint8Array(transactionBytes);
            const transaction = ts_sdk_1.SimpleTransaction.deserialize(new ts_sdk_1.Deserializer(txBytes));
            const simulation = await aptosClient_js_1.aptos.transaction.simulate.simple({
                signerPublicKey: senderAccount.publicKey,
                transaction,
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
            const signedTx = aptosClient_js_1.aptos.transaction.sign({ signer: senderAccount, transaction });
            const result = await smoothClient.submitSignedTransaction({
                transactionBytes: Array.from(txBytes),
                authenticatorBytes: Array.from(signedTx.bcsToBytes()),
            });
            txHash = result.txHash;
        }
        console.log(`[transactions/execute] Confirmed tx: ${txHash}`);
        res.json({
            success: true,
            tx_hash: txHash,
            explorer_url: (0, aptosAssets_js_1.aptosExplorerTxnUrl)(txHash),
            transfer_mode: viaFramework ? 'aptos_account::transfer_fungible_assets' : 'smoothsend',
            force_smoothsend: forceSmooth,
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
    const metadataAddress = (0, aptosAssets_js_1.metadataForSymbol)(upperCurrency);
    if (!metadataAddress) {
        res.status(400).json({ success: false, error: `Unsupported currency: ${currency}` });
        return;
    }
    try {
        const privateKeyHex = (0, keyManager_js_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        const rawAmount = Math.round(parseFloat(amount) * 1000000);
        const useFramework = useAptosAccountFungibleTransfer();
        const entryFn = useFramework
            ? '0x1::aptos_account::transfer_fungible_assets'
            : '0x1::primary_fungible_store::transfer';
        const typeArguments = useFramework ? [] : ['0x1::fungible_asset::Metadata'];
        const tx = await aptosClient_js_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: entryFn,
                typeArguments,
                functionArguments: [metadataAddress, recipient, rawAmount],
            },
            ...(useFramework ? { options: frameworkTransferBuildOptions() } : {}),
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
// ─── POST /transactions/execute-gasless ───────────────────────────────────────
/**
 * Signs and submits any Aptos entry-function transaction using SmoothSend credits (Method 2).
 *
 * Body:
 *   sender_address        — Aptos address of sender
 *   encrypted_private_key — AES-256-GCM encrypted private key from DB
 *   function              — Full entry-function e.g. "0x1::coin::transfer"
 *   type_arguments        — Optional array of type arguments
 *   function_arguments    — Optional array of function args (JSON-serializable)
 *   options               — Optional tx build options (gasUnitPrice, maxGasAmount, etc.)
 *
 * Notes:
 * - Transaction is built with withFeePayer=true.
 * - functionName is forwarded to SmoothSend (required for TransactionSubmitter billing path).
 */
router.post('/execute-gasless', async (req, res) => {
    const { sender_address, encrypted_private_key, function: functionName, type_arguments, function_arguments, 
    // Convenience for fungible transfers (so callers can behave like /transactions/execute)
    recipient, amount, currency, options, } = req.body;
    const hasDirectFn = !!functionName;
    const hasFungibleShortcut = !!recipient && !!amount && !!currency;
    if (!sender_address || !encrypted_private_key || (!hasDirectFn && !hasFungibleShortcut)) {
        res.status(400).json({
            success: false,
            error: 'Missing required fields: sender_address, encrypted_private_key, and either (function) or (recipient, amount, currency)',
        });
        return;
    }
    try {
        const privateKeyHex = (0, keyManager_js_1.decryptKey)(encrypted_private_key);
        const privateKey = new ts_sdk_1.Ed25519PrivateKey(privateKeyHex);
        const senderAccount = ts_sdk_1.Account.fromPrivateKey({ privateKey });
        if (senderAccount.accountAddress.toString().toLowerCase() !== String(sender_address).toLowerCase()) {
            res.status(400).json({
                success: false,
                error: 'sender_address does not match the decrypted private key',
            });
            return;
        }
        let resolvedFunctionName;
        let resolvedTypeArguments = [];
        let resolvedFunctionArguments = [];
        if (hasDirectFn) {
            resolvedFunctionName = String(functionName);
            resolvedTypeArguments = Array.isArray(type_arguments) ? type_arguments : [];
            resolvedFunctionArguments = Array.isArray(function_arguments) ? function_arguments : [];
        }
        else {
            const upperCurrency = String(currency).toUpperCase();
            const metadataAddress = (0, aptosAssets_js_1.metadataForSymbol)(upperCurrency);
            if (!metadataAddress) {
                res.status(400).json({
                    success: false,
                    error: `Unsupported currency "${currency}". Supported: USDC, USDT`,
                });
                return;
            }
            const parsedAmount = parseFloat(String(amount));
            if (isNaN(parsedAmount) || parsedAmount <= 0) {
                res.status(400).json({ success: false, error: 'amount must be a positive number' });
                return;
            }
            const rawAmount = Math.round(parsedAmount * 1000000);
            resolvedFunctionName = '0x1::aptos_account::transfer_fungible_assets';
            resolvedFunctionArguments = [metadataAddress, recipient, rawAmount];
        }
        const transaction = await aptosClient_js_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: resolvedFunctionName,
                typeArguments: resolvedTypeArguments,
                functionArguments: resolvedFunctionArguments,
            },
            options: typeof options === 'object' && options ? options : undefined,
            withFeePayer: true,
        });
        const simulation = await aptosClient_js_1.aptos.transaction.simulate.simple({
            signerPublicKey: senderAccount.publicKey,
            transaction,
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
        const senderAuthenticator = aptosClient_js_1.aptos.transaction.sign({ signer: senderAccount, transaction });
        const submitter = getSmoothSubmitter();
        const pending = await submitter.submitTransaction({
            // aptos-service uses a single Aptos instance; use its config for network routing.
            aptosConfig: aptosClient_js_1.aptos.config,
            transaction: transaction,
            senderAuthenticator: senderAuthenticator,
            pluginParams: { functionName: String(resolvedFunctionName) },
        });
        res.json({
            success: true,
            tx_hash: pending.hash,
            explorer_url: (0, aptosAssets_js_1.aptosExplorerTxnUrl)(pending.hash),
            submit_mode: 'smoothsend_transaction_submitter',
            function: resolvedFunctionName,
        });
    }
    catch (e) {
        console.error('[transactions/execute-gasless] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
