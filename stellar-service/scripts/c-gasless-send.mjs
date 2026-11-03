/**
 * Operator G is tx source (pays XLM). User C signs auth as BytesN<64>
 * (simple_account __check_auth). User G is never funded.
 */
import {
  Keypair,
  Networks,
  Operation,
  TransactionBuilder,
  authorizeEntry,
  nativeToScVal,
  rpc,
  xdr,
} from '@stellar/stellar-sdk';

const isMainnet = (process.env.STELLAR_NETWORK || 'TESTNET').toUpperCase() === 'PUBLIC';
const RPC_URL =
  process.env.RPC_URL ||
  (isMainnet ? 'https://mainnet.sorobanrpc.com' : 'https://soroban-testnet.stellar.org');
const passphrase = isMainnet ? Networks.PUBLIC : Networks.TESTNET;
const NATIVE_SAC = 'CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQVU2HHGCYSC';
const MAINNET_USDC_SAC = 'CCW67TSZV3SSS2HXMBQ5JFGCKJNXKZM7UQUWUZPUTHXSTZLEO7SJMI75';
const SAC = process.env.SAC_ID || (isMainnet ? MAINNET_USDC_SAC : NATIVE_SAC);
const C = process.env.C_ADDRESS;
const OP_SECRET = process.env.OP_SECRET;
const USER_SECRET = process.env.USER_SECRET;
const AMOUNT = process.env.AMOUNT || '2500000';
const DEST = process.env.DEST;

if (!C || !OP_SECRET || !USER_SECRET) {
  console.error('Need C_ADDRESS, OP_SECRET, USER_SECRET');
  process.exit(1);
}

const server = new rpc.Server(RPC_URL, { allowHttp: false });
const opKp = Keypair.fromSecret(OP_SECRET);
const userKp = Keypair.fromSecret(USER_SECRET);

async function authorizeSimpleAccount(entry, signer, validUntil, networkPassphrase) {
  if (entry.credentials().switch().value !== xdr.SorobanCredentialsType.sorobanCredentialsAddress().value) {
    return entry;
  }
  // Sign with the SDK helper (correct preimage), then store a naked BytesN<64>
  // because simple_account::__check_auth does not use the G-account {public_key,signature} map.
  const signed = await authorizeEntry(entry, signer, validUntil, networkPassphrase);
  const addrAuth = signed.credentials().address();
  const entries = addrAuth.signature().vec();
  let sigBytes;
  for (const scv of entries) {
    for (const pair of scv.map()) {
      if (String(pair.key().sym()) === 'signature') {
        sigBytes = pair.val().bytes();
      }
    }
  }
  if (!sigBytes || sigBytes.length !== 64) {
    throw new Error(`expected 64-byte signature, got ${sigBytes && sigBytes.length}`);
  }
  addrAuth.signature(xdr.ScVal.scvBytes(sigBytes));
  return signed;
}

async function waitTx(hashHex) {
  for (let i = 0; i < 30; i++) {
    const r = await server.getTransaction(hashHex);
    if (r.status === 'SUCCESS') return r;
    if (r.status === 'FAILED') {
      throw new Error(`tx failed: ${JSON.stringify(r, null, 2).slice(0, 2000)}`);
    }
    await new Promise((res) => setTimeout(res, 1000));
  }
  throw new Error(`timeout waiting for ${hashHex}`);
}

const account = await server.getAccount(opKp.publicKey());
const dest = DEST || opKp.publicKey();
const tx = new TransactionBuilder(account, {
  fee: '100000',
  networkPassphrase: passphrase,
})
  .addOperation(
    Operation.invokeContractFunction({
      contract: SAC,
      function: 'transfer',
      args: [
        nativeToScVal(C, { type: 'address' }),
        nativeToScVal(dest, { type: 'address' }),
        nativeToScVal(BigInt(AMOUNT), { type: 'i128' }),
      ],
    }),
  )
  .setTimeout(60)
  .build();

const sim = await server.simulateTransaction(tx);
if (rpc.Api.isSimulationError(sim)) {
  console.error('simulate error', sim);
  process.exit(1);
}

const ledger = await server.getLatestLedger();
const validUntil = ledger.sequence + 50;
const op0 = tx.operations[0];
const auth = sim.result?.auth ?? [];
const signedAuth = [];
for (const entry of auth) {
  signedAuth.push(await authorizeSimpleAccount(entry, userKp, validUntil, passphrase));
}
op0.auth = signedAuth;
console.log('signed auth entries', signedAuth.length);

const assembled = rpc.assembleTransaction(tx, sim).build();
const sorobanData = assembled.toEnvelope().v1().tx().ext().sorobanData();
const resources = sorobanData.resources();
const bumped = xdr.SorobanTransactionData.fromXDR(sorobanData.toXDR());
bumped.resources().instructions(resources.instructions() * 4);
const prepared = TransactionBuilder.cloneFrom(assembled, {
  fee: String(Math.max(Number(sim.minResourceFee || 0) * 4, 500000)),
  sorobanData: bumped,
}).build();
prepared.sign(opKp);

const send = await server.sendTransaction(prepared);
console.log('submit status', send.status, send.hash);
if (send.status === 'ERROR') {
  console.error(send);
  process.exit(1);
}
const done = await waitTx(send.hash);
console.log('confirmed', done.status, send.hash);
const explorer = isMainnet ? 'public' : 'testnet';
console.log(`https://stellar.expert/explorer/${explorer}/tx/${send.hash}`);
