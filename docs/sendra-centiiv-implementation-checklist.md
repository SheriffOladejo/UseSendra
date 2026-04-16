# Sendra Aptos × Centiiv aggregator — implementation checklist

Separate from **logistics** Centiiv Direct Pay (`CentiivDirectPayService`, `CENTIIV_JWT`, `api.centiiv.com`). Sendra buy/sell uses the **aggregator** API (`CentiivAggregatorService`, `CENTIIV_AGGREGATOR_*`, `api.centiiv.io`).

Reference: [Create conversion order](https://c-vmch.readme.io/reference/createorder).

---

## Phase 0 — Repository and secrets

- [ ] Push local commits to GitHub (this environment may need a PAT, SSH, or `gh auth login`).
  - Branch: `backup/pre-logistics-flow-2026-04-13` (or merge to `main` per your release process).
- [ ] Obtain Centiiv **aggregator** credentials and confirm sandbox vs production base URL.
- [ ] Add to `.env` (and document in `.env.example`):
  - `CENTIIV_AGGREGATOR_JWT` or `CENTIIV_AGGREGATOR_API_KEY`
  - `CENTIIV_AGGREGATOR_BASE_URL` (default `https://api.centiiv.io`)
  - Optional `CENTIIV_AGGREGATOR_PLATFORM` (maps to `X-Platform` header)
  - `CENTIIV_AGGREGATOR_WEBHOOK_SECRET` (once Centiiv/Svix documents verification for this product)

---

## Phase 1 — Sell (off-ramp) end-to-end

### 1.1 Product behaviour

- [ ] **Crypto-first**: user says e.g. `sell 20 USDT` → show approximate NGN out, fee, effective rate; then collect bank payout details; confirm; fund order.
- [ ] **Fiat-first** (optional for v1): `send 20000 naira to {account} {bank}` → show crypto to debit from wallet, rate, fee; confirm with PIN; fund order.
- [ ] Currency selection (NGN vs GHS, etc.) if required by Centiiv asset enums.

### 1.2 WhatsApp routing (`MessageController`)

- [ ] Route `sell` / `sell 20 usdt` / natural language variants to a dedicated handler (avoid colliding with `send ...` peer transfer).
- [ ] Re-introduce or replace the old `handleOfframpCommand` path: ensure it is **actually called** from the main `switch`.
- [ ] Multi-step state: either extend `pending_transactions` with `tx_type = offramp_centiiv` and JSON details, or a small `centiiv_orders` table keyed by `user_phone` + `order_id`.

### 1.3 Centiiv API

- [ ] Build `createConversionOrder` payload for **USDT/USDC → NGN**:
  - `fromAsset`, `toAsset`, `amount` (per doc: source asset units)
  - `beneficiary` (bank fields per Centiiv schema)
  - `refundAddress` — Stellar address per doc; **confirm with Centiiv** whether Aptos/refund semantics differ or another field is used for Sendra.
  - `webhookUrl` — public HTTPS endpoint dedicated to **Sendra** (not `CentiivWebhook.php` for logistics).
  - `metadata` — `user_phone`, internal pending id, WhatsApp message id, etc.
- [ ] Parse **201** response: persist `orderId`, deposit wallet address / instructions, quoted NGN, expiry.
- [ ] Handle **400/401** and show user-safe errors.

### 1.4 PIN and on-chain funding

- [ ] After user confirms quote + bank details, queue pending action.
- [ ] Extend `handlePinConfirmation()` to branch on `tx_type`:
  - For off-ramp: call `aptos-service` to send **exact** stable amount to Centiiv **temporary deposit address** from response (not a hardcoded burn wallet unless product requires it).
- [ ] Reconcile: if chain send succeeds but order never fulfills, define ops alert + user messaging (reuse patterns from old `executeOfframp`).

### 1.5 Webhooks

- [ ] New endpoint e.g. `CentiivAggregatorWebhook.php` (or namespaced route) — **do not** mix with logistics Svix handler unless payload shape is identical.
- [ ] Verify signature per Centiiv/Svix docs.
- [ ] On terminal states, notify user on WhatsApp and update DB.

### 1.6 Cleanup

- [ ] Remove or quarantine `CentivService` / hardcoded test keys once Centiiv aggregator is live.
- [ ] Align Aptos network in balance/send (`testnet` vs `mainnet`) with production.

---

## Phase 2 — Buy (on-ramp)

- [ ] Flow: `buy` → local fiat → asset USDC/USDT → optional destination Aptos address (default user wallet).
- [ ] `createConversionOrder` with `fromAsset` NGN, `toAsset` USDC/USDT, `destinationAddress` per doc (validate format Centiiv expects).
- [ ] Present **virtual bank account** payment instructions from API response; optional “I paid” only if webhook is delayed (prefer webhook as source of truth).
- [ ] Webhook → confirm → optional “crypto deposit” style notification (reuse copy patterns).

---

## Phase 3 — Hardening

- [ ] Rate limits, idempotency keys if API supports them.
- [ ] 30-minute order expiry: cancel UI state if user does not fund.
- [ ] Audit log for quotes and order ids.
- [ ] E2E tests or scripted dry-runs in sandbox.

---

## Files touched (expected)

| Area | File / artifact |
|------|------------------|
| Aggregator client | `src/Services/CentiivAggregatorService.php` |
| Send flow | `src/Controllers/MessageController.php` |
| Webhook | new `CentiivAggregatorWebhook.php` (or equivalent) |
| Env | `.env.example` |
| DB | migration for order / pending state if needed |
| Node | `aptos-service` if new params for Centiiv deposit transfers |
