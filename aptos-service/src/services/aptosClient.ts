// src/services/aptosClient.ts
import { Aptos, AptosConfig, Network } from '@aptos-labs/ts-sdk';
import 'dotenv/config';

const networkMap: Record<string, Network> = {
  MAINNET: Network.MAINNET,
  TESTNET: Network.TESTNET,
  DEVNET: Network.DEVNET, 
};

const networkName = process.env.APTOS_NETWORK || 'TESTNET';
const network = networkMap[networkName] ?? Network.TESTNET;

const apiKey = (process.env.APTOS_NODE_API_KEY || '').trim();
const clientConfig = apiKey !== '' ? { API_KEY: apiKey } : undefined;

export const aptos = new Aptos(new AptosConfig({ network, clientConfig }));

console.log(
  `[AptosClient] Connected to ${networkName}` +
    (apiKey !== '' ? ' (fullnode API key set)' : ' (no APTOS_NODE_API_KEY — anonymous rate limits apply)')
);