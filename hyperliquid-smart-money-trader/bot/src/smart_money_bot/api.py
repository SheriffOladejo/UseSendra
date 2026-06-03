from __future__ import annotations

import threading
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from smart_money_bot.config import get_settings
from smart_money_bot import hl_client
from smart_money_bot.state import background_poll_loop, request_stop, runtime_state


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    thread = threading.Thread(
        target=background_poll_loop,
        kwargs={"settings": settings, "interval_sec": 60.0},
        daemon=True,
    )
    thread.start()
    yield
    request_stop()
    thread.join(timeout=5.0)


app = FastAPI(title="Smart Money Bot — Ops API", lifespan=lifespan)
_settings = get_settings()
app.add_middleware(
    CORSMiddleware,
    allow_origins=_settings.cors_origin_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "network": _settings.hl_network}


@app.get("/config/public")
def config_public() -> dict[str, Any]:
    has_key = bool(
        _settings.hl_secret_key
        and _settings.hl_secret_key.startswith("0x")
        and len(_settings.hl_secret_key.strip()) >= 66
    )
    return {
        "hl_network": _settings.hl_network,
        "api_url": _settings.hyperliquid_api_url,
        "account_configured": bool(
            _settings.hl_account_address and _settings.hl_account_address.startswith("0x")
        ),
        "trading_enabled": _settings.trading_enabled,
        "trading_dry_run": _settings.trading_dry_run,
        "trading_kill_switch": _settings.trading_kill_switch,
        "has_secret_key": has_key,
        "armed_live": _settings.trading_enabled
        and not _settings.trading_dry_run
        and not _settings.trading_kill_switch
        and has_key,
    }


@app.get("/bot/status")
def bot_status() -> dict[str, Any]:
    return runtime_state.snapshot()


@app.get("/market/mids")
def market_mids() -> dict[str, Any]:
    info = hl_client.make_info(_settings)
    return {"mids": hl_client.fetch_mids(info)}


@app.get("/market/asset/{coin}")
def market_asset(coin: str) -> dict[str, Any]:
    info = hl_client.make_info(_settings)
    _, ctxs = hl_client.fetch_meta_and_asset_ctxs(info)
    meta = info.meta()
    coin_upper = coin.upper()
    idx = next(
        (i for i, u in enumerate(meta["universe"]) if u["name"] == coin_upper),
        None,
    )
    if idx is None:
        return {"error": f"unknown coin: {coin_upper}"}
    return {"coin": coin_upper, "ctx": ctxs[idx]}


@app.get("/account/summary")
def account_summary() -> dict[str, Any]:
    addr = _settings.hl_account_address
    if not addr:
        return {"error": "set HL_ACCOUNT_ADDRESS in .env"}
    info = hl_client.make_info(_settings)
    state = hl_client.fetch_user_state(info, addr)
    if state is None:
        return {"error": "invalid address"}
    return {"address": addr, "state": state}


@app.get("/smart-money/profile")
def smart_money_profile(force_refresh: bool = False) -> dict[str, Any]:
    from smart_money_bot.smart_money import SmartMoneyConstraints, get_smart_money_profile

    if force_refresh:
        profile = get_smart_money_profile(settings=_settings, constraints=SmartMoneyConstraints(), force_refresh=True)
        with runtime_state.lock:
            runtime_state.smart_money = profile
        return {"profile": profile}
    with runtime_state.lock:
        sm = runtime_state.smart_money
    return {"profile": sm}


@app.get("/analysis/{coin}/indicators")
def analysis_indicators(coin: str, interval: str = "1m") -> dict[str, Any]:
    coin_u = coin.upper()
    with runtime_state.lock:
        by_tf = runtime_state.indicators.get(coin_u) or {}
        ind = by_tf.get(interval)
    if not ind:
        return {"error": f"no indicators yet for {coin_u} {interval}"}
    return {"coin": coin_u, "interval": interval, **ind}


@app.get("/analysis/{coin}/signal")
def analysis_signal(coin: str, interval: str = "1m") -> dict[str, Any]:
    coin_u = coin.upper()
    with runtime_state.lock:
        by_tf = runtime_state.signals.get(coin_u) or {}
        sig = by_tf.get(interval)
    if not sig:
        return {"error": f"no signal yet for {coin_u} {interval}"}
    return {"coin": coin_u, "interval": interval, **sig}


@app.get("/trades/recent")
def recent_trades(limit: int = 25) -> dict[str, Any]:
    with runtime_state.lock:
        trades = list(runtime_state.recent_trades)
    return {"trades": trades[: max(1, min(200, int(limit)))]}


@app.get("/dashboard/summary")
def dashboard_summary() -> dict[str, Any]:
    # IMPORTANT: runtime_state.snapshot() takes the lock internally, so we must not
    # call it while holding the same lock (non-reentrant) to avoid deadlocks.
    snap = runtime_state.snapshot()
    with runtime_state.lock:
        sm = runtime_state.smart_money
        pats = runtime_state.patterns
        corr = runtime_state.correlations
        inds = dict(runtime_state.indicators)
        sigs = dict(runtime_state.signals)
        candles = dict(runtime_state.candles)
        trades = list(runtime_state.recent_trades)
        exec_recent = list(runtime_state.execution_log[-15:])
        exec_status = dict(runtime_state.execution_status)
    return {
        "status": snap,
        "smart_money": sm,
        "patterns": pats,
        "correlations": corr,
        "execution": {"status": exec_status, "recent": exec_recent},
        "indicators": inds,
        "signals": sigs,
        "candles": candles,
        "recent_trades": trades,
    }
