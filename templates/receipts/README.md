# Sendra payout receipt (HTML/CSS)

Edit `payout.html` for layout and styling. Placeholders are filled by `SendraPayoutReceiptHtmlRenderer`.

| Placeholder | Source |
|-------------|--------|
| `amount_ngn` | NGN paid out |
| `withdraw_amount` | Crypto debited (e.g. `17.764 USDC`) |
| `conversion_rate` | e.g. `₦1,374.52` (from quote or NGN÷crypto) |
| `bank_name`, `account_name`, `account_number` | Beneficiary |
| `sender_name` | Usually **Nomba Financial Services** |
| `display_reference` | Same id as Opay **Remark** / Nomba `API-TRANSFER-…` when available |
| `completed_at` | Completion time (Africa/Lagos by default) |

Enable HTML rendering:

```env
SENDRA_RECEIPT_USE_HTML=1
# optional:
SENDRA_RECEIPT_CHROMIUM_PATH=/usr/bin/chromium-browser
```

Install Chromium on Ubuntu: `apt install chromium-browser`

Preview:

```bash
SENDRA_RECEIPT_USE_HTML=1 php src/scripts/generate_receipt_preview.php
```

You can replace `payout.html` entirely with your own design; keep the `{{placeholders}}` or add new ones in `SendraPayoutReceiptHtmlRenderer::viewModel()`.
