Smart Money Bot (Python) — Hyperliquid testnet first
======================================================

1) python3 -m venv .venv && source .venv/bin/activate
2) pip install -e .
3) cp .env.example .env   (set HL_ACCOUNT_ADDRESS for /account/summary)
4) uvicorn smart_money_bot.api:app --reload --host 127.0.0.1 --port 8000

Testnet app: https://app.hyperliquid-testnet.xyz
API URL is selected via HL_NETWORK=testnet in .env (default).

Trade universe (indicators, signals, execution)
-------------------------------------------------
Default perps are set in config (28 majors + alts). Override in .env:
  TRADE_COINS=SUI,AVAX,ARB,...,SEI
Unlisted symbols are skipped each poll with a warning in the bot log.

Backtest (walk-forward, ATR TP/SL, fees, slippage; uses .env signal/trade settings)
-----------------------------------------------------------------
  python -m smart_money_bot.backtest --days 14 --entry-stride 5
  # or after pip install -e .:
  smart-money-backtest --days 7 --taker-fee-bps 4.5 --slippage 0.0003 --out results.json

Open results: meta.final_cash, meta.return_pct, trades[].
