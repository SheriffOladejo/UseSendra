// src/routes/accounts.ts
import { Router, Request, Response } from 'express';
import { Account } from '@aptos-labs/ts-sdk';
import { aptos } from '../services/aptosClient';
import { encryptKey, decryptKey } from '../services/keyManager';

const router = Router();

// ─── Middleware: verify internal secret ───────────────────────────────────────
router.use((req: Request, res: Response, next) => {
  const secret = req.headers['x-internal-secret'];
  if (secret !== process.env.INTERNAL_API_SECRET) {
    res.status(401).json({ success: false, error: 'Unauthorized' });
    return;
  }
  next();
});

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
router.post('/create', async (req: Request, res: Response) => {
  const { phone_number } = req.body;

  if (!phone_number) {
    res.status(400).json({ success: false, error: 'phone_number is required' });
    return;
  }

  try {
    // 1. Generate a brand new Ed25519 Aptos account
    const account = Account.generate();
    const address = account.accountAddress.toString();
    const privateKeyHex = account.privateKey.toString();

    // 2. Encrypt private key — this is what gets stored in your DB
    const encryptedKey = encryptKey(privateKeyHex);

    console.log(`[accounts/create] New account created for ${phone_number}: ${address}`);

    res.json({
      success: true,
      aptos_address: address,
      encrypted_private_key: encryptedKey,
      // Return plain key ONCE so PHP can show it to user as backup
      // PHP must NOT store this — display and discard
      private_key_for_display: privateKeyHex,
    });
  } catch (e: any) {
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
router.post('/balance', async (req: Request, res: Response) => {
  const { aptos_address } = req.body;

  if (!aptos_address) {
    res.status(400).json({ success: false, error: 'aptos_address is required' });
    return;
  }

  const ASSET_METADATA: Record<string, string> = {
    USDC: '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832',
    USDT: '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a',
  };

  try {
    // APT balance
    let aptRaw = 0;
    try {
      const aptRes = await aptos.getAccountResource({
        accountAddress: aptos_address,
        resourceType: '0x1::coin::CoinStore<0x1::aptos_coin::AptosCoin>',
      });
      aptRaw = Number((aptRes as any).coin?.value ?? 0);
    } catch {
      // Account not yet on-chain — balance is 0
    }

    // Fungible asset balances (USDC / USDT)
    const getFaBalance = async (metadataAddress: string): Promise<number> => {
      try {
        const result = await aptos.view({
          payload: {
            function: '0x1::primary_fungible_store::balance',
            typeArguments: ['0x1::fungible_asset::Metadata'],
            functionArguments: [aptos_address, metadataAddress],
          },
        });
        return Number(result[0] ?? 0);
      } catch {
        return 0;
      }
    };

    const [usdcRaw, usdtRaw] = await Promise.all([
      getFaBalance(ASSET_METADATA.USDC),
      getFaBalance(ASSET_METADATA.USDT),
    ]);

    res.json({
      success: true,
      address: aptos_address,
      balances: {
        APT:  (aptRaw  / 1e8).toFixed(8),
        USDC: (usdcRaw / 1e6).toFixed(6),
        USDT: (usdtRaw / 1e6).toFixed(6),
      },
      raw: { APT: aptRaw, USDC: usdcRaw, USDT: usdtRaw },
    });
  } catch (e: any) {
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
router.post('/validate-key', (req: Request, res: Response) => {
  const { encrypted_private_key } = req.body;

  if (!encrypted_private_key) {
    res.status(400).json({ success: false, error: 'encrypted_private_key is required' });
    return;
  }

  try {
    const decrypted = decryptKey(encrypted_private_key);
    // Only return a partial key so this can't be used to extract full keys in prod
    res.json({
      success: true,
      decrypted_preview: decrypted.slice(0, 8) + '...',
      length: decrypted.length,
    });
  } catch (e: any) {
    res.status(400).json({ success: false, error: 'Decryption failed: ' + e.message });
  }
});

export { router as accountRoutes };