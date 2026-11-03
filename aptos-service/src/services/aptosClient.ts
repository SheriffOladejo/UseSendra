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

function resolveFullnodeUrl(): string | undefined {
  const explicit = (process.env.APTOS_FULLNODE_URL || '').trim();
  if (explicit !== '') {
    return explicit;
  }

  return undefined;
}

const fullnodeUrl = resolveFullnodeUrl();
const apiKey = (process.env.APTOS_NODE_API_KEY || '').trim();
const geomiOrigin = (process.env.APTOS_GEOMI_ORIGIN || 'https://coinectra.stipendly.app').trim();

function buildAptosAuthConfig(): { clientConfig?: { API_KEY: string; HEADERS?: Record<string, string> }; fullnodeConfig?: { API_KEY: string; HEADERS?: Record<string, string> } } {
  if (apiKey === '') {
    return {};
  }
  const headers =
    apiKey.startsWith('AG-') && geomiOrigin !== '' ? { Origin: geomiOrigin } : undefined;
  const auth = {
    API_KEY: apiKey,
    ...(headers ? { HEADERS: headers } : {}),
  };
  // Mirror into fullnodeConfig so every Fullnode RPC (simulate, estimate_gas_price, view) sends the key.
  return { clientConfig: auth, fullnodeConfig: auth };
}

const { clientConfig, fullnodeConfig } = buildAptosAuthConfig();

export const aptos =
  fullnodeUrl !== undefined
    ? new Aptos(
        new AptosConfig({
          network: Network.CUSTOM,
          fullnode: fullnodeUrl,
          ...(clientConfig ? { clientConfig } : {}),
          ...(fullnodeConfig ? { fullnodeConfig } : {}),
        })
      )
    : new Aptos(
        new AptosConfig({
          network,
          ...(clientConfig ? { clientConfig } : {}),
          ...(fullnodeConfig ? { fullnodeConfig } : {}),
        })
      );

const fullnodeLabel =
  fullnodeUrl !== undefined
    ? `custom fullnode (APTOS_FULLNODE_URL)`
    : apiKey !== ''
      ? 'Aptos Labs fullnode (Geomi API key set)'
      : 'anonymous Aptos Labs fullnode (rate limits apply — set APTOS_NODE_API_KEY)';

console.log(`[AptosClient] Connected to ${networkName} via ${fullnodeLabel}`);