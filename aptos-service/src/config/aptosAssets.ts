/**
 * Fungible asset metadata object addresses (Aptos docs / Foundation listings).
 * Override with APTOS_USDC_METADATA_ADDRESS / APTOS_USDT_METADATA_ADDRESS if needed.
 *
 * @see https://aptos.dev/build/guides/exchanges — Stablecoin Addresses table
 */

const MAINNET: Record<string, string> = {
  // Circle native USDC on Aptos mainnet
  USDC: '0xbae207659db88bea0cbead6da0ed00aac12edcdda169e591cd41c94180b46f3b',
  // Aptos Foundation listing (USDt)
  USDT: '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b',
};

const TESTNET: Record<string, string> = {
  USDC: '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832',
  USDT: '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a',
};

export function aptosNetworkName(): string {
  return (process.env.APTOS_NETWORK || 'TESTNET').toUpperCase();
}

/**
 * Framework transfer (sender pays APT gas) vs SmoothSend gasless.
 * Explicit APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER wins; otherwise mainnet → gasless, testnet → framework.
 */
export function useAptosAccountFungibleTransfer(): boolean {
  const v = process.env.APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER;
  if (v !== undefined && v !== '') {
    return ['1', 'true', 'yes', 'on'].includes(v.trim().toLowerCase());
  }

  return aptosNetworkName() !== 'MAINNET';
}

/**
 * Which chain SmoothSend’s relayer targets. Usually align with `APTOS_NETWORK`.
 * The API key is often the same for testnet and mainnet; this flag picks the network, not the key.
 */
export function smoothSendNetwork(): 'mainnet' | 'testnet' {
  const o = (process.env.SMOOTHSEND_NETWORK || '').toLowerCase();
  if (o === 'mainnet' || o === 'testnet') {
    return o;
  }

  return aptosNetworkName() === 'MAINNET' ? 'mainnet' : 'testnet';
}

export function metadataForSymbol(symbol: string): string | undefined {
  const up = symbol.toUpperCase();
  if (up === 'USDC') {
    const v = process.env.APTOS_USDC_METADATA_ADDRESS?.trim();
    if (v) {
      return v;
    }
  }
  if (up === 'USDT') {
    const v = process.env.APTOS_USDT_METADATA_ADDRESS?.trim();
    if (v) {
      return v;
    }
  }

  const table = aptosNetworkName() === 'MAINNET' ? MAINNET : TESTNET;

  return table[up];
}

/** Explorer query: mainnet is default (no param); other nets need ?network= */
export function aptosExplorerTxnUrl(txHash: string): string {
  const base = `https://explorer.aptoslabs.com/txn/${txHash}`;
  const net = aptosNetworkName();
  if (net === 'MAINNET') {
    return base;
  }
  if (net === 'TESTNET') {
    return `${base}?network=testnet`;
  }
  if (net === 'DEVNET') {
    return `${base}?network=devnet`;
  }

  return `${base}?network=testnet`;
}
