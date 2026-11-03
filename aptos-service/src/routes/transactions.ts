// src/routes/transactions.ts
import { Router, Request, Response } from 'express';
import { Account, Deserializer, Ed25519PrivateKey, SimpleTransaction } from '@aptos-labs/ts-sdk';
import { aptos } from '../services/aptosClient.js';
import { decryptKey } from '../services/keyManager.js';
import { ScriptComposerClient, SmoothSendTransactionSubmitter } from '@smoothsend/sdk';
import { requireInternalSecret } from '../middleware/internalSecretAuth.js';
import {
  aptosExplorerTxnUrl,
  metadataForSymbol,
  smoothSendNetwork,
  useAptosAccountFungibleTransfer,
} from '../config/aptosAssets.js';

const router = Router();

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

/** Aptos SDK default is 20s; SmoothSend relayer needs time to co-sign and submit (they allow ~120s). */
function gaslessTxExpirySecFromNow(): number {
  const raw = process.env.APTOS_GASLESS_TX_EXPIRY_SEC?.trim();
  const parsed = raw ? parseInt(raw, 10) : 150;
  if (!Number.isFinite(parsed) || parsed < 60) {
    return 150;
  }
  if (parsed > 600) {
    return 600;
  }
  return parsed;
}

function gaslessTransferBuildOptions(extra?: Record<string, unknown>): { expireTimestamp: number } {
  const expireTimestamp = Math.floor(Date.now() / 1000) + gaslessTxExpirySecFromNow();
  return { expireTimestamp, ...(extra ?? {}) };
}

/**
 * After a SmoothSend submit error, how long to poll the chain for a committed tx
 * before reporting failure. Relayers sometimes submit successfully even when the
 * API call times out on our side (observed: tx landed ~19s after "Request timeout").
 */
function gaslessRecoveryWaitSec(): number {
  const raw = process.env.APTOS_GASLESS_RECOVERY_WAIT_SEC?.trim();
  const parsed = raw ? parseInt(raw, 10) : 45;
  if (!Number.isFinite(parsed) || parsed < 0) {
    return 45;
  }
  return Math.min(parsed, 120);
}

/** Errors where the relayer may have submitted despite the failure we saw. */
function isAmbiguousSubmitError(message: string): boolean {
  const lower = message.toLowerCase();
  return (
    lower.includes('timeout') ||
    lower.includes('timed out') ||
    lower.includes('network') ||
    lower.includes('socket') ||
    lower.includes('econn') ||
    lower.includes('fetch failed') ||
    lower.includes('failed to process gasless transaction')
  );
}

interface RecoveredTx {
  hash: string;
  success: boolean;
  vmStatus: string;
}

/**
 * Look up a committed user transaction for `senderAddress` at exactly `sequenceNumber`.
 * Only the holder of the sender key can sign that slot, so a match with the same entry
 * function means our transaction was relayed despite the submit error.
 */
async function findCommittedTxBySequence(
  senderAddress: string,
  sequenceNumber: bigint,
  functionName: string
): Promise<RecoveredTx | null> {
  const txs = await aptos.getAccountTransactions({
    accountAddress: senderAddress,
    options: { offset: Number(sequenceNumber), limit: 1 },
  });
  const tx = txs?.[0] as any;
  if (
    tx &&
    tx.type === 'user_transaction' &&
    String(tx.sequence_number) === String(sequenceNumber) &&
    String(tx.payload?.function ?? '') === functionName
  ) {
    return {
      hash: String(tx.hash),
      success: tx.success === true,
      vmStatus: String(tx.vm_status ?? ''),
    };
  }
  return null;
}

/**
 * Submits via SmoothSend; on error, checks the chain before giving up.
 * For ambiguous errors (timeouts etc.) polls up to APTOS_GASLESS_RECOVERY_WAIT_SEC;
 * for terminal errors does a single immediate check. Rethrows the original error
 * when no committed tx is found.
 */
