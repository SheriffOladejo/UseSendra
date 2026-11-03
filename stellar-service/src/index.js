import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import express from 'express';
import { requireInternalSecret } from './auth.js';
import { accountRoutes } from './accounts.js';
import { transactionRoutes } from './transactions.js';
import { isMainnet } from './stellarOps.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const opEnv = path.join(__dirname, '..', '.env.operator');
if (fs.existsSync(opEnv) && !process.env.STELLAR_OPERATOR_SECRET) {
  const raw = fs.readFileSync(opEnv, 'utf8');
  for (const line of raw.split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (!m) continue;
    const val = m[2].replace(/^['"]|['"]$/g, '');
    if (!process.env[m[1]]) process.env[m[1]] = val;
  }
}

const app = express();
app.use(express.json());

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    service: 'stellar-microservice',
    network: isMainnet() ? 'PUBLIC' : 'TESTNET',
    timestamp: new Date().toISOString(),
  });
});

app.use('/accounts', requireInternalSecret, accountRoutes);
app.use('/transactions', requireInternalSecret, transactionRoutes);

app.use((_req, res) => {
  res.status(404).json({ success: false, error: 'Route not found' });
});

const PORT = parseInt(process.env.PORT || '3002', 10);
const HOST = process.env.NODE_ENV === 'production' ? '127.0.0.1' : '0.0.0.0';

app.listen(PORT, HOST, () => {
  console.log(`Stellar service on http://${HOST}:${PORT} network=${isMainnet() ? 'PUBLIC' : 'TESTNET'}`);
});
