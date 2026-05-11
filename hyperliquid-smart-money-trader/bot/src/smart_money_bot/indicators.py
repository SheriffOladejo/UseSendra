from __future__ import annotations

from dataclasses import dataclass
from math import fabs
from typing import Any


def _to_float(x: Any) -> float:
    try:
        return float(x)
    except Exception:
        return float("nan")


def _sma(values: list[float], length: int) -> list[float]:
    if length <= 0:
        return [float("nan")] * len(values)
    out: list[float] = [float("nan")] * len(values)
    s = 0.0
    for i, v in enumerate(values):
        s += v
        if i >= length:
            s -= values[i - length]
        if i >= length - 1:
            out[i] = s / length
    return out


def _ema(values: list[float], length: int) -> list[float]:
    if length <= 0 or not values:
        return [float("nan")] * len(values)
    out: list[float] = [float("nan")] * len(values)
    alpha = 2.0 / (length + 1.0)
    ema = values[0]
    out[0] = ema
    for i in range(1, len(values)):
        ema = alpha * values[i] + (1.0 - alpha) * ema
        out[i] = ema
    return out


def _rsi(close: list[float], length: int = 14) -> list[float]:
    if len(close) < 2:
        return [float("nan")] * len(close)
    gains = [0.0] * len(close)
    losses = [0.0] * len(close)
    for i in range(1, len(close)):
        d = close[i] - close[i - 1]
        gains[i] = max(0.0, d)
        losses[i] = max(0.0, -d)
    avg_g = _sma(gains, length)
    avg_l = _sma(losses, length)
    out: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length:
            continue
        if avg_l[i] == 0.0:
            out[i] = 100.0
        else:
            rs = avg_g[i] / avg_l[i]
            out[i] = 100.0 - (100.0 / (1.0 + rs))
    return out


def _true_range(high: list[float], low: list[float], close: list[float]) -> list[float]:
    tr: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i == 0:
            tr[i] = high[i] - low[i]
        else:
            tr[i] = max(
                high[i] - low[i],
                fabs(high[i] - close[i - 1]),
                fabs(low[i] - close[i - 1]),
            )
    return tr


def _atr(high: list[float], low: list[float], close: list[float], length: int = 14) -> list[float]:
    tr = _true_range(high, low, close)
    return _ema(tr, length)


def _bb(close: list[float], length: int = 20, mult: float = 2.0) -> tuple[list[float], list[float], list[float]]:
    mid = _sma(close, length)
    out_u: list[float] = [float("nan")] * len(close)
    out_l: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length - 1:
            continue
        window = close[i - length + 1 : i + 1]
        m = mid[i]
        var = sum((v - m) ** 2 for v in window) / length
        sd = var**0.5
        out_u[i] = m + mult * sd
        out_l[i] = m - mult * sd
    return out_u, mid, out_l


def _donchian(high: list[float], low: list[float], length: int = 20) -> tuple[list[float], list[float]]:
    up: list[float] = [float("nan")] * len(high)
    dn: list[float] = [float("nan")] * len(low)
    for i in range(len(high)):
        if i < length - 1:
            continue
        up[i] = max(high[i - length + 1 : i + 1])
        dn[i] = min(low[i - length + 1 : i + 1])
    return up, dn


def _obv(close: list[float], volume: list[float]) -> list[float]:
    if not close:
        return []
    out = [0.0] * len(close)
    for i in range(1, len(close)):
        if close[i] > close[i - 1]:
            out[i] = out[i - 1] + volume[i]
        elif close[i] < close[i - 1]:
            out[i] = out[i - 1] - volume[i]
        else:
            out[i] = out[i - 1]
    return out


def _vwap(high: list[float], low: list[float], close: list[float], volume: list[float]) -> list[float]:
    out: list[float] = [float("nan")] * len(close)
    pv = 0.0
    vv = 0.0
    for i in range(len(close)):
        typical = (high[i] + low[i] + close[i]) / 3.0
        pv += typical * volume[i]
        vv += volume[i]
        out[i] = pv / vv if vv else float("nan")
    return out


