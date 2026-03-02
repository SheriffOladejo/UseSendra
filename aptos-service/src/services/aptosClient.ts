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

export const aptos = new Aptos(new AptosConfig({ network }));

console.log(`[AptosClient] Connected to ${networkName}`);