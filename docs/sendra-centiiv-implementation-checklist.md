# Sendra Aptos × Centiiv ramps — implementation checklist

Sendra uses **dedicated API client** (`SendraCentiivRampService`, `SENDRA_CENTIIV_*` on api.centiiv.io).

**Webhooks:** one HTTPS URL in Centiiv (often `CentiivWebhook.php` shared with logistics). Sendra conversion events are detected by `metadata.source === "sendra_whatsapp"` and handled separately inside that file. Logistics Direct Pay is unchanged (`payment.successful`, DPL/DRV_ACCEPT metadata).

Do **not** reuse logistics **API** credentials for ramps (`CentiivDirectPayService`, `CENTIIV_JWT`, `api.centiiv.com`) — those are a different product from Sendra’s `SENDRA_CENTIIV_*` aggregator keys.

Reference: [Create conversion order](https://c-vmch.readme.io/reference/createorder).

---

## Phase 0 — Repository and secrets

- [ ] Push local commits to GitHub (this environment may need a PAT, SSH, or `gh auth login`).
  - Branch: `backup/pre-logistics-flow-2026-04-13` (or merge to `main` per your release process).
- [ ] Obtain Centiiv **Sendra** (api.centiiv.io) credentials and confirm sandbox vs production base URL.
- [ ] Use a **public** API key for ramps: `pk_sandbox_*` or `pk_live_*` (not `cnt-*` Direct Pay keys).
- [ ] Add to `.env` (and document in `.env.example`):
  - `SENDRA_CENTIIV_JWT` or `SENDRA_CENTIIV_API_KEY` — must be `pk_*` for `/public/*` routes
  - `SENDRA_CENTIIV_BASE_URL` (default `https://api.centiiv.io`)
  - Optional `SENDRA_CENTIIV_PLATFORM` (maps to `X-Platform` header)
  - `CENTIIV_WEBHOOK_SECRET` on the shared `CentiivWebhook.php` URL (or `SENDRA_CENTIIV_WEBHOOK_SECRET` as fallback if the first is empty)
  - `SENDRA_PUBLIC_BASE_URL` and/or `SENDRA_CENTIIV_WEBHOOK_URL` for `webhookUrl` in create order

---

## Phase 1 — Sell (off-ramp) end-to-end

### 1.1 Product behaviour

- [ ] **Crypto-first**: user says e.g. `sell 20 USDT` → show approximate NGN out, fee, effective rate; then collect bank payout details; confirm; fund order.
- [ ] **Fiat-first** (optional for v1): `send 20000 naira to {account} {bank}` → show crypto to debit from wallet, rate, fee; confirm with PIN; fund order.
- [ ] Currency selection (NGN vs GHS, etc.) if required by Centiiv asset enums.

### 1.2 WhatsApp routing (`MessageController`)

- [ ] Route `sell` / `sell 20 usdt` / natural language variants to a dedicated handler (avoid colliding with `send ...` peer transfer).
- [ ] Multi-step state: `users.chat_context` (migration) + `pending_transactions` with `tx_type = offramp_centiiv`.

### 1.3 Centiiv API (`SendraCentiivRampService`)

- [ ] Build `createConversionOrder` payload for **USDT/USDC → NGN**:
  - `fromAsset`, `toAsset`, `amount` (per doc: source asset units)
  - `beneficiary` (bank fields per Centiiv schema)
  - `refundAddress` — Stellar address per doc; **confirm with Centiiv** for Aptos/Sendra.
  - `webhookUrl` → same host as logistics, e.g. `https://{host}/CentiivWebhook.php` (or `SENDRA_CENTIIV_WEBHOOK_URL`).
  - `metadata` — `user_phone`, etc.
- [ ] Parse **201** response: deposit address, order id, expiry.
- [ ] Handle **400/401** and show user-safe errors.

### 1.4 PIN and on-chain funding

- [ ] `handlePinConfirmation()` branch `offramp_centiiv`: `aptos-service` sends exact amount to Centiiv deposit address.
- [ ] Reconcile failures (ops alert + user messaging).

### 1.5 Webhooks

- [ ] `CentiivWebhook.php` — Sendra branch when `metadata.source === sendra_whatsapp`; logistics branch unchanged.
- [ ] On terminal states, notify user on WhatsApp (and extend with DB updates as needed).

### 1.6 Cleanup

- [ ] Remove or quarantine legacy `CentivService` if unused.
- [ ] Align Aptos network in balance/send (`testnet` vs `mainnet`) with production.

---

## Phase 2 — Buy (on-ramp)

- [ ] Flow: `buy` → local fiat → asset USDC/USDT → optional destination (default user wallet).
- [ ] `createConversionOrder` with `fromAsset` NGN, `toAsset` USDC/USDT, `destinationAddress` per doc.
- [ ] Present virtual bank account from API; webhook as source of truth.

---

## Phase 3 — Hardening

- [ ] Rate limits, idempotency keys if API supports them.
- [ ] 30-minute order expiry: cancel UI state if user does not fund.
- [ ] Audit log for quotes and order ids.
- [ ] E2E tests or scripted dry-runs in sandbox.

---

## Files (Sendra Centiiv only)

| Area | File / artifact |
|------|------------------|
| Ramp client | `src/Services/SendraCentiivRampService.php` |
| Send flow | `src/Controllers/MessageController.php` |
| Webhook | `CentiivWebhook.php` (shared URL; Sendra vs logistics by payload) |
| Env | `.env.example` (`SENDRA_CENTIIV_*`, `SENDRA_PUBLIC_BASE_URL`) |
| DB | `migrations/20260416_users_chat_context.sql` (sell wizard) |
| Node | `aptos-service` (existing execute transfer) |