def _mfi(high: list[float], low: list[float], close: list[float], volume: list[float], length: int = 14) -> list[float]:
    tp = [(high[i] + low[i] + close[i]) / 3.0 for i in range(len(close))]
    pos = [0.0] * len(close)
    neg = [0.0] * len(close)
    for i in range(1, len(close)):
        mf = tp[i] * volume[i]
        if tp[i] > tp[i - 1]:
            pos[i] = mf
        elif tp[i] < tp[i - 1]:
            neg[i] = mf
    out: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length:
            continue
        ps = sum(pos[i - length + 1 : i + 1])
        ns = sum(neg[i - length + 1 : i + 1])
        if ns == 0.0:
            out[i] = 100.0
        else:
            mr = ps / ns
            out[i] = 100.0 - (100.0 / (1.0 + mr))
    return out


def _cci(high: list[float], low: list[float], close: list[float], length: int = 20) -> list[float]:
    tp = [(high[i] + low[i] + close[i]) / 3.0 for i in range(len(close))]
    sma = _sma(tp, length)
    out: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length - 1:
            continue
        window = tp[i - length + 1 : i + 1]
        m = sma[i]
        md = sum(fabs(v - m) for v in window) / length
        out[i] = (tp[i] - m) / (0.015 * md) if md else float("nan")
    return out


def _williams_r(high: list[float], low: list[float], close: list[float], length: int = 14) -> list[float]:
    out: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length - 1:
            continue
        hh = max(high[i - length + 1 : i + 1])
        ll = min(low[i - length + 1 : i + 1])
        denom = (hh - ll)
        out[i] = -100.0 * ((hh - close[i]) / denom) if denom else float("nan")
    return out


def _stoch_rsi(close: list[float], length: int = 14, stoch_length: int = 14) -> list[float]:
    rsi = _rsi(close, length=length)
    out: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < (length + stoch_length):
            continue
        window = [x for x in rsi[i - stoch_length + 1 : i + 1] if x == x]
        if not window:
            continue
        lo = min(window)
        hi = max(window)
        denom = hi - lo
        out[i] = (rsi[i] - lo) / denom if denom else float("nan")
    return out


def _macd(close: list[float], fast: int = 12, slow: int = 26, signal: int = 9) -> tuple[list[float], list[float], list[float]]:
    ema_f = _ema(close, fast)
    ema_s = _ema(close, slow)
    macd = [ema_f[i] - ema_s[i] for i in range(len(close))]
    sig = _ema(macd, signal)
    hist = [macd[i] - sig[i] for i in range(len(close))]
    return macd, sig, hist


def _adx(high: list[float], low: list[float], close: list[float], length: int = 14) -> tuple[list[float], list[float], list[float]]:
    plus_dm = [0.0] * len(close)
    minus_dm = [0.0] * len(close)
    for i in range(1, len(close)):
        up_move = high[i] - high[i - 1]
        down_move = low[i - 1] - low[i]
        plus_dm[i] = up_move if (up_move > down_move and up_move > 0) else 0.0
        minus_dm[i] = down_move if (down_move > up_move and down_move > 0) else 0.0
    tr = _true_range(high, low, close)
    atr = _ema(tr, length)
    plus_di: list[float] = [float("nan")] * len(close)
    minus_di: list[float] = [float("nan")] * len(close)
    dx: list[float] = [float("nan")] * len(close)
    for i in range(len(close)):
        if i < length:
            continue
        atr_i = atr[i]
        if not atr_i or atr_i != atr_i:
            continue
        p = 100.0 * (_ema(plus_dm, length)[i] / atr_i) if atr_i else float("nan")
        m = 100.0 * (_ema(minus_dm, length)[i] / atr_i) if atr_i else float("nan")
        plus_di[i] = p
        minus_di[i] = m
        denom = p + m
        dx[i] = 100.0 * (fabs(p - m) / denom) if denom else float("nan")
    adx = _ema([0.0 if x != x else x for x in dx], length)
    return adx, plus_di, minus_di