async function submitGaslessWithChainRecovery(params: {
  transaction: SimpleTransaction;
  senderAuthenticator: unknown;
  senderAddress: string;
  functionName: string;
  logTag: string;
}): Promise<{ hash: string; recovered: boolean }> {
  const submitter = getSmoothSubmitter();
  try {
    const pending = await submitter.submitTransaction({
      // aptos-service uses a single Aptos instance; use its config for network routing.
      aptosConfig: (aptos as any).config,
      transaction: params.transaction as any,
      senderAuthenticator: params.senderAuthenticator as any,
      pluginParams: { functionName: params.functionName },
    });
    return { hash: pending.hash, recovered: false };
  } catch (submitErr: any) {
    const errMsg = String(submitErr?.message ?? submitErr);
    const sequenceNumber = (params.transaction as any).rawTransaction?.sequence_number as bigint | undefined;
    if (sequenceNumber === undefined) {
      throw submitErr;
    }

    const ambiguous = isAmbiguousSubmitError(errMsg);
    const waitSec = ambiguous ? gaslessRecoveryWaitSec() : 0;
    console.error(
      `${params.logTag} SmoothSend submit failed (${errMsg}); checking chain for committed tx ` +
        `(sender=${params.senderAddress} seq=${sequenceNumber} wait=${waitSec}s)`
    );

    const deadline = Date.now() + waitSec * 1000;
    // Always do one immediate check, then keep polling only within the window.
    for (;;) {
      try {
        const recovered = await findCommittedTxBySequence(
          params.senderAddress,
          sequenceNumber,
          params.functionName
        );
        if (recovered) {
          if (!recovered.success) {
            console.error(`${params.logTag} tx ${recovered.hash} committed but failed on-chain: ${recovered.vmStatus}`);
            throw new Error(`Transaction was submitted but failed on-chain: ${recovered.vmStatus}`);
          }
          console.log(`${params.logTag} recovered committed tx after submit error: ${recovered.hash}`);
          return { hash: recovered.hash, recovered: true };
        }
      } catch (lookupErr: any) {
        if (String(lookupErr?.message ?? '').startsWith('Transaction was submitted but failed on-chain')) {
          throw lookupErr;
        }
        // Transient fullnode errors (429 etc.): keep polling until the deadline.
      }
      if (Date.now() >= deadline) {
        break;
      }
      await new Promise((r) => setTimeout(r, 5000));
    }

    throw submitErr;
  }
}

let smoothClientSingleton: ScriptComposerClient | null = null;
let smoothSubmitterSingleton: SmoothSendTransactionSubmitter | null = null;

/** SmoothSend HTTP timeout (ms). Default 60s (SDK default is 30s). */
function smoothSendTimeoutMs(): number {
  const raw = process.env.SMOOTHSEND_TIMEOUT_MS?.trim();
  const parsed = raw ? parseInt(raw, 10) : 60000;
  if (!Number.isFinite(parsed) || parsed < 10000) {
    return 60000;
  }
  if (parsed > 120000) {
    return 120000;
  }
  return parsed;
}

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
      timeout: smoothSendTimeoutMs(),
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
      timeout: smoothSendTimeoutMs(),
      debug: true,
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
    const viaFramework = forceSmooth ? false : useAptosAccountFungibleTransfer();
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
      if (result && (result as { success?: boolean }).success === false) {
        const vmStatus = (result as { vmStatus?: string }).vmStatus;
        const err =
          (result as { error?: string }).error ||
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

    const userBuildOptions = typeof options === 'object' && options ? options : {};
    const buildOptions = gaslessTransferBuildOptions(userBuildOptions as Record<string, unknown>);

    const transaction = await aptos.transaction.build.simple({
      sender: sender_address,
      data: {
        function: resolvedFunctionName,
        typeArguments: resolvedTypeArguments,
        functionArguments: resolvedFunctionArguments,
      },
      options: buildOptions,
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
      options: buildOptions,
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

    const { hash, recovered } = await submitGaslessWithChainRecovery({
      transaction,
      senderAuthenticator,
      senderAddress: String(sender_address),
      functionName: String(resolvedFunctionName),
      logTag: '[transactions/execute-gasless]',
    });

    res.json({
      success: true,
      tx_hash: hash,
      explorer_url: aptosExplorerTxnUrl(hash),
      submit_mode: 'smoothsend_transaction_submitter',
      function: resolvedFunctionName,
      recovered_after_submit_error: recovered,
    });
  } catch (e: any) {
    console.error('[transactions/execute-gasless] Error:', e.message);
    if (e?.stack) {
      console.error(e.stack);
    }
    res.status(500).json({ success: false, error: e.message });
  }
});

// ─── POST /transactions/panora-swap ───────────────────────────────────────────
/**
 * Panora USDT → USDC on Aptos; output credited to sender (`toWalletAddress` = sender).
 * Used before 1Click Stellar bridge so USDC remains in the user wallet if 1Click fails later.
 */
function panoraChainId(): string {
  const raw = process.env.SENDRA_PANORA_CHAIN_ID?.trim() || process.env.PANORA_CHAIN_ID?.trim();
  if (raw) {
    return raw;
  }
  return (process.env.APTOS_NETWORK || 'TESTNET').toUpperCase() === 'MAINNET' ? '1' : '2';
}

