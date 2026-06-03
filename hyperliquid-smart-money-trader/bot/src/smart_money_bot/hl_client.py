from __future__ import annotations

from typing import Any, cast

import eth_account
from hyperliquid.exchange import Exchange
from hyperliquid.info import Info
from hyperliquid.utils.types import SpotMeta

from smart_money_bot.config import Settings


def make_exchange(settings: Settings) -> Exchange | None:
    """Signed Exchange client for order placement. Returns None if key missing/invalid."""
    key = (settings.hl_secret_key or "").strip()
    if not key.startswith("0x") or len(key) < 66:
        return None
    try:
        wallet = eth_account.Account.from_key(key)
    except Exception:
        return None
    url = settings.hyperliquid_api_url
    empty_spot = cast(SpotMeta, {"universe": [], "tokens": []})
    return Exchange(wallet, base_url=url, spot_meta=empty_spot, timeout=10.0)


def make_info(settings: Settings) -> Info:
    """Build Info for perp data. Testnet spot_meta can list token indices that are
    missing from `tokens`, which makes the SDK crash in Info.__init__; we skip spot
    indexing there and still load perp universe via meta().

    On mainnet, calling spotMeta during startup can also hit rate limits; this bot
    currently needs perp universe + candle snapshots only, so we pass an empty
    spot_meta to avoid that extra request.
    """
    url = settings.hyperliquid_api_url
    empty_spot = cast(SpotMeta, {"universe": [], "tokens": []})
    return Info(url, skip_ws=True, spot_meta=empty_spot, timeout=6.0)


def fetch_mids(info: Info) -> dict[str, str]:
    return info.all_mids()


def fetch_meta_and_asset_ctxs(info: Info) -> Any:
    return info.meta_and_asset_ctxs()


def perp_coin_names_from_meta(meta_ctx: Any) -> frozenset[str]:
    """Listed perp symbols from `meta_and_asset_ctxs()` (first element is meta)."""
    if not meta_ctx or not isinstance(meta_ctx, (list, tuple)) or len(meta_ctx) < 1:
        return frozenset()
    meta = meta_ctx[0]
    if not isinstance(meta, dict):
        return frozenset()
    universe = meta.get("universe") or []
    return frozenset(
        str(u.get("name", "")).upper()
        for u in universe
        if isinstance(u, dict) and u.get("name")
    )


def fetch_candles(
    info: Info,
    coin: str,
    interval: str,
    start_time_ms: int,
    end_time_ms: int,
) -> list[Any]:
    """OHLCV bars from Hyperliquid info API."""
    return info.candles_snapshot(coin, interval, start_time_ms, end_time_ms)


def fetch_user_state(info: Info, address: str) -> Any | None:
    if not address or not address.startswith("0x"):
        return None
    return info.user_state(address)