def _parabolic_sar(
    high: list[float],
    low: list[float],
    close: list[float],
    *,
    af: float = 0.02,
    af_max: float = 0.2,
) -> list[float]:
    """Classic Wilder-style Parabolic SAR (same-iteration highs/lows constraints)."""
    n = len(close)
    out: list[float] = [float("nan")] * n
    if n < 2:
        return out
    bull = close[1] >= close[0]
    if bull:
        sar = min(low[0], low[1])
        ep = max(high[0], high[1])
    else:
        sar = max(high[0], high[1])
        ep = min(low[0], low[1])
    afv = af
    out[0] = sar
    for i in range(1, n):
        prev_sar = sar
        if bull:
            sar = prev_sar + afv * (ep - prev_sar)
            sar = min(sar, low[i - 1])
            if i >= 2:
                sar = min(sar, low[i - 2])
            if low[i] < sar:
                bull = False
                sar = ep
                ep = low[i]
                afv = af
            else:
                if high[i] > ep:
                    ep = high[i]
                    afv = min(afv + af, af_max)
        else:
            sar = prev_sar + afv * (ep - prev_sar)
            sar = max(sar, high[i - 1])
            if i >= 2:
                sar = max(sar, high[i - 2])
            if high[i] > sar:
                bull = True
                sar = ep
                ep = high[i]
                afv = af
            else:
                if low[i] < ep:
                    ep = low[i]
                    afv = min(afv + af, af_max)
        out[i] = sar
    return out


def _ichimoku(high: list[float], low: list[float]) -> dict[str, list[float]]:
    # Standard periods: 9, 26, 52. We return the "current" lines (no forward shifting),
    # as the dashboard needs a single snapshot.
    def mid(h: list[float], l: list[float], length: int) -> list[float]:
        out = [float("nan")] * len(h)
        for i in range(len(h)):
            if i < length - 1:
                continue
            hh = max(h[i - length + 1 : i + 1])
            ll = min(l[i - length + 1 : i + 1])
            out[i] = (hh + ll) / 2.0
        return out

    tenkan = mid(high, low, 9)
    kijun = mid(high, low, 26)
    span_b = mid(high, low, 52)
    span_a = [(tenkan[i] + kijun[i]) / 2.0 if tenkan[i] == tenkan[i] and kijun[i] == kijun[i] else float("nan") for i in range(len(high))]
    return {"tenkan": tenkan, "kijun": kijun, "span_a": span_a, "span_b": span_b}


def _keltner(high: list[float], low: list[float], close: list[float], length: int = 20, mult: float = 1.5) -> tuple[list[float], list[float], list[float]]:
    mid = _ema(close, length)
    atr = _atr(high, low, close, length=length)
    up = [mid[i] + mult * atr[i] for i in range(len(close))]
    dn = [mid[i] - mult * atr[i] for i in range(len(close))]
    return up, mid, dn


def normalize_candles(candles: list[dict[str, Any]]) -> dict[str, list[float]]:
    # Hyperliquid candle schema: {T, t, o, h, l, c, v, n, i, s}
    t: list[float] = []
    o: list[float] = []
    h: list[float] = []
    l: list[float] = []
    c: list[float] = []
    v: list[float] = []
    for cd in candles:
        t.append(_to_float(cd.get("t") if "t" in cd else cd.get("T")))
        o.append(_to_float(cd.get("o")))
        h.append(_to_float(cd.get("h")))
        l.append(_to_float(cd.get("l")))
        c.append(_to_float(cd.get("c")))
        v.append(_to_float(cd.get("v")))
    return {"t": t, "o": o, "h": h, "l": l, "c": c, "v": v}


@dataclass(frozen=True)
class IndicatorsSnapshot:
    coin: str
    interval: str
    end_time_ms: int
    values: dict[str, float | None]


