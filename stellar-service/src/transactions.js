import { Router } from 'express';
import { decryptKey } from './keyManager.js';
import { gaslessTransfer, poolTransferToC } from './stellarOps.js';

export const transactionRoutes = Router();

transactionRoutes.post('/execute-gasless', async (req, res) => {
  const {
    sender_address,
    encrypted_private_key,
    encrypted_stellar_private_key,
    recipient,
    amount,
    currency,
  } = req.body || {};

  const enc = encrypted_stellar_private_key || encrypted_private_key;
  if (!sender_address || !enc || !recipient || !amount) {
    res.status(400).json({
      success: false,
      error: 'sender_address, encrypted key, recipient, and amount are required',
    });
    return;
  }

  try {
    const userSecret = decryptKey(enc);
    const out = await gaslessTransfer({
      cAddress: sender_address,
      userSecret,
      dest: recipient,
      amount,
      currency: currency || 'USDC',
    });
    res.json({
      success: true,
      tx_hash: out.tx_hash,
      explorer_url: out.explorer_url,
    });
  } catch (e) {
    console.error('[transactions/execute-gasless]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});

transactionRoutes.post('/sweep-to-c', async (req, res) => {
  const { recipient, amount, currency } = req.body || {};
  if (!recipient || !amount) {
    res.status(400).json({ success: false, error: 'recipient and amount are required' });
    return;
  }
  try {
    const out = await poolTransferToC({
      destC: recipient,
      amount,
      currency: currency || 'USDC',
    });
    res.json({ success: true, tx_hash: out.tx_hash, explorer_url: out.explorer_url });
  } catch (e) {
    console.error('[transactions/sweep-to-c]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});
