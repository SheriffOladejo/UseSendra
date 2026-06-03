"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
// src/index.ts
require("dotenv/config");
const express_1 = __importDefault(require("express"));
const aptosAssets_js_1 = require("./config/aptosAssets.js");
const accounts_js_1 = require("./routes/accounts.js");
const transactions_js_1 = require("./routes/transactions.js");
const app = (0, express_1.default)();
app.use(express_1.default.json());
// ─── Health check (no auth required) ─────────────────────────────────────────
app.get('/health', (_req, res) => {
    res.json({
        status: 'ok',
        service: 'aptos-microservice',
        network: process.env.APTOS_NETWORK || 'TESTNET',
        timestamp: new Date().toISOString(),
    });
});
// ─── Routes ───────────────────────────────────────────────────────────────────
app.use('/accounts', accounts_js_1.accountRoutes);
app.use('/transactions', transactions_js_1.transactionRoutes);
// ─── 404 handler ─────────────────────────────────────────────────────────────
app.use((_req, res) => {
    res.status(404).json({ success: false, error: 'Route not found' });
});
// ─── Global error handler ─────────────────────────────────────────────────────
app.use((err, _req, res, _next) => {
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
    console.log(`📡 Aptos SDK network: ${process.env.APTOS_NETWORK || 'TESTNET'}`);
    console.log(`📡 SmoothSend network: ${(0, aptosAssets_js_1.smoothSendNetwork)()}`);
    console.log(`🔒 Auth:    X-Internal-Secret header required on all routes\n`);
});
