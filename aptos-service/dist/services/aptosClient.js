"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.aptos = void 0;
// src/services/aptosClient.ts
const ts_sdk_1 = require("@aptos-labs/ts-sdk");
require("dotenv/config");
const networkMap = {
    MAINNET: ts_sdk_1.Network.MAINNET,
    TESTNET: ts_sdk_1.Network.TESTNET,
    DEVNET: ts_sdk_1.Network.DEVNET,
};
const networkName = process.env.APTOS_NETWORK || 'TESTNET';
const network = networkMap[networkName] ?? ts_sdk_1.Network.TESTNET;
exports.aptos = new ts_sdk_1.Aptos(new ts_sdk_1.AptosConfig({ network }));
console.log(`[AptosClient] Connected to ${networkName}`);
