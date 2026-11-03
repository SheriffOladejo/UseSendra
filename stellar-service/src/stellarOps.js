import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import {
  Address,
  Keypair,
  Networks,
  Operation,
  TransactionBuilder,
  authorizeEntry,
  nativeToScVal,
  scValToNative,
  rpc,
  xdr,
} from '@stellar/stellar-sdk';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export function isMainnet() {
  return (process.env.STELLAR_NETWORK || 'PUBLIC').toUpperCase() === 'PUBLIC';
}

export function rpcUrl() {
  return (
    process.env.STELLAR_RPC_URL ||
    (isMainnet() ? 'https://mainnet.sorobanrpc.com' : 'https://soroban-testnet.stellar.org')
  );
}

export function passphrase() {
  return isMainnet() ? Networks.PUBLIC : Networks.TESTNET;
}

export function usdcSac() {
  return (
    process.env.STELLAR_USDC_SAC ||
    (isMainnet()
      ? 'CCW67TSZV3SSS2HXMBQ5JFGCKJNXKZM7UQUWUZPUTHXSTZLEO7SJMI75'
      : 'CBIELTK6YBZJU5UP2WWQEUCYKLPU6AUNZ2BQ4WWFEIE3USCIHMXQDAMA')
  );
}

export function usdtIssuer() {
  return (
    process.env.STELLAR_USDT_ISSUER ||
    'GATISXX6BZ6NC7IKQBY37CJD4SOZL3CYZJWXEDG6JVIY4WBS6KXJHN6Q'
  );
}

export function usdtSac() {
  return (
    process.env.STELLAR_USDT_SAC ||
    'CBSJZEIO5C7KC2SF3MKSNXXJSW5G3VTNBX4ATMKUI3B2MR4JKM4R26YF'
  ).trim();
}

export function sacForCurrency(currency) {
  const c = String(currency || 'USDC').toUpperCase();
  if (c === 'USDT' || c === 'USDT0') {
    const id = usdtSac();
    if (!id) {
      throw new Error('USDT on Stellar is not configured (set STELLAR_USDT_SAC)');
    }
    return id;
  }
  return usdcSac();
}

export function operatorKeypair() {
  const secret = (process.env.STELLAR_OPERATOR_SECRET || '').trim();
  if (!secret) {
    throw new Error('STELLAR_OPERATOR_SECRET is not set');
  }
  return Keypair.fromSecret(secret);
}

export function server() {
  return new rpc.Server(rpcUrl(), { allowHttp: false });
}

export function wasmHashHex() {
  const env = (process.env.STELLAR_SIMPLE_ACCOUNT_WASM_HASH || '').trim().toLowerCase();
  if (env) {
    return env;
  }
  return '3a64c6a8ef1e725412a83704e05157f7530c8534662f8b64b305a0330f4fd571';
}

function wasmPath() {
  return path.join(__dirname, '..', 'soroban_simple_account_contract.wasm');
}

function credSwitchName(cred) {
  try {
    return String(cred.switch().name);
  } catch {
    return '';
  }
}

function isAddressCredentials(cred) {
  const n = credSwitchName(cred);
  return n === 'sorobanCredentialsAddress' || n === 'sorobanCredentialsAddressV2';
}

function addressCredentialsBody(entry) {
  const cred = entry.credentials();
  const n = credSwitchName(cred);
  if (n === 'sorobanCredentialsAddressV2') {
    return cred.addressV2();
  }
  if (n === 'sorobanCredentialsAddress') {
    return cred.address();
  }
  return null;
}

function contractAddressFromCred(cred) {
  const n = credSwitchName(cred);
  try {
    if (n === 'sorobanCredentialsAddressV2') {
      return Address.fromScAddress(cred.addressV2().address()).toString();
    }
    if (n === 'sorobanCredentialsAddress') {
      return Address.fromScAddress(cred.address().address()).toString();
    }
  } catch {
    return null;
  }
  return null;
}

function flattenSimpleAccountSignature(addrAuth) {
  const sig = addrAuth.signature();
  const kind = String(sig.switch().name);
  if (kind === 'scvBytes') {
    return;
  }
  let sigBytes;
  if (kind === 'scvVec') {
    for (const scv of sig.vec() || []) {
      if (!scv.map) {
        continue;
      }
      for (const pair of scv.map()) {
        if (String(pair.key().sym()) === 'signature') {
          sigBytes = pair.val().bytes();
        }
      }
    }
  }
  if (!sigBytes || sigBytes.length !== 64) {
    throw new Error(`expected 64-byte signature, got ${sigBytes && sigBytes.length}`);
  }
  addrAuth.signature(xdr.ScVal.scvBytes(sigBytes));
}

