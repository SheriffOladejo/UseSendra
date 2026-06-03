from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from smart_money_bot.indicators import IndicatorsSnapshot


@dataclass(frozen=True)
class SignalScores:
    coin: str
    interval: str
    long_score: int
    short_score: int
    breakdown: dict[str, Any]


def _bool_pts(cond: bool, pts: int) -> int:
    return pts if cond else 0


def _as_float(x: Any) -> float | None:
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def parse_perp_ctx(meta_ctx: Any, coin: str) -> dict[str, float | None]:
    """Extract live perp fields from `meta_and_asset_ctxs()` for one coin."""
    out: dict[str, float | None] = {
        "funding": None,
        "open_interest": None,
        "mark_oracle_spread_pct": None,
        "premium": None,
    }
    if not meta_ctx or not isinstance(meta_ctx, (list, tuple)) or len(meta_ctx) < 2:
        return out
    meta, ctxs = meta_ctx[0], meta_ctx[1]
    if not isinstance(meta, dict) or not isinstance(ctxs, list):
        return out
    universe = meta.get("universe") or []
    idx = next((i for i, u in enumerate(universe) if str(u.get("name", "")).upper() == coin.upper()), None)
    if idx is None or idx >= len(ctxs):
        return out
    c = ctxs[idx]
    if not isinstance(c, dict):
        return out
    funding = _as_float(c.get("funding"))
    oi = _as_float(c.get("openInterest"))
    mark = _as_float(c.get("markPx"))
    oracle = _as_float(c.get("oraclePx"))
    prem = _as_float(c.get("premium"))
    spread_pct = None
    if mark is not None and oracle is not None and oracle != 0:
        spread_pct = (mark - oracle) / oracle * 100.0
    out["funding"] = funding
    out["open_interest"] = oi
    out["mark_oracle_spread_pct"] = spread_pct
    out["premium"] = prem
    return out


def _stack_bias(snap: IndicatorsSnapshot) -> str:
    v = snap.values
    e9, e21, e50 = v.get("ema_9"), v.get("ema_21"), v.get("ema_50")
    if e9 is not None and e21 is not None and e50 is not None:
        if e9 > e21 > e50:
            return "long"
        if e9 < e21 < e50:
            return "short"
    macd = v.get("macd")
    sig = v.get("macd_signal")
    if macd is not None and sig is not None:
        if macd > sig:
            return "long"
        if macd < sig:
            return "short"
    return "neutral"


# Higher-TF confirmation weights (spec: e.g. +10 for 4H confirm on lower TF).
_HTF_RULES: dict[str, list[tuple[str, int]]] = {
    "1m": [("1h", 3), ("4h", 5), ("1d", 2)],
    "1h": [("4h", 6), ("1d", 4)],
    "4h": [("1d", 10)],
    "1d": [],
}


