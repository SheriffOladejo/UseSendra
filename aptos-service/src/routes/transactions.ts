// src/routes/transactions.ts
import { Router, Request, Response } from 'express';
import { Account, Deserializer, Ed25519PrivateKey, SimpleTransaction } from '@aptos-labs/ts-sdk';
import { aptos } from '../services/aptosClient.js';
import { decryptKey } from '../services/keyManager.js';
import { ScriptComposerClient, SmoothSendTransactionSubmitter } from '@smoothsend/sdk';
import { requireInternalSecret } from '../middleware/internalSecretAuth.js';
import { aptosExplorerTxnUrl, metadataForSymbol, smoothSendNetwork } from '../config/aptosAssets.js';

const router = Router();

/** Explorers show `0x1::aptos_account::transfer_fungible_assets` instead of SmoothSend script payloads. Sender pays gas (needs APT). */
function useAptosAccountFungibleTransfer(): boolean {
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
function forceSmoothSend(): boolean {
  const v = process.env.APTOS_FORCE_SMOOTHSEND;
  if (v === undefined || v === '') {
    return false;
  }
  return ['1', 'true', 'yes', 'on'].includes(v.trim().toLowerCase());
}

/**
 * Default SDK max gas can exceed small hot-wallet APT; FA transfers are cheap.
 */
function frameworkTransferBuildOptions(): { maxGasAmount: number } {
  const raw = process.env.APTOS_TRANSFER_MAX_GAS_AMOUNT?.trim();
  const parsed = raw ? parseInt(raw, 10) : 50000;
  const maxGasAmount = Number.isFinite(parsed) && parsed >= 2000 ? parsed : 50000;
  return { maxGasAmount };
}

let smoothClientSingleton: ScriptComposerClient | null = null;
let smoothSubmitterSingleton: SmoothSendTransactionSubmitter | null = null;

function getSmoothClient(): ScriptComposerClient {
  if (!smoothClientSingleton) {
    const apiKey = process.env.SMOOTHSEND_API_KEY?.trim();
    if (!apiKey) {
      throw new Error(
        'SMOOTHSEND_API_KEY is required for gasless transfers, or set APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER=1 for direct framework transfers (sender pays gas)'
      );
    }
    smoothClientSingleton = new ScriptComposerClient({
      apiKey,
      network: smoothSendNetwork(),
    });
  }
  return smoothClientSingleton;
}

function getSmoothSubmitter(): SmoothSendTransactionSubmitter {
  if (!smoothSubmitterSingleton) {
    const apiKey = process.env.SMOOTHSEND_API_KEY?.trim();
    if (!apiKey) {
      throw new Error('SMOOTHSEND_API_KEY is required for gasless transactions');
    }
    smoothSubmitterSingleton = new SmoothSendTransactionSubmitter({
      apiKey,
      network: smoothSendNetwork(),
    });
  }
  return smoothSubmitterSingleton;
}

router.use(requireInternalSecret);

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
router.post('/execute', async (req: Request, res: Response) => {
  const { sender_address, encrypted_private_key, recipient, amount, currency } = req.body;

  if (!sender_address || !encrypted_private_key || !recipient || !amount || !currency) {
    res.status(400).json({
      success: false,
      error: 'Missing required fields: sender_address, encrypted_private_key, recipient, amount, currency',
    });
    return;
  }

  const upperCurrency = (currency as string).toUpperCase();
  const metadataAddress = metadataForSymbol(upperCurrency);
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
    const privateKeyHex = decryptKey(encrypted_private_key);
    const privateKey = new Ed25519PrivateKey(privateKeyHex);
    const senderAccount = Account.fromPrivateKey({ privateKey });

    if (senderAccount.accountAddress.toString().toLowerCase() !== sender_address.toLowerCase()) {
      res.status(400).json({
        success: false,
        error: 'sender_address does not match the decrypted private key',
      });
      return;
    }

    const rawAmount = Math.round(parsedAmount * 1_000_000);
    const forceSmooth = forceSmoothSend();
    //const viaFramework = forceSmooth ? false : useAptosAccountFungibleTransfer();

    const viaFramework = false; // Force SmoothSend for now
    console.log(
      `[transactions/execute] ${JSON.stringify({
        sender: sender_address,
        recipient,
        amount: String(rawAmount),
        assetType: metadataAddress,
        decimals: 6,
        symbol: upperCurrency,
        transfer_mode: viaFramework ? 'aptos_account::transfer_fungible_assets' : 'smoothsend',
        force_smoothsend: forceSmooth,
      })}`
    );

    let txHash: string;

    if (viaFramework) {
      const transaction = await aptos.transaction.build.simple({
        sender: sender_address,
        data: {
          function: '0x1::aptos_account::transfer_fungible_assets',
          typeArguments: [],
          functionArguments: [metadataAddress, recipient, rawAmount],
        },
        options: frameworkTransferBuildOptions(),
      });

      const simulation = await aptos.transaction.simulate.simple({
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

      const pending = await aptos.signAndSubmitTransaction({
        signer: senderAccount,
        transaction,
      });
      txHash = pending.hash;
    } else {
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
      const transaction = SimpleTransaction.deserialize(new Deserializer(txBytes));

      const simulation = await aptos.transaction.simulate.simple({
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

      const signedTx = aptos.transaction.sign({ signer: senderAccount, transaction });
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
      explorer_url: aptosExplorerTxnUrl(txHash),
      transfer_mode: viaFramework ? 'aptos_account::transfer_fungible_assets' : 'smoothsend',
      force_smoothsend: forceSmooth,
    });
  } catch (e: any) {
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
router.post('/simulate', async (req: Request, res: Response) => {
  const { sender_address, encrypted_private_key, recipient, amount, currency } = req.body;

  if (!sender_address || !encrypted_private_key || !recipient || !amount || !currency) {
    res.status(400).json({ success: false, error: 'Missing required fields' });
    return;
  }

  const upperCurrency = (currency as string).toUpperCase();
  const metadataAddress = metadataForSymbol(upperCurrency);
  if (!metadataAddress) {
    res.status(400).json({ success: false, error: `Unsupported currency: ${currency}` });
    return;
  }

  try {
    const privateKeyHex = decryptKey(encrypted_private_key);
    const privateKey = new Ed25519PrivateKey(privateKeyHex);
    const senderAccount = Account.fromPrivateKey({ privateKey });

    const rawAmount = Math.round(parseFloat(amount) * 1_000_000);
    const useFramework = useAptosAccountFungibleTransfer();
    const entryFn = useFramework
      ? '0x1::aptos_account::transfer_fungible_assets'
      : '0x1::primary_fungible_store::transfer';
    const typeArguments = useFramework ? [] : ['0x1::fungible_asset::Metadata'];

    const tx = await aptos.transaction.build.simple({
      sender: sender_address,
      data: {
        function: entryFn,
        typeArguments,
        functionArguments: [metadataAddress, recipient, rawAmount],
      },
      ...(useFramework ? { options: frameworkTransferBuildOptions() } : {}),
    });

    const simulation = await aptos.transaction.simulate.simple({
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
  } catch (e: any) {
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
router.post('/execute-gasless', async (req: Request, res: Response) => {
  const {
    sender_address,
    encrypted_private_key,
    function: functionName,
    type_arguments,
    function_arguments,
    // Convenience for fungible transfers (so callers can behave like /transactions/execute)
    recipient,
    amount,
    currency,
    options,
  } = req.body;

  const hasDirectFn = !!functionName;
  const hasFungibleShortcut = !!recipient && !!amount && !!currency;

  if (!sender_address || !encrypted_private_key || (!hasDirectFn && !hasFungibleShortcut)) {
    res.status(400).json({
      success: false,
      error:
        'Missing required fields: sender_address, encrypted_private_key, and either (function) or (recipient, amount, currency)',
    });
    return;
  }

  try {
    const privateKeyHex = decryptKey(encrypted_private_key);
    const privateKey = new Ed25519PrivateKey(privateKeyHex);
    const senderAccount = Account.fromPrivateKey({ privateKey });

    if (senderAccount.accountAddress.toString().toLowerCase() !== String(sender_address).toLowerCase()) {
      res.status(400).json({
        success: false,
        error: 'sender_address does not match the decrypted private key',
      });
      return;
    }

    let resolvedFunctionName: `${string}::${string}::${string}`;
    let resolvedTypeArguments: any[] = [];
    let resolvedFunctionArguments: any[] = [];

    const directFnName = hasDirectFn ? (String(functionName) as `${string}::${string}::${string}`) : null;
    const directFnArgs = Array.isArray(function_arguments) ? function_arguments : [];
    const directFnHasArgs = directFnArgs.length > 0;

    // If caller provided `function` but omitted args, and also provided (recipient, amount, currency),
    // prefer the shortcut path to avoid building an invalid transfer_fungible_assets call.
    const shouldUseShortcut =
      hasFungibleShortcut &&
      (!hasDirectFn ||
        !directFnName ||
        !directFnHasArgs ||
        directFnName === '0x1::aptos_account::transfer_fungible_assets');

    if (!shouldUseShortcut) {
      resolvedFunctionName = directFnName as `${string}::${string}::${string}`;
      resolvedTypeArguments = Array.isArray(type_arguments) ? type_arguments : [];
      resolvedFunctionArguments = directFnArgs;
    } else {
      const upperCurrency = String(currency).toUpperCase();
      const metadataAddress = metadataForSymbol(upperCurrency);
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

      const rawAmount = Math.round(parsedAmount * 1_000_000);
      resolvedFunctionName = '0x1::aptos_account::transfer_fungible_assets';
      resolvedFunctionArguments = [metadataAddress, recipient, rawAmount];
    }

    if (
      resolvedFunctionName === '0x1::aptos_account::transfer_fungible_assets' &&
      (!Array.isArray(resolvedFunctionArguments) || resolvedFunctionArguments.length !== 3)
    ) {
      res.status(400).json({
        success: false,
        error:
          'Invalid arguments for 0x1::aptos_account::transfer_fungible_assets. Expected 3 function_arguments: [metadataAddress, recipient, rawAmount] (or omit "function" and pass recipient/amount/currency).',
      });
      return;
    }

    const transaction = await aptos.transaction.build.simple({
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

    const simulation = await aptos.transaction.simulate.simple({
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

    const senderAuthenticator = aptos.transaction.sign({ signer: senderAccount, transaction });
    const submitter = getSmoothSubmitter();

    const pending = await submitter.submitTransaction({
      // aptos-service uses a single Aptos instance; use its config for network routing.
      aptosConfig: (aptos as any).config,
      transaction: transaction as any,
      senderAuthenticator: senderAuthenticator as any,
      pluginParams: { functionName: String(resolvedFunctionName) },
    });

    res.json({
      success: true,
      tx_hash: pending.hash,
      explorer_url: aptosExplorerTxnUrl(pending.hash),
      submit_mode: 'smoothsend_transaction_submitter',
      function: resolvedFunctionName,
    });
  } catch (e: any) {
    console.error('[transactions/execute-gasless] Error:', e.message);
    res.status(500).json({ success: false, error: e.message });
  }
});

export { router as transactionRoutes };