async function authorizeSimpleAccount(entry, signer, validUntil, networkPassphrase) {
  const signed = await authorizeEntry(entry, signer, validUntil, networkPassphrase);
  const addrAuth = addressCredentialsBody(signed);
  if (addrAuth) {
    flattenSimpleAccountSignature(addrAuth);
  }
  return signed;
}

async function waitTx(srv, hashHex) {
  for (let i = 0; i < 40; i++) {
    const r = await srv.getTransaction(hashHex);
    if (r.status === 'SUCCESS') return r;
    if (r.status === 'FAILED') {
      throw new Error(`tx failed: ${JSON.stringify(r, null, 2).slice(0, 2000)}`);
    }
    await new Promise((res) => setTimeout(res, 1000));
  }
  throw new Error(`timeout waiting for ${hashHex}`);
}

async function signAuthEntries(tx, sim, signers) {
  const ledger = await server().getLatestLedger();
  const validUntil = ledger.sequence + 50;
  const op0 = tx.operations[0];
  const auth = sim.result?.auth ?? [];
  const signedAuth = [];
  for (const entry of auth) {
    const cred = entry.credentials();
    let signer = signers.operator;
    if (isAddressCredentials(cred)) {
      const addr = contractAddressFromCred(cred);
      if (addr && signers.byAddress && signers.byAddress[addr]) {
        signer = signers.byAddress[addr];
      } else if (signers.user) {
        signer = signers.user;
      }
    }
    if (signer === signers.user) {
      signedAuth.push(await authorizeSimpleAccount(entry, signer, validUntil, passphrase()));
    } else {
      signedAuth.push(await authorizeEntry(entry, signer, validUntil, passphrase()));
    }
  }
  op0.auth = signedAuth;
}

function bumpAndSign(assembled, sim, operatorKp) {
  const sorobanData = assembled.toEnvelope().v1().tx().ext().sorobanData();
  const resources = sorobanData.resources();
  const bumped = xdr.SorobanTransactionData.fromXDR(sorobanData.toXDR());
  bumped.resources().instructions(resources.instructions() * 4);
  const prepared = TransactionBuilder.cloneFrom(assembled, {
    fee: String(Math.max(Number(sim.minResourceFee || 0) * 4, 500000)),
    sorobanData: bumped,
  }).build();
  prepared.sign(operatorKp);
  return prepared;
}

async function submitInvoke(buildOp, signers) {
  const srv = server();
  const opKp = signers.operator;
  const account = await srv.getAccount(opKp.publicKey());
  const tx = new TransactionBuilder(account, {
    fee: '100000',
    networkPassphrase: passphrase(),
  })
    .addOperation(buildOp())
    .setTimeout(90)
    .build();

  const sim = await srv.simulateTransaction(tx);
  if (rpc.Api.isSimulationError(sim)) {
    throw new Error(`simulate error: ${JSON.stringify(sim).slice(0, 1500)}`);
  }

  await signAuthEntries(tx, sim, signers);
  const assembled = rpc.assembleTransaction(tx, sim).build();
  const prepared = bumpAndSign(assembled, sim, opKp);
  const send = await srv.sendTransaction(prepared);
  if (send.status === 'ERROR') {
    throw new Error(`submit error: ${JSON.stringify(send).slice(0, 1500)}`);
  }
  const done = await waitTx(srv, send.hash);
  return { hash: send.hash, result: done };
}

function explorerTx(hash) {
  return `https://stellar.expert/explorer/${isMainnet() ? 'public' : 'testnet'}/tx/${hash}`;
}

export function horizonUrl() {
  return (
    process.env.STELLAR_HORIZON_URL ||
    (isMainnet() ? 'https://horizon.stellar.org' : 'https://horizon-testnet.stellar.org')
  );
}

export function usdcIssuer() {
  return (
    process.env.STELLAR_USDC_ISSUER ||
    'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN'
  );
}

export function amountToStroops(amount) {
  const s = String(amount).trim().replace(/,/g, '');
  if (!/^\d+(\.\d+)?$/.test(s)) {
    throw new Error('amount must be a positive number');
  }
  const [whole, rawFrac = ''] = s.split('.');
  let frac = rawFrac.replace(/0+$/, '');
  if (frac.length > 7) {
    throw new Error('amount has more than 7 decimal places');
  }
  const stroops = BigInt(whole) * 10_000_000n + BigInt((frac + '0000000').slice(0, 7));
  if (stroops <= 0n) {
    throw new Error('amount must be a positive number');
  }
  return stroops;
}

export function stroopsToDecimal(stroops) {
  const n = BigInt(stroops);
  const neg = n < 0n;
  const abs = neg ? -n : n;
  const w = abs / 10_000_000n;
  const f = (abs % 10_000_000n).toString().padStart(7, '0');
  return `${neg ? '-' : ''}${w}.${f}`;
}

