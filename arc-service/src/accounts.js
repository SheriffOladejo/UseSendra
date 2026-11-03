import { Router } from 'express';
import { isAddress } from 'viem';
import { createAccount, usdcBalance } from './arcOps.js';
import { encryptKey } from './keyManager.js';

export const accountRoutes = Router();

accountRoutes.post('/create', (req, res) => {
  const { phone_number } = req.body || {};
  if (!phone_number) {
    res.status(400).json({ success: false, error: 'phone_number is required' });
    return;
  }

  try {
    const created = createAccount();
    const encryptedKey = encryptKey(created.privateKey);
    console.log(`[accounts/create] ${phone_number} arc=${created.address}`);
    res.json({
      success: true,
      arc_address: created.address,
      encrypted_arc_private_key: encryptedKey,
    });
  } catch (e) {
    console.error('[accounts/create]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});

accountRoutes.post('/balance', async (req, res) => {
  const { arc_address } = req.body || {};
  if (!arc_address || !isAddress(arc_address)) {
    res.status(400).json({ success: false, error: 'arc_address is required' });
    return;
  }

  try {
    const bal = await usdcBalance(arc_address);
    res.json({
      success: true,
      address: arc_address,
      balances: { USDC: bal.display },
      raw: { USDC: bal.raw },
    });
  } catch (e) {
    console.error('[accounts/balance]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});
