from __future__ import annotations

import math
import time
from typing import Any

from smart_money_bot.signals import parse_perp_ctx


def _pearson(xs: list[float], ys: list[float]) -> float | None:
    n = len(xs)
    if n < 10 or n != len(ys):
        return None
    mx = math.fsum(xs) / n
    my = math.fsum(ys) / n
    num = 0.0
    dx = 0.0
    dy = 0.0
    for x, y in zip(xs, ys):
        vx = x - mx
        vy = y - my
        num += vx * vy
        dx += vx * vx
        dy += vy * vy
    if dx <= 0 or dy <= 0:
        return None
    return num / math.sqrt(dx * dy)


def _log_returns(closes: list[float]) -> list[float]:
    out: list[float] = []
    for i in range(1, len(closes)):
        a, b = closes[i - 1], closes[i]
        if a > 0 and b > 0:
            out.append(math.log(b / a))
    return out


def _interpret_r(r: float | None) -> str:
    if r is None:
        return "insufficient data"
    ar = abs(r)
    if ar >= 0.75:
        return "strong co-movement"
    if ar >= 0.45:
        return "moderate linkage"
    if ar >= 0.2:
        return "weak / regime-dependent"
    return "very low linkage"


def build_correlations(
    *,
    candles: dict[str, dict[str, Any]],
    signals: dict[str, dict[str, Any]],
    meta_ctx: Any,
) -> dict[str, Any]:
    """ETH↔SOL rollups for the dashboard Correlations tab (no extra network I/O)."""
    intervals = ["1m", "1h", "4h", "1d"]
    eth_ctx = parse_perp_ctx(meta_ctx, "ETH")
    sol_ctx = parse_perp_ctx(meta_ctx, "SOL")
    fe, se = eth_ctx.get("funding"), sol_ctx.get("funding")
    funding_spread = None
    if fe is not None and se is not None:
        funding_spread = fe - se
    pe, ps = eth_ctx.get("mark_oracle_spread_pct"), sol_ctx.get("mark_oracle_spread_pct")
    premium_spread = None
    if pe is not None and ps is not None:
        premium_spread = pe - ps

    by_interval: dict[str, Any] = {}
    bias_rows: list[dict[str, Any]] = []

    for iv in intervals:
        ec = candles.get("ETH", {}).get(iv, {}) or {}
        sc = candles.get("SOL", {}).get(iv, {}) or {}
        a = list(ec.get("closes") or [])
        b = list(sc.get("closes") or [])
        n = min(len(a), len(b))
        if n >= 2:
            a = a[-n:]
            b = b[-n:]
        r_close = _pearson(a, b) if n >= 10 else None

        ra = _log_returns(a)
        rb = _log_returns(b)
        m = min(len(ra), len(rb))
        r_ret = _pearson(ra[-m:], rb[-m:]) if m >= 10 else None

        by_interval[iv] = {
            "closes_pearson": None if r_close is None else round(r_close, 4),
            "returns_pearson": None if r_ret is None else round(r_ret, 4),
            "sample_bars": n,
            "returns_sample": m if m >= 10 else 0,
            "closes_label": _interpret_r(r_close),
            "returns_label": _interpret_r(r_ret),
        }

        es = signals.get("ETH", {}).get(iv, {}) or {}
        ss = signals.get("SOL", {}).get(iv, {}) or {}
        el = int(es.get("long_score") or 0)
        eth_s = int(es.get("short_score") or 0)
        sl = int(ss.get("long_score") or 0)
        sol_s = int(ss.get("short_score") or 0)
        eth_net = el - eth_s
        sol_net = sl - sol_s
        if el + eth_s + sl + sol_s == 0:
            aligned = None
        elif eth_net == 0 and sol_net == 0:
            aligned = True
        elif eth_net == 0 or sol_net == 0:
            aligned = False
        else:
            aligned = (eth_net > 0) == (sol_net > 0)
        bias_rows.append(
            {
                "interval": iv,
                "eth_net_score": eth_net,
                "sol_net_score": sol_net,
                "bias_aligned": aligned,
            }
        )

    agree = sum(1 for row in bias_rows if row.get("bias_aligned") is True)
    disagree = sum(1 for row in bias_rows if row.get("bias_aligned") is False)

    return {
        "updated_at_unix": time.time(),
        "by_interval": by_interval,
        "bias_by_interval": bias_rows,
        "bias_summary": {
            "aligned_timeframes": agree,
            "divergent_timeframes": disagree,
            "total": len(bias_rows),
        },
        "perp": {
            "eth_funding": fe,
            "sol_funding": se,
            "funding_spread": funding_spread,
            "eth_mark_oracle_spread_pct": pe,
            "sol_mark_oracle_spread_pct": ps,
            "mark_oracle_spread_diff_pct": premium_spread,
        },
    }
