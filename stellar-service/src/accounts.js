import { Router } from 'express';
import { Keypair } from '@stellar/stellar-sdk';
import { encryptKey } from './keyManager.js';
import { deploySimpleAccount, sacBalance, usdcSac, usdtSac } from './stellarOps.js';

export const accountRoutes = Router();

accountRoutes.post('/create', async (req, res) => {
  const { phone_number } = req.body || {};
  if (!phone_number) {
    res.status(400).json({ success: false, error: 'phone_number is required' });
    return;
  }

  try {
    const userKp = Keypair.random();
    const deployed = await deploySimpleAccount(userKp);
    const encryptedKey = encryptKey(userKp.secret());
    console.log(`[accounts/create] ${phone_number} C=${deployed.stellar_address}`);
    res.json({
      success: true,
      stellar_address: deployed.stellar_address,
      encrypted_stellar_private_key: encryptedKey,
      deploy_tx: deployed.deploy_tx,
      explorer_url: deployed.explorer_url,
    });
  } catch (e) {
    console.error('[accounts/create]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});

accountRoutes.post('/balance', async (req, res) => {
  const { stellar_address } = req.body || {};
  if (!stellar_address) {
    res.status(400).json({ success: false, error: 'stellar_address is required' });
    return;
  }

  try {
    const usdcRaw = await sacBalance(stellar_address, usdcSac());
    let usdtRaw = 0n;
    const usdtId = usdtSac();
    if (usdtId) {
      usdtRaw = await sacBalance(stellar_address, usdtId);
    }
    res.json({
      success: true,
      address: stellar_address,
      balances: {
        USDC: (Number(usdcRaw) / 1e7).toFixed(7),
        USDT: (Number(usdtRaw) / 1e7).toFixed(7),
      },
      raw: { USDC: usdcRaw.toString(), USDT: usdtRaw.toString() },
    });
  } catch (e) {
    console.error('[accounts/balance]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});
