import {
  createPublicClient,
  createWalletClient,
  defineChain,
  encodeFunctionData,
  formatUnits,
  http,
  keccak256,
  parseUnits,
} from 'viem';
import { generatePrivateKey, privateKeyToAccount } from 'viem/accounts';

export const USDC_ADDRESS = '0x3600000000000000000000000000000000000000';
const GAS_FLOOR = 20_000_000_000n;

const erc20Abi = [
  {
    type: 'function',
    name: 'balanceOf',
    stateMutability: 'view',
    inputs: [{ name: 'account', type: 'address' }],
    outputs: [{ type: 'uint256' }],
  },
  {
    type: 'function',
    name: 'transfer',
    stateMutability: 'nonpayable',
    inputs: [
      { name: 'to', type: 'address' },
      { name: 'amount', type: 'uint256' },
    ],
    outputs: [{ type: 'bool' }],
  },
];

export function arcChain() {
  const rpc = process.env.ARC_RPC_URL || 'https://rpc.mainnet.arc.io';
  const id = Number(process.env.ARC_CHAIN_ID || 5042);
  return defineChain({
    id,
    name: 'Arc',
    nativeCurrency: { name: 'USDC', symbol: 'USDC', decimals: 18 },
    rpcUrls: { default: { http: [rpc] } },
    blockExplorers: { default: { name: 'Arc Explorer', url: 'https://explorer.arc.io' } },
  });
}

function rpcTransport() {
  const rpc = process.env.ARC_RPC_URL || 'https://rpc.mainnet.arc.io';
  return http(rpc, { timeout: 20_000, retryCount: 0 });
}

function publicClient() {
  return createPublicClient({ chain: arcChain(), transport: rpcTransport() });
}

function isUncertainBroadcast(error) {
  const msg = String(error?.shortMessage || error?.message || error || '');
  return /took too long|timed out|timeout|already known|already imported/i.test(msg);
}

export function explorerTx(hash) {
  return `https://explorer.arc.io/tx/${hash}`;
}

export function createAccount() {
  const privateKey = generatePrivateKey();
  const account = privateKeyToAccount(privateKey);
  return { address: account.address, privateKey };
}

export async function usdcBalance(address) {
  const client = publicClient();
  const raw = await client.readContract({
    address: USDC_ADDRESS,
    abi: erc20Abi,
    functionName: 'balanceOf',
    args: [address],
  });
  return {
    raw: raw.toString(),
    display: formatUnits(raw, 6),
  };
}

export async function transferUsdc({ privateKey, recipient, amount }) {
  const account = privateKeyToAccount(privateKey);
  const chain = arcChain();
  const client = publicClient();
  const wallet = createWalletClient({ account, chain, transport: rpcTransport() });
  const value = parseUnits(String(amount), 6);
  if (value <= 0n) {
    throw new Error('amount must be a positive number');
  }

  const balance = await client.readContract({
    address: USDC_ADDRESS,
    abi: erc20Abi,
    functionName: 'balanceOf',
    args: [account.address],
  });
  const gas = await client.estimateContractGas({
    address: USDC_ADDRESS,
    abi: erc20Abi,
    functionName: 'transfer',
    args: [recipient, value],
    account,
  });
  const gasNative = gas * GAS_FLOOR;
  const gasUsdc = (gasNative + 10n ** 12n - 1n) / 10n ** 12n;
  if (balance < value + gasUsdc) {
    throw new Error(
      `insufficient USDC for amount plus gas (have ${formatUnits(balance, 6)}, need ${formatUnits(value + gasUsdc, 6)})`,
    );
  }

  const nonce = await client.getTransactionCount({
    address: account.address,
    blockTag: 'pending',
  });
  const data = encodeFunctionData({
    abi: erc20Abi,
    functionName: 'transfer',
    args: [recipient, value],
  });
  const serialized = await account.signTransaction({
    chainId: chain.id,
    nonce,
    gas,
    maxFeePerGas: GAS_FLOOR,
    maxPriorityFeePerGas: GAS_FLOOR,
    to: USDC_ADDRESS,
    data,
    type: 'eip1559',
  });
  const hash = keccak256(serialized);
  console.log(`[transfer] from=${account.address} to=${recipient} amount=${amount} hash=${hash}`);

  let broadcastUncertain = false;
  try {
    await wallet.sendRawTransaction({ serializedTransaction: serialized });
  } catch (error) {
    if (!isUncertainBroadcast(error)) throw error;
    broadcastUncertain = true;
    console.error(`[transfer] broadcast uncertain hash=${hash}`, error?.shortMessage || error?.message || error);
  }

  try {
    const receipt = await client.waitForTransactionReceipt({
      hash,
      timeout: 25_000,
      pollingInterval: 2_000,
    });
    if (receipt.status !== 'success') {
      throw new Error(`transaction reverted: ${hash}`);
    }
    return { tx_hash: hash, explorer_url: explorerTx(hash) };
  } catch (error) {
    if (broadcastUncertain) {
      throw new Error(`Arc network did not confirm the transfer (${hash}). Check the balance before retrying.`);
    }
    throw error;
  }
}
