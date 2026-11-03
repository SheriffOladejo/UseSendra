# Sendra on Stellar: classic `G…` vs contract `C…`

Sendra is a custodial WhatsApp wallet. We already generate an Ed25519 key, encrypt it, and submit user-signed transfers through SmoothSend so the user never holds gas. On Stellar we must pick an address type before we build `stellar-service`.

This note is only about that choice, and what to ask SmoothSend so we do not build the wrong submit path.

## What Sendra needs (either address type)

- User never holds or thinks about XLM.
- Receive USDC (and USDT / USDT0 if we support it) at a stable address we show in chat.
- Send those assets after PIN, from our backend.
- SmoothSend (or equivalent) pays network fees from Sendra’s side.
- Deposit watcher so inbound credits trigger WhatsApp, same as Aptos indexer.

WhatsApp cannot do passkeys. The signer stays the Ed25519 key in our DB.

## Side-by-side

| | Classic `G…` | Contract `C…` (smart wallet) |
|---|---|---|
| What the user is given | Stellar account keypair; address starts with `G` | Deployed account contract; address starts with `C` |
| Exists after keygen? | No. Needs `CreateAccount` on-chain | No. Needs a deploy tx (Sendra operator `G…` pays) |
| Hold USDC/USDT | Trustline per asset (`changeTrust`). Extra XLM reserve per trustline unless we sponsor it | No trustline. Balance lives on the Stellar Asset Contract (SAC) |
| User XLM | Min ~1 XLM + 0.5 XLM per trustline, **or** Sendra sponsors reserves so the user balance can be 0 | User holds 0 XLM. Sendra pays deploy, rent, and every submit |
| How send works | Classic `payment` (or SAC `transfer` if talking to a `C…`) | **Only** SAC `transfer`. Classic `payment` cannot use a `C…` as source or destination |
| How the user “signs” | Signs the **transaction envelope** | Signs **Soroban auth entries** only. A `C…` cannot sign an envelope or pay fees |
| Who pays gas | Fee-bump: SmoothSend wraps the signed inner tx and pays XLM | Relayer `G…` is tx source and pays XLM. User auth is attached separately (`func` + signed `auth`) |
| Inbound from CEXs / many ramps | Works: `G…` + memo / muxed `M…` | Often **does not** work until the ramp supports SAC `transfer` to a `C…` |
| Deposit monitoring | Horizon `/payments` on the `G…` | RPC `getEvents` on the USDC/USDT SAC, `to` = user `C…` |
| Closest Aptos analogue | Account + coin/FA, but with an activation + trustline step Aptos does not have | Address is a contract; key only authorizes; relayer pays gas |

**Trustline ≠ gas.** Opening a trustline is “this `G…` is allowed to hold USDC.” Gas is the XLM fee on each tx. SmoothSend solves gas. It does not, by itself, create the account or the trustlines.

## What we would build in `stellar-service`

**If `G…`:** keygen → sponsored `CreateAccount` + USDC/USDT trustlines → user signs a payment → SmoothSend **fee-bump** submit → Horizon payment stream.

**If `C…`:** keygen → deploy Simple Account (Ed25519 in `__check_auth`) → user `C…` is the receive address → user signs SAC auth entries → SmoothSend (or our operator) submits as fee-payer `G…` → SAC `getEvents`.

We prefer `C…` only if SmoothSend’s Stellar gasless product is the Soroban/auth-entry path (or will be). Otherwise `G…` + fee-bump is the same product we already run on Aptos, with extra activation/trustline work on our side.

## What to ask SmoothSend

Their public Stellar SDK is **fee-bump on `G…`** (XLM, USDC, EURC). Sendra wants **contract accounts**. Ask them which of those they are actually shipping, in writing.

### 1. Address type (this decides our wallet)

- Does Stellar gasless support **classic `G…` only**, **contract `C…` only**, or **both**?
- If both, which path is the one you want partners on in the next 3–6 months?
- Is `C…` support on the roadmap, or live on testnet/mainnet today?

### 2. What we send you on submit

For a Sendra user sending USDC from a `C…`:

- Do we POST a **classic signed transaction XDR** (fee-bump), or **`func` + signed Soroban `auth` entries** (Launchtube-style)?
- If `C…`: do you simulate in enforcing mode, set the fee-payer `G…` as source, and submit? Do we still need to simulate first on our side?
- Can the inner `from` be a `C…` and `to` be either `C…` or `G…`?