def compute_indicators(*, coin: str, interval: str, candles: list[dict[str, Any]]) -> IndicatorsSnapshot:
    norm = normalize_candles(candles)
    close = norm["c"]
    high = norm["h"]
    low = norm["l"]
    vol = norm["v"]
    end_t = int(norm["t"][-1]) if norm["t"] else 0

    def last(xs: list[float]) -> float | None:
        if not xs:
            return None
        x = xs[-1]
        if x != x:  # nan
            return None
        return float(f"{x:.6f}")

    def prev(xs: list[float]) -> float | None:
        if len(xs) < 2:
            return None
        x = xs[-2]
        if x != x:
            return None
        return float(f"{x:.6f}")

    opens = norm["o"]
    ema9 = _ema(close, 9)
    ema21 = _ema(close, 21)
    ema50 = _ema(close, 50)
    ema200 = _ema(close, 200)
    adx, di_p, di_m = _adx(high, low, close, length=14)
    rsi14 = _rsi(close, length=14)
    macd, macd_sig, macd_hist = _macd(close, 12, 26, 9)
    stoch_rsi = _stoch_rsi(close, 14, 14)
    cci20 = _cci(high, low, close, 20)
    willr14 = _williams_r(high, low, close, 14)
    mfi14 = _mfi(high, low, close, vol, 14)
    vol_ma20 = _sma(vol, 20)
    obv = _obv(close, vol)
    vwap = _vwap(high, low, close, vol)
    bb_u, bb_m, bb_l = _bb(close, 20, 2.0)
    kc_u, kc_m, kc_l = _keltner(high, low, close, 20, 1.5)
    dc_u, dc_l = _donchian(high, low, 20)
    atr14 = _atr(high, low, close, 14)
    ichi = _ichimoku(high, low)
    psar = _parabolic_sar(high, low, close)
    last_c = last(close)
    last_o = last(opens)
    last_v = last(vol)
    vma_last = last(vol_ma20)
    vol_ratio = None
    if last_v is not None and vma_last is not None and vma_last > 0:
        vol_ratio = float(f"{(last_v / vma_last):.4f}")

    values: dict[str, float | None] = {
        # Trend
        "ema_9": last(ema9),
        "ema_21": last(ema21),
        "ema_50": last(ema50),
        "ema_200": last(ema200),
        "adx_14": last(adx),
        "di_plus": last(di_p),
        "di_minus": last(di_m),
        "parabolic_sar": last(psar),
        "ichimoku_tenkan": last(ichi["tenkan"]),
        "ichimoku_kijun": last(ichi["kijun"]),
        "ichimoku_span_a": last(ichi["span_a"]),
        "ichimoku_span_b": last(ichi["span_b"]),
        # Momentum
        "rsi_14": last(rsi14),
        "rsi_14_prev": prev(rsi14),
        "macd": last(macd),
        "macd_signal": last(macd_sig),
        "macd_prev": prev(macd),
        "macd_signal_prev": prev(macd_sig),
        "macd_hist": last(macd_hist),
        "stoch_rsi": last(stoch_rsi),
        "cci_20": last(cci20),
        "williams_r": last(willr14),
        "mfi_14": last(mfi14),
        # Volume
        "last_open": last_o,
        "last_close": last_c,
        "last_volume": last_v,
        "volume_ratio_ma20": vol_ratio,
        "volume_ma_20": last(vol_ma20),
        "obv": last(obv),
        "vwap": last(vwap),
        # Volatility / Structure
        "bb_upper": last(bb_u),
        "bb_middle": last(bb_m),
        "bb_lower": last(bb_l),
        "kc_upper": last(kc_u),
        "kc_middle": last(kc_m),
        "kc_lower": last(kc_l),
        "donchian_upper": last(dc_u),
        "donchian_lower": last(dc_l),
        "atr_14": last(atr14),
    }

    return IndicatorsSnapshot(coin=coin.upper(), interval=interval, end_time_ms=end_t, values=values)

