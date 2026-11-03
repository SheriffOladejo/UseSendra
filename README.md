# Sendra (UseSendra)

**Buy, sell, send, and receive digital dollars — right in WhatsApp.**

Sendra is a chat-first money product for Africa. Users message the Sendra WhatsApp number, create an account in one tap, and move USDC/USDT without installing a wallet app or paying gas in a separate token.

- **Product:** [usesendra.com](https://usesendra.com)
- **WhatsApp:** [wa.me/2348144111831](https://wa.me/2348144111831)
- **Repo:** [github.com/SheriffOladejo/UseSendra](https://github.com/SheriffOladejo/UseSendra)

---

## What Sendra does

| Capability | What the user experiences |
|---|---|
| **Buy** | Pay NGN (bank transfer / virtual account) → receive USDC or USDT on-chain |
| **Sell / cash out** | Send stablecoins → NGN lands in a Nigerian bank account |
| **Send** | Peer-to-peer USDC/USDT to a phone number (claimable voucher if the recipient is new) |
| **Receive** | Deposit USDC/USDT to the user’s address on a supported network |
| **Swap** | USDT ↔ USDC in-wallet |
| **Balance & history** | Check balances and recent activity in chat |
| **KYC** | Tiered identity verification before larger Naira rails |
| **PIN** | Sensitive actions confirm on a secure web PIN page (not typed into WhatsApp) |

Primary corridor today: **NGN ↔ USDC/USDT**. Expansion to more African currencies is on the roadmap.

---

## Supported networks

Sendra credits and moves stablecoins across multiple chains from the same WhatsApp account:

| Network | Role |
|---|---|
| **Aptos** | Core wallet, swaps (Panora), gasless sends where configured |
| **Arc** | USDC deposits, sends, and NGN off-ramp (USDC as gas) |
| **Stellar** | Shared deposit pool + per-user C-address spending |
| **Solana / Base / Arbitrum / BNB** (and related) | Cross-chain receive via bridge / deposit flows |

Exact asset + chain pairs depend on live ramp and bridge routing (Centiiv, 1Click, Daya, and chain indexers).

---

## Why Arc

On Arc, **USDC is the gas token**. That means a user who only holds USDC can receive, send, and cash out without needing a second token for fees — which matches how Sendra users already think about “dollars in chat.”

Sendra’s Arc path includes:

- Per-user Arc addresses and inbound USDC indexing
- Arc USDC sends after web PIN confirm
- Arc → NGN off-ramp through the same Centiiv settlement rails used elsewhere

---

## Non-custodial model (keys)

Sendra is designed so **users own their on-chain keys**; the product orchestrates UX, ramps, and signing services.

| Step | Who holds / controls what |
|---|---|
| Account creation | A wallet is created for the user; the private key is encrypted at rest (AES-256-GCM) and only decrypted inside the signing services to execute the user’s confirmed action |
| Receiving USDC/USDT | Funds land on the **user’s** chain address (or Stellar memo / pool credit mapped to that user) |
| Send / swap / sell (on-chain leg) | User confirms with PIN on the web page → PHP backend calls the internal signer (`aptos-service` / `stellar-service` / `arc-service`) → transaction is submitted from the user’s key material |
| NGN on-ramp / off-ramp | Fiat conversion is handled by **regulated ramp partners** (e.g. Centiiv, Daya). During that leg, settlement follows the partner’s custody and compliance model; Sendra does not operate as the user’s bank |
| PIN | Verified on HTTPS pages (`confirm-pin-v2.php`); WhatsApp never accepts the 6-digit PIN as a chat message |

Never commit real `.env` files, private keys, seed phrases, or webhook secrets to this repository.

---

## High-level architecture

```text
WhatsApp (Meta Cloud API)
        │
        ▼
   webhook.php  ──► aptos-v2 Kernel (step router)
        │              buy / sell / send / swap / …
        ▼
   MySQL (users, pending_transactions, history, deposits)
        │
        ├── web PIN pages (confirm-pin-v2.php, KYC, security questions)
        ├── ramp webhooks (Centiiv, Daya, Paystack, …)
        ├── chain indexers / pollers (Aptos, Stellar pool, Arc, 1Click)
        └── signing microservices (localhost only)
              ├── aptos-service   :3001
              ├── stellar-service :3002
              └── arc-service     :3003
```

**Stack:** PHP 8.2 (WhatsApp bot + webhooks + admin APIs), Node.js signers (Aptos TS, Stellar, Arc/viem), MySQL, Nginx, PM2, React admin / wallet client SPAs.

---

## Repository map

```text
.
├── webhook.php, CentiivWebhook.php, DayaWebhook.php, …
├── aptos-v2/           Live WhatsApp money bot (step router)
├── src/                Shared services, logistics bot, cron scripts
├── aptos-service/      Aptos signer + key encryption
├── stellar-service/    Stellar C-wallet signer
├── arc-service/        Arc USDC signer (viem)
├── confirm-pin*.php    Web PIN confirmation
├── kyc-verify.php      KYC flow
├── migrations/         SQL migrations
├── aptos-admin/        Admin SPA (source)
├── aptos-admin-api/    Admin JSON API
├── admin/              Legacy / logistics admin SPA source
└── docs/               Architecture and policy notes
```

Sibling copies used only on the production host (`wallet/`, `logistics/`) are **not** part of this repo. The marketing site and static client live separately under the public web root.

---

## Product surfaces

| Surface | Purpose |
|---|---|
| WhatsApp bot | Primary UX for money movement |
| Web PIN + KYC pages | Confirmation and compliance |
| Admin dashboards | Ops, monitoring, user support |
| Wallet client | Optional web wallet / Aptos Connect flows |
| Marketing site | [usesendra.com](https://usesendra.com) |

---

## Local / deploy notes

1. **Do not** copy production `.env` files into git. Configure secrets only on the server or in a private secret store.
2. PHP deps: `composer install`
3. Aptos signer: `cd aptos-service && npm install && npm run build`
4. Stellar / Arc signers: `npm install` in each service directory; they run `src/index.js` directly
5. Point Meta WhatsApp webhooks at `webhook.php`, and ramp webhooks at the matching PHP endpoints
6. Run DB migrations under `migrations/`
7. Process managers: PHP-FPM + Nginx for HTTP; PM2 for the three Node signers; cron for pollers/indexers under `scripts/` and `src/scripts/`

Required environment groups (names only — values stay private):

- Meta WhatsApp (`WHATSAPP_*`, `META_APP_SECRET`)
- Database (`APTOS_DB_*`, `LOGISTICS_DB_*`)
- Signer auth (`INTERNAL_API_SECRET`, `ENCRYPTION_KEY`)
- Ramps (`CENTIIV_*`, `DAYA_*`, `PAYSTACK_*`, 1Click / bridge keys)
- Chain RPCs and indexer credentials

---

## Security expectations for contributors

- Never commit `.env`, `.env.example`, `.env.operator`, keys, SQL dumps, or customer KYC packages
- Never paste private keys, seed phrases, or live balances into issues, screenshots, or demos
- Prefer rotating any credential that may have appeared in an old private backup
- Treat recovery / disburse scripts as production-breaking: they move money and message users

---

## Status

Sendra is live in production on WhatsApp and is demoing on **Arc Nigeria Builder Office Hours**. This repository is the application source for review — not a grant, investment, or custody offering.

---

## License / contact

Proprietary — All rights reserved unless otherwise noted.

Product: [usesendra.com](https://usesendra.com) · WhatsApp: [+234 814 411 1831](https://wa.me/2348144111831)
