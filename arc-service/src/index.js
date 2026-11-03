import dotenv from 'dotenv';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import express from 'express';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const envCandidates = [
  path.join(__dirname, '..', '..', '.env'),
  path.join(__dirname, '..', '..', 'aptos-service', '.env'),
  path.join(__dirname, '..', '..', 'stellar-service', '.env'),
  path.join(__dirname, '..', '.env'),
];
for (const envPath of envCandidates) {
  if (fs.existsSync(envPath)) {
    dotenv.config({ path: envPath });
  }
}

const operatorEnv = path.join(__dirname, '..', '.env.operator');
if (fs.existsSync(operatorEnv)) {
  const raw = fs.readFileSync(operatorEnv, 'utf8');
  for (const line of raw.split('\n')) {
    const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (!match) continue;
    const value = match[2].replace(/^['"]|['"]$/g, '');
    if (!process.env[match[1]]) process.env[match[1]] = value;
  }
}

const { requireInternalSecret } = await import('./auth.js');
const { accountRoutes } = await import('./accounts.js');
const { transactionRoutes } = await import('./transactions.js');
const { arcChain } = await import('./arcOps.js');

const app = express();
app.use(express.json());

app.get('/health', (_req, res) => {
  const chain = arcChain();
  res.json({
    status: 'ok',
    service: 'arc-microservice',
    network: chain.id === 5042 ? 'MAINNET' : `CHAIN_${chain.id}`,
    chain_id: chain.id,
    timestamp: new Date().toISOString(),
  });
});

app.use('/accounts', requireInternalSecret, accountRoutes);
app.use('/transactions', requireInternalSecret, transactionRoutes);

app.use((_req, res) => {
  res.status(404).json({ success: false, error: 'Route not found' });
});

const PORT = parseInt(process.env.ARC_SERVICE_PORT || '3003', 10);
const HOST = '127.0.0.1';

app.listen(PORT, HOST, () => {
  console.log(`Arc service on http://${HOST}:${PORT} chain=${arcChain().id}`);
});