If they only accept a signed `G…` payment XDR, **Sendra cannot use `C…` through SmoothSend** without our own relayer.

### 3. Assets and receive

- Which assets: native XLM, Circle USDC (`GA5ZSEJY…`), EURC, USDT0, others?
- SAC `transfer` vs classic `payment`?
- Can a third party pay **into** a user’s `C…` via USDC SAC without that user holding XLM or a trustline? (This is how Sendra receive must work.)

### 4. Account creation (not fees)

SmoothSend paying gas does not create the wallet.

- Do you deploy or activate user wallets, or only relay spends?
- `G…`: will you sponsor `CreateAccount` + `changeTrust(USDC)` so the user can receive immediately with 0 XLM?
- `C…`: will you pay the **factory deploy** (and rent) of the account contract, or only later transfers?

If they only relay spends, Sendra still runs an operator `G…` for create/deploy.

### 5. Custodial / server-side (Sendra is not Freighter)

- Can we use the same **secret API key** (`sk_nogas_*`) from Node, as we do on Aptos (`SmoothSendTransactionSubmitter`), with a key we decrypt on the server — not a browser wallet?
- Any restriction that the signer must be a passkey / Freighter?

### 6. Limits we will hit in production

- Testnet and mainnet, SLA, timeouts (Aptos relay is ~60–120s).
- Who pays if submit fails after the user already signed?
- Sequence / channel accounts under load (many WhatsApp sends).
- For `C…`: TTL/rent bumps — do you extend instance + SAC balance entries, or do we?

### Suggested message to SmoothSend

> Sendra is a custodial WhatsApp wallet. We already use SmoothSend on Aptos: we hold the user’s Ed25519 key, they never hold gas, we submit from our backend.
>
> On Stellar we are choosing between classic `G…` accounts (fee-bump payments + trustlines) and contract `C…` accounts (SAC `transfer`, user signs auth entries, a `G…` relayer pays XLM). We do not want passkeys.
>
> Please confirm: (1) is Stellar gasless `G…` fee-bump, `C…` auth-entry relay, or both? (2) what exact payload do we submit from Node? (3) which assets, and can inbound USDC land on a `C…` with no user XLM? (4) do you only sponsor fees on send, or also account create / contract deploy? (5) is the Aptos-style server API key supported for Stellar?

Until they answer (1) and (2), do not lock `stellar-service` to `C…`. If they are `G…` fee-bump only, we should use sponsored classic accounts and still get gasless UX. If they are shipping `C…` auth-entry submit, we should match that and skip classic trustlines.

## SmoothSend reply (fee-bump + claimable balance)

They said Stellar gasless is **fee-bump**, optionally combined with **claimable balances**.

That is the **classic `G…` product**, not contract-account gasless.

- **Fee-bump** wraps a signed inner transaction. The inner source must be a `G…` that can sign an envelope. A `C…` cannot. Fee-bump only pays XLM fees; it does not make a contract the sender.
- **Claimable balance** is a classic payment you park on the ledger for a claimant. Used so a `G…` can *receive* an asset before they have a trustline (they must still open a trustline to claim). It is not how `C…` wallets hold USDC (those use SAC balances).

So: SmoothSend will pay gas for **classic sends**. They have not described Soroban auth-entry submit, SAC `transfer` from a `C…`, or deploying user smart wallets.

**Implication for Sendra:** match SmoothSend — sponsored `G…` + fee-bump — unless they later ship a `C…` path. Claimable balances are a possible *receive* workaround if we do not sponsor trustlines up front; they add a claim step and are worse UX than sponsoring `CreateAccount` + `changeTrust` ourselves.

### Follow-up to send them

> Thanks — fee-bump is clear for classic `G…` payments.
>
> Sendra’s question is specifically **contract accounts (`C…`)**. Those cannot sign a transaction envelope, so fee-bump alone is not enough: the user signs Soroban **auth entries**, and a relayer `G…` is the tx source (Launchtube-style `func` + `auth`).
>
> Can you confirm:
> 1. Is Stellar gasless **only** fee-bump of a user-signed `G…` payment XDR (no `C…`)?
> 2. If you do support `C…`, what do we POST — signed envelope, or `func` + signed auth entries?
> 3. Claimable balances: is that so a `G…` can receive USDC before a trustline, and does the user/we still have to `changeTrust` + `claimClaimableBalance`? Do you submit that claim gaslessly too?
> 4. Do you activate accounts (`CreateAccount` / sponsored trustlines), or only fee-bump spends on accounts that already exist?