function panoraSlippagePercent(): string {
  return process.env.SENDRA_PANORA_SLIPPAGE_PERCENT?.trim() || process.env.PANORA_SLIPPAGE_PERCENT?.trim() || '1';
}

function panoraUsdtMetadata(): string {
  return (
    process.env.SENDRA_PANORA_USDT_METADATA?.trim() ||
    process.env.PANORA_USDT_METADATA?.trim() ||
    metadataForSymbol('USDT') ||
    ''
  );
}

function panoraUsdcMetadata(): string {
  return (
    process.env.SENDRA_PANORA_USDC_METADATA?.trim() ||
    process.env.PANORA_USDC_METADATA?.trim() ||
    metadataForSymbol('USDC') ||
    ''
  );
}

router.post('/panora-swap', async (req: Request, res: Response) => {
  const {
    sender_address,
    encrypted_private_key,
    from_token_amount,
    to_token_amount,
    direction: directionRaw,
  } = req.body;
  const direction =
    String(directionRaw || 'usdt_to_usdc').toLowerCase() === 'usdc_to_usdt' ? 'usdc_to_usdt' : 'usdt_to_usdc';

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

  const baseUrl = (process.env.SENDRA_PANORA_API_BASE_URL || process.env.PANORA_API_BASE_URL || 'https://api.panora.exchange').replace(
    /\/$/,
    ''
  );

  try {
    const quoteRes = await fetch(`${baseUrl}/swap`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'x-api-key': apiKey,
      },
      body: JSON.stringify(
        hasExactOut
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
            }
      ),
    });
    const quoteRaw = await quoteRes.text();
    let quoteJson: any;
    try {
      quoteJson = JSON.parse(quoteRaw);
    } catch {
      res.status(502).json({ success: false, error: 'Panora returned invalid JSON', http_code: quoteRes.status });
      return;
    }
    if (!quoteRes.ok) {
      const errMsg =
        typeof quoteJson?.message === 'string'
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
    const resolvedFunctionName = String(txData.function) as `${string}::${string}::${string}`;
    const resolvedTypeArguments = Array.isArray(txData.type_arguments) ? txData.type_arguments : [];
    const resolvedFunctionArguments = Array.isArray(txData.arguments) ? txData.arguments : [];

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

    const transaction = await aptos.transaction.build.simple({
      sender: sender_address,
      data: {
        function: resolvedFunctionName,
        typeArguments: resolvedTypeArguments,
        functionArguments: resolvedFunctionArguments,
      },
      options: gaslessTransferBuildOptions(),
      withFeePayer: true,
    });

    const simulation = await aptos.transaction.simulate.simple({
      signerPublicKey: senderAccount.publicKey,
      transaction,
    });
    const simResult = simulation[0];
    if (!simResult.success) {
      const vmStatus = String(simResult.vm_status ?? '');
      console.error('Wallet swap simulation failed:', vmStatus);
      let userError = 'We could not complete this swap. Please try again.';
      const lower = vmStatus.toLowerCase();
      if (lower.includes('einsufficient_balance') || lower.includes('insufficient balance')) {
        userError = 'Insufficient balance to complete this swap.';
      }
      res.status(400).json({
        success: false,
        error: userError,
      });
      return;
    }

    const senderAuthenticator = aptos.transaction.sign({ signer: senderAccount, transaction });
    const { hash: swapTxHash, recovered: swapRecovered } = await submitGaslessWithChainRecovery({
      transaction,
      senderAuthenticator,
      senderAddress: String(sender_address),
      functionName: String(resolvedFunctionName),
      logTag: '[transactions/panora-swap]',
    });

    const quotedTo =
      best.toTokenAmount != null ? String(best.toTokenAmount) : hasExactOut ? String(to_token_amount) : null;
    const quotedMin =
      best.minToTokenAmount != null
        ? String(best.minToTokenAmount)
        : quotedTo;

    res.json({
      success: true,
      tx_hash: swapTxHash,
      explorer_url: aptosExplorerTxnUrl(swapTxHash),
      submit_mode: 'smoothsend_panora_swap',
      direction,
      function: resolvedFunctionName,
      recovered_after_submit_error: swapRecovered,
      panora: {
        from_token_amount:
          best.fromTokenAmount != null ? String(best.fromTokenAmount) : hasExactIn ? String(from_token_amount) : null,
        to_token_amount: quotedTo,
        min_to_token_amount: quotedMin,
      },
    });
  } catch (e: any) {
    console.error('[transactions/panora-swap] Error:', e.message);
    res.status(500).json({ success: false, error: e.message });
  }
});

export { router as transactionRoutes };