def score_signal(
    ind: IndicatorsSnapshot,
    *,
    higher_tf: dict[str, IndicatorsSnapshot] | None = None,
    perp: dict[str, float | None] | None = None,
) -> SignalScores:
    v = ind.values
    ema9 = v.get("ema_9")
    ema21 = v.get("ema_21")
    ema50 = v.get("ema_50")
    adx = v.get("adx_14")
    di_p = v.get("di_plus")
    di_m = v.get("di_minus")
    rsi = v.get("rsi_14")
    rsi_prev = v.get("rsi_14_prev")
    macd = v.get("macd")
    macd_sig = v.get("macd_signal")
    macd_prev = v.get("macd_prev")
    macd_sig_prev = v.get("macd_signal_prev")
    vol_ratio = v.get("volume_ratio_ma20")
    last_close = v.get("last_close")
    last_open = v.get("last_open")
    atr = v.get("atr_14")
    sar = v.get("parabolic_sar")
    bb_u = v.get("bb_upper")
    bb_l = v.get("bb_lower")
    stoch = v.get("stoch_rsi")
    mfi = v.get("mfi_14")
    vwap = v.get("vwap")

    def ok(x: float | None) -> bool:
        return x is not None

    long_pts = 0
    short_pts = 0
    breakdown: dict[str, Any] = {"checks": [], "notes": [], "values": {}}

    def add_check(name: str, pts: int, long_cond: bool | None, short_cond: bool | None) -> None:
        nonlocal long_pts, short_pts
        lp = _bool_pts(bool(long_cond), pts) if long_cond is not None else 0
        sp = _bool_pts(bool(short_cond), pts) if short_cond is not None else 0
        long_pts += lp
        short_pts += sp
        breakdown["checks"].append(
            {
                "name": name,
                "pts": pts,
                "long": bool(long_cond) if long_cond is not None else None,
                "short": bool(short_cond) if short_cond is not None else None,
                "long_pts": lp,
                "short_pts": sp,
            }
        )

    breakdown["values"] = {
        "ema_9": ema9,
        "ema_21": ema21,
        "ema_50": ema50,
        "adx_14": adx,
        "di_plus": di_p,
        "di_minus": di_m,
        "rsi_14": rsi,
        "rsi_14_prev": rsi_prev,
        "macd": macd,
        "macd_signal": macd_sig,
        "atr_14": atr,
        "volume_ratio_ma20": vol_ratio,
        "parabolic_sar": sar,
        "last_close": last_close,
        "last_open": last_open,
        "perp": perp or {},
    }

    # EMA regime / cross
    if ok(ema9) and ok(ema21) and ok(ema50):
        add_check("EMA 9/21 cross", 15, ema9 > ema21, ema9 < ema21)
        add_check("Trend stack (9>21>50)", 10, ema9 > ema21 > ema50, ema9 < ema21 < ema50)
    else:
        breakdown["notes"].append("ema values missing")

    # ADX trend strength
    if ok(adx) and ok(di_p) and ok(di_m):
        strong = adx > 25
        add_check("ADX>25 & DI confirm", 8, strong and di_p > di_m, strong and di_m > di_p)
    else:
        breakdown["notes"].append("adx/di values missing")

    # RSI: zone + bounce from oversold / overbought
    if ok(rsi):
        add_check("RSI extreme zone", 8, rsi <= 35, rsi >= 65)
        bounce_long = (
            ok(rsi_prev)
            and rsi_prev < 36
            and rsi > rsi_prev
            and rsi <= 48
        )
        bounce_short = (
            ok(rsi_prev)
            and rsi_prev > 64
            and rsi < rsi_prev
            and rsi >= 52
        )
        add_check("RSI bounce (reversal)", 12, bounce_long, bounce_short)
        add_check("RSI mid-trend bias", 4, 45 <= rsi <= 60, 40 <= rsi <= 55)
    else:
        breakdown["notes"].append("rsi missing")

    # MACD: histogram bias + bullish/bearish cross
    if ok(macd) and ok(macd_sig):
        add_check("MACD vs signal line", 8, macd > macd_sig, macd < macd_sig)
    else:
        breakdown["notes"].append("macd missing")

    if ok(macd) and ok(macd_sig) and ok(macd_prev) and ok(macd_sig_prev):
        bull_x = macd_prev <= macd_sig_prev and macd > macd_sig
        bear_x = macd_prev >= macd_sig_prev and macd < macd_sig
        add_check("MACD crossover", 10, bull_x, bear_x)

    # Parabolic SAR trend
    if ok(last_close) and ok(sar):
        add_check("Parabolic SAR side", 8, last_close > sar, last_close < sar)
    else:
        breakdown["notes"].append("sar or close missing")

    # Bollinger: not pinned to wrong band (mean-reversion guard)
    if ok(last_close) and ok(bb_u) and ok(bb_l) and bb_u > bb_l:
        bw = bb_u - bb_l
        pos = (last_close - bb_l) / bw
        add_check("Not hugging lower band (long quality)", 5, pos > 0.12, None)
        add_check("Not hugging upper band (short quality)", 5, None, pos < 0.88)
    else:
        breakdown["notes"].append("bb width missing")

    # Volume spike vs 20-bar average, in direction of the candle (spec: >1.5×)
    if ok(vol_ratio) and ok(last_close) and ok(last_open):
        spike = vol_ratio >= 1.5
        bull_bar = last_close > last_open
        bear_bar = last_close < last_open
        add_check("Volume spike with candle", 7, spike and bull_bar, spike and bear_bar)
    elif ok(vol_ratio):
        add_check("Volume spike (direction neutral)", 4, vol_ratio >= 1.5, vol_ratio >= 1.5)
    else:
        breakdown["notes"].append("volume ratio missing")

    # Stochastic RSI + MFI confirmation
    if ok(stoch):
        add_check("Stoch RSI extreme", 5, stoch <= 0.2, stoch >= 0.8)
    if ok(mfi):
        add_check("MFI pressure", 5, mfi <= 28, mfi >= 72)

    # VWAP anchor
    if ok(last_close) and ok(vwap):
        add_check("Price vs VWAP", 5, last_close > vwap, last_close < vwap)

    # Higher timeframe EMA-stack confirmation (graded by TF)
    htf = higher_tf or {}
    for tf, pts in _HTF_RULES.get(ind.interval, []):
        snap = htf.get(tf)
        if snap is None:
            continue
        b = _stack_bias(snap)
        add_check(f"{tf.upper()} trend confirms", pts, b == "long", b == "short")

    # Perpetual context (live meta + asset ctx)
    px = perp or {}
    fund = px.get("funding")
    if fund is not None:
        thr = 0.00003
        add_check("Funding skew (contrarian)", 4, fund < -thr, fund > thr)

    mosp = px.get("mark_oracle_spread_pct")
    if mosp is not None:
        edge = 0.03
        add_check("Mark vs oracle (premium)", 3, mosp < -edge, mosp > edge)

    # Volatility sanity
    if ok(atr):
        add_check("ATR sanity", 2, atr > 0, atr > 0)

    long_score = int(max(0, min(100, long_pts)))
    short_score = int(max(0, min(100, short_pts)))
    return SignalScores(
        coin=ind.coin,
        interval=ind.interval,
        long_score=long_score,
        short_score=short_score,
        breakdown=breakdown,
    )
