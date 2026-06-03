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
        const viaFramework = forceSmooth ? false : (0, aptosAssets_js_1.useAptosAccountFungibleTransfer)();
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
            if (result && result.success === false) {
                const vmStatus = result.vmStatus;
                const err = result.error ||
                    vmStatus ||
                    'SmoothSend relayer rejected the transaction';
                res.status(400).json({ success: false, error: err, relayer: result });
                return;
            }
            if (!result?.txHash) {
                res.status(400).json({
                    success: false,
                    error: 'SmoothSend did not return a transaction hash',
                    relayer: result,
                });
                return;
            }
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
        const useFramework = (0, aptosAssets_js_1.useAptosAccountFungibleTransfer)();
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
        const directFnName = hasDirectFn ? String(functionName) : null;
        const directFnArgs = Array.isArray(function_arguments) ? function_arguments : [];
        const directFnHasArgs = directFnArgs.length > 0;
        // If caller provided `function` but omitted args, and also provided (recipient, amount, currency),
        // prefer the shortcut path to avoid building an invalid transfer_fungible_assets call.
        const shouldUseShortcut = hasFungibleShortcut &&
            (!hasDirectFn ||
                !directFnName ||
                !directFnHasArgs ||
                directFnName === '0x1::aptos_account::transfer_fungible_assets');
        if (!shouldUseShortcut) {
            resolvedFunctionName = directFnName;
            resolvedTypeArguments = Array.isArray(type_arguments) ? type_arguments : [];
            resolvedFunctionArguments = directFnArgs;
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
        if (resolvedFunctionName === '0x1::aptos_account::transfer_fungible_assets' &&
            (!Array.isArray(resolvedFunctionArguments) || resolvedFunctionArguments.length !== 3)) {
            res.status(400).json({
                success: false,
                error: 'Invalid arguments for 0x1::aptos_account::transfer_fungible_assets. Expected 3 function_arguments: [metadataAddress, recipient, rawAmount] (or omit "function" and pass recipient/amount/currency).',
            });
            return;
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
        console.log('smoothsend payload');
        console.log({
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
// ─── POST /transactions/panora-swap ───────────────────────────────────────────
/**
 * Panora USDT → USDC on Aptos; output credited to sender (`toWalletAddress` = sender).
 * Used before 1Click Stellar bridge so USDC remains in the user wallet if 1Click fails later.
 */
function panoraChainId() {
    const raw = process.env.SENDRA_PANORA_CHAIN_ID?.trim() || process.env.PANORA_CHAIN_ID?.trim();
    if (raw) {
        return raw;
    }
    return (process.env.APTOS_NETWORK || 'TESTNET').toUpperCase() === 'MAINNET' ? '1' : '2';
}
function panoraSlippagePercent() {
    return process.env.SENDRA_PANORA_SLIPPAGE_PERCENT?.trim() || process.env.PANORA_SLIPPAGE_PERCENT?.trim() || '1';
}
function panoraUsdtMetadata() {
    return (process.env.SENDRA_PANORA_USDT_METADATA?.trim() ||
        process.env.PANORA_USDT_METADATA?.trim() ||
        (0, aptosAssets_js_1.metadataForSymbol)('USDT') ||
        '');
}
function panoraUsdcMetadata() {
    return (process.env.SENDRA_PANORA_USDC_METADATA?.trim() ||
        process.env.PANORA_USDC_METADATA?.trim() ||
        (0, aptosAssets_js_1.metadataForSymbol)('USDC') ||
        '');
}
router.post('/panora-swap', async (req, res) => {
    const { sender_address, encrypted_private_key, from_token_amount, to_token_amount, direction: directionRaw, } = req.body;
    const direction = String(directionRaw || 'usdt_to_usdc').toLowerCase() === 'usdc_to_usdt' ? 'usdc_to_usdt' : 'usdt_to_usdc';
    if (!sender_address || !encrypted_private_key) {
        res.status(400).json({
            success: false,
            error: 'Missing required fields: sender_address, encrypted_private_key',
        });
        return;
    }
    const hasExactIn = from_token_amount != null && String(from_token_amount).trim() !== '';
    const hasExactOut = to_token_amount != null && String(to_token_amount).trim() !== '';
    if (!hasExactIn && !hasExactOut) {
        res.status(400).json({
            success: false,
            error: 'Provide from_token_amount (exact-in) or to_token_amount (exact-out)',
        });
        return;
    }
    const apiKey = process.env.SENDRA_PANORA_API_KEY?.trim() || process.env.PANORA_API_KEY?.trim();
    if (!apiKey) {
        res.status(500).json({ success: false, error: 'PANORA_API_KEY / SENDRA_PANORA_API_KEY not set on aptos-service' });
        return;
    }
    const usdtMeta = panoraUsdtMetadata();
    const usdcMeta = panoraUsdcMetadata();
    if (!usdtMeta || !usdcMeta) {
        res.status(500).json({ success: false, error: 'USDT/USDC metadata not configured for APTOS_NETWORK' });
        return;
    }
    const fromTokenMeta = direction === 'usdc_to_usdt' ? usdcMeta : usdtMeta;
    const toTokenMeta = direction === 'usdc_to_usdt' ? usdtMeta : usdcMeta;
    const baseUrl = (process.env.SENDRA_PANORA_API_BASE_URL || process.env.PANORA_API_BASE_URL || 'https://api.panora.exchange').replace(/\/$/, '');
    try {
        const quoteRes = await fetch(`${baseUrl}/swap`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'x-api-key': apiKey,
            },
            body: JSON.stringify(hasExactOut
                ? {
                    chainId: panoraChainId(),
                    fromTokenAddress: fromTokenMeta,
                    toTokenAddress: toTokenMeta,
                    toTokenAmount: String(to_token_amount),
                    toWalletAddress: String(sender_address),
                    slippagePercentage: panoraSlippagePercent(),
                }
                : {
                    chainId: panoraChainId(),
                    fromTokenAddress: fromTokenMeta,
                    toTokenAddress: toTokenMeta,
                    fromTokenAmount: String(from_token_amount),
                    toWalletAddress: String(sender_address),
                    slippagePercentage: panoraSlippagePercent(),
                }),
        });
        const quoteRaw = await quoteRes.text();
        let quoteJson;
        try {
            quoteJson = JSON.parse(quoteRaw);
        }
        catch {
            res.status(502).json({ success: false, error: 'Panora returned invalid JSON', http_code: quoteRes.status });
            return;
        }
        if (!quoteRes.ok) {
            const errMsg = typeof quoteJson?.message === 'string'
                ? quoteJson.message
                : typeof quoteJson?.error === 'string'
                    ? quoteJson.error
                    : `Panora HTTP ${quoteRes.status}`;
            res.status(quoteRes.status >= 400 && quoteRes.status < 600 ? quoteRes.status : 502).json({
                success: false,
                error: errMsg,
                http_code: quoteRes.status,
                data: quoteJson,
            });
            return;
        }
        const quotes = quoteJson?.quotes;
        if (!Array.isArray(quotes) || quotes.length === 0 || !quotes[0]?.txData) {
            res.status(502).json({ success: false, error: 'Panora quote missing txData' });
            return;
        }
        const best = quotes[0];
        const txData = best.txData;
        const resolvedFunctionName = String(txData.function);
        const resolvedTypeArguments = Array.isArray(txData.type_arguments) ? txData.type_arguments : [];
        const resolvedFunctionArguments = Array.isArray(txData.arguments) ? txData.arguments : [];
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
        const transaction = await aptosClient_js_1.aptos.transaction.build.simple({
            sender: sender_address,
            data: {
                function: resolvedFunctionName,
                typeArguments: resolvedTypeArguments,
                functionArguments: resolvedFunctionArguments,
            },
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
                error: `Panora swap simulation failed: ${simResult.vm_status}`,
                simulation: simResult,
            });
            return;
        }
        const senderAuthenticator = aptosClient_js_1.aptos.transaction.sign({ signer: senderAccount, transaction });
        const submitter = getSmoothSubmitter();
        const pending = await submitter.submitTransaction({
            aptosConfig: aptosClient_js_1.aptos.config,
            transaction: transaction,
            senderAuthenticator: senderAuthenticator,
            pluginParams: { functionName: String(resolvedFunctionName) },
        });
        const quotedTo = best.toTokenAmount != null ? String(best.toTokenAmount) : hasExactOut ? String(to_token_amount) : null;
        const quotedMin = best.minToTokenAmount != null
            ? String(best.minToTokenAmount)
            : quotedTo;
        res.json({
            success: true,
            tx_hash: pending.hash,
            explorer_url: (0, aptosAssets_js_1.aptosExplorerTxnUrl)(pending.hash),
            submit_mode: 'smoothsend_panora_swap',
            direction,
            function: resolvedFunctionName,
            panora: {
                from_token_amount: best.fromTokenAmount != null ? String(best.fromTokenAmount) : hasExactIn ? String(from_token_amount) : null,
                to_token_amount: quotedTo,
                min_to_token_amount: quotedMin,
            },
        });
    }
    catch (e) {
        console.error('[transactions/panora-swap] Error:', e.message);
        res.status(500).json({ success: false, error: e.message });
    }
});
