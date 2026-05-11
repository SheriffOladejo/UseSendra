from __future__ import annotations

import time
from typing import Any


def _as_float(x: Any) -> float | None:
    try:
        return float(x)
    except Exception:
        return None


def _norm_asset(a: Any) -> str:
    return str(a or "").strip().upper()


def _trader_assets(t: dict[str, Any]) -> list[str]:
    raw = t.get("most_traded_assets")
    if not isinstance(raw, list):
        return []
    return [_norm_asset(x) for x in raw if _norm_asset(x)]


def _mentions_perp_coin(assets: list[str], coin: str) -> bool:
    cu = coin.upper()
    for a in assets:
        if a == cu:
            return True
        if a.startswith("@"):
            continue
        if "/" in a and a.split("/")[0] == cu:
            return True
    return False


def aggregate_smart_money_patterns(
    profile: dict[str, Any] | None,
    *,
    eth_signal: dict[str, Any] | None = None,
    sol_signal: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Roll up cross-trader stats from the smart-money profile for the Patterns tab."""
    if not profile or not isinstance(profile.get("traders"), list):
        return {
            "updated_at_unix": time.time(),
            "traders_analyzed": 0,
            "top_assets": [],
            "duration_buckets": {},
            "directional_bias": {},
            "margin_mix": {},
            "market_overlap_1h": {},
        }

    traders = [t for t in profile["traders"] if isinstance(t, dict)]
    n = len(traders)
    asset_counts: dict[str, int] = {}

    lt1h = 0
    b1_24h = 0
    gt24h = 0
    unknown_dur = 0

    long_bias = 0
    short_bias = 0
    balanced = 0

    iso = 0
    cross = 0
    unknown_margin = 0

    eth_overlap = 0
    sol_overlap = 0

    for t in traders:
        for a in _trader_assets(t):
            asset_counts[a] = asset_counts.get(a, 0) + 1

        d = t.get("avg_trade_duration_min")
        if isinstance(d, int) and d >= 0:
            if d < 60:
                lt1h += 1
            elif d < 24 * 60:
                b1_24h += 1
            else:
                gt24h += 1
        else:
            unknown_dur += 1

        wl = _as_float(t.get("win_rate_long"))
        ws = _as_float(t.get("win_rate_short"))
        if wl is not None and ws is not None:
            if wl > ws + 0.03:
                long_bias += 1
            elif ws > wl + 0.03:
                short_bias += 1
            else:
                balanced += 1
        else:
            balanced += 1

        mt = str(t.get("margin_type") or "").lower()
        if mt == "isolated":
            iso += 1
        elif mt == "cross":
            cross += 1
        else:
            unknown_margin += 1

        assets = _trader_assets(t)
        if _mentions_perp_coin(assets, "ETH"):
            eth_overlap += 1
        if _mentions_perp_coin(assets, "SOL"):
            sol_overlap += 1

    top_assets = [
        {"asset": k, "traders": v}
        for k, v in sorted(asset_counts.items(), key=lambda kv: kv[1], reverse=True)[:12]
    ]

    def _sig_bias(sig: dict[str, Any] | None) -> str | None:
        if not sig:
            return None
        lo = int(sig.get("long_score") or 0)
        sh = int(sig.get("short_score") or 0)
        if lo == sh:
            return "neutral"
        return "long" if lo > sh else "short"

    eth_b = _sig_bias(eth_signal)
    sol_b = _sig_bias(sol_signal)

    return {
        "updated_at_unix": time.time(),
        "traders_analyzed": n,
        "top_assets": top_assets,
        "duration_buckets": {
            "under_1h": lt1h,
            "1h_to_24h": b1_24h,
            "over_24h": gt24h,
            "unknown": unknown_dur,
        },
        "directional_bias": {
            "long_favored": long_bias,
            "short_favored": short_bias,
            "balanced": balanced,
        },
        "margin_mix": {
            "isolated": iso,
            "cross": cross,
            "unknown": unknown_margin,
        },
        "market_overlap_1h": {
            "eth_traders": eth_overlap,
            "sol_traders": sol_overlap,
            "eth_signal_bias": eth_b,
            "sol_signal_bias": sol_b,
        },
    }
