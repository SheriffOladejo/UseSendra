import { Router } from 'express';
import { isAddress } from 'viem';
import { privateKeyToAccount } from 'viem/accounts';
import { transferUsdc } from './arcOps.js';
import { decryptKey } from './keyManager.js';

export const transactionRoutes = Router();

const OPERATOR_WALLETS = {
  refund: ['ARC_REFUND_ADDRESS', 'ARC_REFUND_KEY_ENC'],
  earnings: ['ARC_EARNINGS_ADDRESS', 'ARC_EARNINGS_KEY_ENC'],
};

transactionRoutes.post('/operator', async (req, res) => {
  const wallet = String(req.body?.wallet || '').toLowerCase();
  const pair = OPERATOR_WALLETS[wallet];
  const recipient = req.body?.recipient;
  const amount = req.body?.amount;
  if (!pair) {
    res.status(400).json({ success: false, error: 'wallet must be refund or earnings' });
    return;
  }
  const sender = (process.env[pair[0]] || '').trim();
  const enc = (process.env[pair[1]] || '').trim();
  if (!sender || !enc) {
    res.status(503).json({ success: false, error: 'operator wallet is not configured' });
    return;
  }
  if (!recipient || !amount || !isAddress(recipient)) {
    res.status(400).json({ success: false, error: 'recipient and amount are required' });
    return;
  }

  try {
    const privateKey = decryptKey(enc);
    const from = privateKeyToAccount(privateKey).address;
    if (from.toLowerCase() !== sender.toLowerCase()) {
      res.status(500).json({ success: false, error: 'operator key does not match the stored address' });
      return;
    }
    const out = await transferUsdc({ privateKey, recipient, amount });
    console.log(`[transactions/operator] ${wallet} -> ${recipient} tx=${out.tx_hash}`);
    res.json({
      success: true,
      wallet,
      from: sender,
      tx_hash: out.tx_hash,
      explorer_url: out.explorer_url,
    });
  } catch (e) {
    console.error('[transactions/operator]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});

transactionRoutes.post('/execute', async (req, res) => {
  const {
    sender_address,
    encrypted_private_key,
    encrypted_arc_private_key,
    recipient,
    amount,
    currency,
  } = req.body || {};

  const enc = encrypted_arc_private_key || encrypted_private_key;
  const asset = String(currency || 'USDC').toUpperCase();
  if (!sender_address || !enc || !recipient || !amount) {
    res.status(400).json({
      success: false,
      error: 'sender_address, encrypted key, recipient, and amount are required',
    });
    return;
  }
  if (asset !== 'USDC') {
    res.status(400).json({ success: false, error: 'Arc only supports USDC' });
    return;
  }
  if (!isAddress(sender_address) || !isAddress(recipient)) {
    res.status(400).json({ success: false, error: 'sender and recipient must be Arc addresses' });
    return;
  }

  try {
    const privateKey = decryptKey(enc);
    const out = await transferUsdc({ privateKey, recipient, amount });
    res.json({
      success: true,
      tx_hash: out.tx_hash,
      explorer_url: out.explorer_url,
    });
  } catch (e) {
    console.error('[transactions/execute]', e);
    res.status(500).json({ success: false, error: e.message || String(e) });
  }
});