export async function classicTrustlineStroops(account, currency) {
  const code = String(currency || 'USDC').toUpperCase() === 'USDT' || String(currency || '').toUpperCase() === 'USDT0'
    ? 'USDT0'
    : 'USDC';
  const issuer = code === 'USDC'
    ? usdcIssuer()
    : usdtIssuer();
  if (!issuer) {
    return 0n;
  }
  const res = await fetch(`${horizonUrl()}/accounts/${encodeURIComponent(account)}`);
  if (!res.ok) {
    throw new Error(`horizon account ${res.status}`);
  }
  const body = await res.json();
  for (const b of body.balances || []) {
    if (b.asset_code === (code === 'USDT0' ? 'USDT0' : 'USDC') && b.asset_issuer === issuer) {
      return amountToStroops(b.balance);
    }
  }
  return 0n;
}

export async function sacBalance(contractId, sacId) {
  const srv = server();
  const simAccount = await srv.getAccount(operatorKeypair().publicKey());
  const tx = new TransactionBuilder(simAccount, {
    fee: '100000',
    networkPassphrase: passphrase(),
  })
    .addOperation(
      Operation.invokeContractFunction({
        contract: sacId,
        function: 'balance',
        args: [nativeToScVal(contractId, { type: 'address' })],
      }),
    )
    .setTimeout(30)
    .build();
  const sim = await srv.simulateTransaction(tx);
  if (rpc.Api.isSimulationError(sim)) {
    return 0n;
  }
  const retval = sim.result?.retval;
  if (!retval) {
    return 0n;
  }
  try {
    const n = scValToNative(retval);
    return BigInt(n);
  } catch {
    return 0n;
  }
}

export async function deploySimpleAccount(userKp) {
  const opKp = operatorKeypair();
  const hashHex = wasmHashHex();
  const wasmBuf = fs.existsSync(wasmPath()) ? fs.readFileSync(wasmPath()) : null;

  let usedHash = hashHex;
  if (!hashHex && wasmBuf) {
    const up = await submitInvoke(
      () => Operation.uploadContractWasm({ wasm: wasmBuf }),
      { operator: opKp },
    );
    usedHash = up.result.returnValue?.bytes?.()?.toString('hex') || hashHex;
  }

  const salt = crypto.randomBytes(32);
  const { hash, result } = await submitInvoke(
    () =>
      Operation.createCustomContract({
        address: new Address(opKp.publicKey()),
        wasmHash: Buffer.from(usedHash, 'hex'),
        salt,
        constructorArgs: [nativeToScVal(Buffer.from(userKp.rawPublicKey()), { type: 'bytes' })],
      }),
    { operator: opKp },
  );

  let cAddress = '';
  try {
    cAddress = Address.fromScVal(result.returnValue).toString();
  } catch (e) {
    throw new Error(`deploy succeeded but could not parse C address: ${e.message} tx=${hash}`);
  }

  return { stellar_address: cAddress, deploy_tx: hash, explorer_url: explorerTx(hash), wasm_hash: usedHash };
}

export async function poolTransferToC({ destC, amount, currency }) {
  const opKp = operatorKeypair();
  const sac = sacForCurrency(currency);
  const stroops = amountToStroops(amount);
  const fromG = opKp.publicKey();
  const available = await classicTrustlineStroops(fromG, currency);
  if (available < stroops) {
    throw new Error(
      `pool G has ${stroopsToDecimal(available)} classic ${String(currency || 'USDC').toUpperCase()} ` +
        `(need ${stroopsToDecimal(stroops)}). SAC transfer spends the G trustline 1:1 — no wrap/haircut.`,
    );
  }

  const { hash } = await submitInvoke(
    () =>
      Operation.invokeContractFunction({
        contract: sac,
        function: 'transfer',
        args: [
          nativeToScVal(fromG, { type: 'address' }),
          nativeToScVal(destC, { type: 'address' }),
          nativeToScVal(stroops, { type: 'i128' }),
        ],
      }),
    { operator: opKp },
  );

  return { tx_hash: hash, explorer_url: explorerTx(hash) };
}

export async function gaslessTransfer({ cAddress, userSecret, dest, amount, currency }) {
  const opKp = operatorKeypair();
  const userKp = Keypair.fromSecret(userSecret);
  const sac = sacForCurrency(currency);
  const stroops = amountToStroops(amount);

  const { hash } = await submitInvoke(
    () =>
      Operation.invokeContractFunction({
        contract: sac,
        function: 'transfer',
        args: [
          nativeToScVal(cAddress, { type: 'address' }),
          nativeToScVal(dest, { type: 'address' }),
          nativeToScVal(stroops, { type: 'i128' }),
        ],
      }),
    { operator: opKp, user: userKp, byAddress: { [cAddress]: userKp } },
  );

  return { tx_hash: hash, explorer_url: explorerTx(hash) };
}
