// src/index.ts
import 'dotenv/config';
import express, { Request, Response, NextFunction } from 'express';
import { accountRoutes } from './routes/accounts';
import { transactionRoutes } from './routes/transactions';

const app = express();
app.use(express.json());

// ─── Health check (no auth required) ─────────────────────────────────────────
app.get('/health', (_req: Request, res: Response) => {
  res.json({
    status: 'ok',
    service: 'aptos-microservice',
    network: process.env.APTOS_NETWORK || 'TESTNET',
    timestamp: new Date().toISOString(),
  });
});

// ─── Routes ───────────────────────────────────────────────────────────────────
app.use('/accounts', accountRoutes);
app.use('/transactions', transactionRoutes);

// ─── 404 handler ─────────────────────────────────────────────────────────────
app.use((_req: Request, res: Response) => {
  res.status(404).json({ success: false, error: 'Route not found' });
});

// ─── Global error handler ─────────────────────────────────────────────────────
app.use((err: Error, _req: Request, res: Response, _next: NextFunction) => {
  console.error('[Unhandled error]', err);
  res.status(500).json({ success: false, error: 'Internal server error' });
});

// ─── Start ────────────────────────────────────────────────────────────────────
const PORT = parseInt(process.env.PORT || '3001', 10);

// '0.0.0.0' during development so Postman can reach it
// Change to '127.0.0.1' in production to restrict to internal traffic only
const HOST = process.env.NODE_ENV === 'production' ? '127.0.0.1' : '0.0.0.0';

app.listen(PORT, HOST, () => {
  console.log(`\n🚀 Aptos service running on http://${HOST}:${PORT}`);
  console.log(`📡 Network: ${process.env.APTOS_NETWORK || 'TESTNET'}`);
  console.log(`🔒 Auth:    X-Internal-Secret header required on all routes\n`);
});