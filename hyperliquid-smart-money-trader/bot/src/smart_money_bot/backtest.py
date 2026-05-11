"""Walk-forward backtest: replay indicators + signals, simulate entries with ATR TP/SL, fees, slippage."""

from __future__ import annotations

import json
import random
import time
from dataclasses import dataclass, field
from typing import Any

from smart_money_bot.config import Settings, get_settings
from smart_money_bot import hl_client
from smart_money_bot.indicators import IndicatorsSnapshot, compute_indicators
from smart_money_bot.signals import score_signal

_TRADE_COINS = ("ETH", "SOL")
_VALID_TF = frozenset({"1m", "1h", "4h", "1d"})
_TF_MS = {"1m": 60_000, "1h": 3_600_000, "4h": 14_400_000, "1d": 86_400_000}

_HIGHER_TF_FOR: dict[str, list[str]] = {
    "1m": ["1h", "4h", "1d"],
    "1h": ["4h", "1d"],
    "4h": ["1d"],
    "1d": [],
}


def _candle_t(cd: dict[str, Any]) -> int:
    v = cd.get("t") if "t" in cd else cd.get("T")
    return int(float(v))


def _start_for(interval: str, end_ms: int) -> int:
    if interval == "1m":
        return end_ms - int(6 * 60 * 60 * 1000)
    if interval == "1h":
        return end_ms - int(21 * 24 * 60 * 60 * 1000)
    if interval == "4h":
        return end_ms - int(120 * 24 * 60 * 60 * 1000)
    if interval == "1d":
        return end_ms - int(420 * 24 * 60 * 60 * 1000)
    return end_ms - int(6 * 60 * 60 * 1000)


def _history_fetch_start(interval: str, sim_start_ms: int) -> int:
    """Earliest time to fetch for this interval (warmup before first simulated bar)."""
    if interval == "1m":
        return sim_start_ms - int(12 * 60 * 60 * 1000)
    if interval == "1h":
        return sim_start_ms - int(21 * 24 * 60 * 60 * 1000)
    if interval == "4h":
        return sim_start_ms - int(120 * 24 * 60 * 60 * 1000)
    if interval == "1d":
        return sim_start_ms - int(420 * 24 * 60 * 60 * 1000)
    return sim_start_ms - int(12 * 60 * 60 * 1000)


def _fetch_candles_chunked(
    info: Any,
    coin: str,
    interval: str,
    start_ms: int,
    end_ms: int,
    *,
    chunk_ms: int,
) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    cur = start_ms
    while cur < end_ms:
        nxt = min(cur + chunk_ms, end_ms)
        part: list[Any] | None = None
        for attempt in range(6):
            try:
                part = hl_client.fetch_candles(info, coin, interval, cur, nxt)
                break
            except Exception as exc:  # noqa: BLE001
                code = getattr(exc, "status_code", None)
                msg = str(exc).lower()
                if code == 429 or "429" in msg or "rate" in msg:
                    time.sleep(min(30.0, 0.8 * (2**attempt) + random.random()))
                    continue
                raise
        if part:
            out.extend(part)
        cur = nxt
    by_t: dict[int, dict[str, Any]] = {}
    for c in out:
        t = _candle_t(c)
        by_t[t] = c
    return [by_t[k] for k in sorted(by_t.keys())]


def _candles_until(series: list[dict[str, Any]], t_open_max: int) -> list[dict[str, Any]]:
    return [c for c in series if _candle_t(c) <= t_open_max]


def _pick_side(settings: Settings, long_score: int, short_score: int) -> str | None:
    edge = settings.signal_min_edge
    if long_score >= settings.signal_long_threshold and long_score >= short_score + edge:
        return "LONG"
    if short_score >= settings.signal_short_threshold and short_score >= long_score + edge:
        return "SHORT"
    return None


def _size_from_mid(notional_usd: float, mid: float) -> float | None:
    if mid <= 0 or notional_usd <= 0:
        return None
    return round(notional_usd / mid, 6)


def _tpsl_levels(
    *,
    entry: float,
    is_long: bool,
    atr_val: float | None,
    settings: Settings,
) -> tuple[float | None, float | None]:
    if not settings.trade_attach_tpsl:
        return (None, None)
    if atr_val is None or atr_val <= 0:
        return (None, None)
    sl_d = float(settings.trade_sl_atr_mult) * atr_val
    tp_d = float(settings.trade_tp_atr_mult) * atr_val
    if is_long:
        return (max(0.0, entry - sl_d), entry + tp_d)
    return (entry + sl_d, max(0.0, entry - tp_d))


def _fee_usd(*, size: float, px: float, fee_rate: float) -> float:
    return abs(size) * px * fee_rate


def _apply_slip_exit(*, trigger_px: float, is_buy_to_close: bool, slippage: float) -> float:
    """Close long = sell -> worse fill slightly below; close short = buy -> slightly above."""
    if is_buy_to_close:
        return trigger_px * (1.0 + slippage)
    return trigger_px * (1.0 - slippage)


@dataclass
class _Leg:
    coin: str
    side: str
    entry_px: float
    sz: float
    sl: float | None
    tp: float | None
    entry_t: int
    entry_fee: float


@dataclass
class _BtState:
    cash: float
    legs: dict[str, _Leg] = field(default_factory=dict)
    last_entry_ts: dict[str, float] = field(default_factory=dict)
    trades: list[dict[str, Any]] = field(default_factory=list)
    equity_samples: list[dict[str, Any]] = field(default_factory=list)


def _open_count(state: _BtState) -> int:
    return sum(1 for _ in state.legs.values())


def _check_exit_intrabar(
    *,
    leg: _Leg,
    hi: float,
    lo: float,
    fee_rate: float,
    slippage: float,
) -> tuple[float, str] | None:
    """Conservative: SL before TP if both touched. Returns (exit_px_after_slip, reason)."""
    if leg.sl is None or leg.tp is None:
        return None
    if leg.side == "LONG":
        hit_sl = lo <= leg.sl
        hit_tp = hi >= leg.tp
        if hit_sl and hit_tp:
            return (_apply_slip_exit(trigger_px=leg.sl, is_buy_to_close=False, slippage=slippage), "sl")
        if hit_sl:
            return (_apply_slip_exit(trigger_px=leg.sl, is_buy_to_close=False, slippage=slippage), "sl")
        if hit_tp:
            return (_apply_slip_exit(trigger_px=leg.tp, is_buy_to_close=False, slippage=slippage), "tp")
    else:
        hit_sl = hi >= leg.sl
        hit_tp = lo <= leg.tp
        if hit_sl and hit_tp:
            return (_apply_slip_exit(trigger_px=leg.sl, is_buy_to_close=True, slippage=slippage), "sl")
        if hit_sl:
            return (_apply_slip_exit(trigger_px=leg.sl, is_buy_to_close=True, slippage=slippage), "sl")
        if hit_tp:
            return (_apply_slip_exit(trigger_px=leg.tp, is_buy_to_close=True, slippage=slippage), "tp")
    return None


def _close_leg(
    state: _BtState,
    coin: str,
    exit_px: float,
    reason: str,
    exit_t: int,
    fee_rate: float,
) -> None:
    leg = state.legs.pop(coin, None)
    if leg is None:
        return
    exit_fee = _fee_usd(size=leg.sz, px=exit_px, fee_rate=fee_rate)
    if leg.side == "LONG":
        pnl_gross = leg.sz * (exit_px - leg.entry_px)
    else:
        pnl_gross = leg.sz * (leg.entry_px - exit_px)
    pnl_net = pnl_gross - leg.entry_fee - exit_fee
    state.cash += pnl_net
    state.trades.append(
        {
            "coin": coin,
            "side": leg.side,
            "entry_t": leg.entry_t,
            "exit_t": exit_t,
            "entry_px": leg.entry_px,
            "exit_px": exit_px,
            "sz": leg.sz,
            "reason": reason,
            "pnl_net": round(pnl_net, 4),
            "fees": round(leg.entry_fee + exit_fee, 4),
        }
    )


def _signal_at_t_open(
    *,
    coin: str,
    sig_tf: str,
    t_open: int,
    raw: dict[str, list[dict[str, Any]]],
    settings: Settings,
) -> dict[str, Any] | None:
    sig_series = raw[coin].get(sig_tf, [])
    if not sig_series:
        return None
    slice_sig = _candles_until(sig_series, t_open)
    if len(slice_sig) < 50:
        return None
    by_interval: dict[str, IndicatorsSnapshot] = {}
    by_interval[sig_tf] = compute_indicators(coin=coin, interval=sig_tf, candles=slice_sig)
    asof_end = t_open + _TF_MS[sig_tf]
    for interval in ["1m", "1h", "4h", "1d"]:
        if interval == sig_tf:
            continue
        series = raw[coin].get(interval, [])
        if not series:
            continue
        start = _start_for(interval, asof_end)
        window = [c for c in series if start <= _candle_t(c) <= t_open]
        if len(window) < 30:
            continue
        by_interval[interval] = compute_indicators(coin=coin, interval=interval, candles=window)
    ind = by_interval[sig_tf]
    higher = {tf: by_interval[tf] for tf in _HIGHER_TF_FOR.get(sig_tf, []) if tf in by_interval}
    sig = score_signal(ind, higher_tf=higher, perp=None)
    return {
        "interval": sig.interval,
        "long_score": sig.long_score,
        "short_score": sig.short_score,
        "breakdown": sig.breakdown,
    }


def run_backtest(
    settings: Settings,
    *,
    start_ms: int,
    end_ms: int,
    initial_equity: float = 10_000.0,
    taker_fee_bps: float = 4.5,
    slippage: float = 0.0003,
    sample_equity_every: int = 50,
    entry_stride: int = 1,
) -> dict[str, Any]:
    """Walk-forward backtest on aligned signal bars for ETH + SOL."""
    sig_tf = settings.signal_trade_tf if settings.signal_trade_tf in _VALID_TF else "1m"
    tf_ms = _TF_MS[sig_tf]
    fee_rate = taker_fee_bps / 10_000.0

    info = hl_client.make_info(settings)

    raw: dict[str, dict[str, list[dict[str, Any]]]] = {c: {} for c in _TRADE_COINS}
    for coin in _TRADE_COINS:
        for interval in ["1m", "1h", "4h", "1d"]:
            d0 = _history_fetch_start(interval, start_ms)
            chunk = 24 * 60 * 60 * 1000 if interval == "1m" else 7 * 24 * 60 * 60 * 1000
            raw[coin][interval] = _fetch_candles_chunked(info, coin, interval, d0, end_ms, chunk_ms=chunk)

    eth_sig = raw["ETH"].get(sig_tf, [])
    sol_sig = raw["SOL"].get(sig_tf, [])
    ts_eth = {_candle_t(c) for c in eth_sig}
    ts_sol = {_candle_t(c) for c in sol_sig}
    common_ts = sorted(ts_eth & ts_sol)

    state = _BtState(cash=initial_equity)
    step_i = 0
    stride = max(1, int(entry_stride))
    for idx, t_open in enumerate(common_ts):
        bar_close = t_open + tf_ms
        if bar_close < start_ms or bar_close > end_ms:
            continue

        # --- mark-to-market equity sample ---
        unreal = 0.0
        for c, leg in state.legs.items():
            cd = next((x for x in raw[c][sig_tf] if _candle_t(x) == t_open), None)
            if not cd:
                continue
            m = float(cd["c"])
            if leg.side == "LONG":
                unreal += leg.sz * (m - leg.entry_px)
            else:
                unreal += leg.sz * (leg.entry_px - m)
        eq = state.cash + unreal
        if step_i % sample_equity_every == 0:
            state.equity_samples.append({"t": bar_close, "equity": round(eq, 2), "cash": round(state.cash, 2)})
        step_i += 1

        # --- exits first (intrabar on this signal bar) ---
        for coin in list(state.legs.keys()):
            leg = state.legs[coin]
            cd = next((x for x in raw[coin][sig_tf] if _candle_t(x) == t_open), None)
            if not cd:
                continue
            hi = float(cd["h"])
            lo = float(cd["l"])
            ex = _check_exit_intrabar(leg=leg, hi=hi, lo=lo, fee_rate=fee_rate, slippage=slippage)
            if ex:
                exit_px, reason = ex
                _close_leg(state, coin, exit_px, reason, bar_close, fee_rate)

        if idx % stride != 0:
            continue

        # --- build mids for execution parity (close prices as mid proxy) ---
        mids: dict[str, str] = {}
        for c in _TRADE_COINS:
            cd = next((x for x in raw[c][sig_tf] if _candle_t(x) == t_open), None)
            if cd:
                mids[c] = str(cd["c"])

        # --- signals + entries (ETH then SOL, same as execution loop order) ---
        signals_row: dict[str, dict[str, Any]] = {}
        for coin in _TRADE_COINS:
            sig = _signal_at_t_open(coin=coin, sig_tf=sig_tf, t_open=t_open, raw=raw, settings=settings)
            if sig:
                signals_row[coin] = {sig_tf: sig}

        max_total = max(0, int(settings.trade_max_open_positions_total))

        for coin in _TRADE_COINS:
            row = signals_row.get(coin, {}).get(sig_tf) or {}
            lo = int(row.get("long_score") or 0)
            sh = int(row.get("short_score") or 0)
            side = _pick_side(settings, lo, sh)
            if side is None:
                continue

            mid_s = mids.get(coin)
            mid_f = float(mid_s) if mid_s else None
            if mid_f is None or mid_f <= 0:
                continue
            if settings.trade_notional_usd < settings.trade_min_notional_usd:
                continue

            atr = None
            try:
                atr = float(((row.get("breakdown") or {}).get("values") or {}).get("atr_14") or 0) or None
            except Exception:
                atr = None
            if atr is not None and atr <= 0:
                atr = None

            pos_leg = state.legs.get(coin)
            pos_side = pos_leg.side if pos_leg else None

            # Cooldown (wall time ~ bar time)
            last_ts = state.last_entry_ts.get(coin, 0.0)
            if settings.trade_cooldown_sec > 0 and (bar_close / 1000.0 - last_ts) < settings.trade_cooldown_sec:
                continue

            # Same-side pyramid block
            if pos_leg and pos_side == side:
                continue

            # Opposite: flip or skip
            if pos_leg and pos_side != side:
                if not settings.trade_allow_flip:
                    continue
                cd_flip = next((x for x in raw[coin][sig_tf] if _candle_t(x) == t_open), None)
                if not cd_flip:
                    continue
                cls_px = float(cd_flip["c"])
                is_buy_close = pos_leg.side == "SHORT"
                exit_px = cls_px * (1.0 + slippage) if is_buy_close else cls_px * (1.0 - slippage)
                _close_leg(state, coin, exit_px, "flip_close", bar_close, fee_rate)

            if max_total > 0 and _open_count(state) >= max_total and coin not in state.legs:
                continue

            is_buy = side == "LONG"
            entry_px = mid_f * (1.0 + slippage) if is_buy else mid_f * (1.0 - slippage)
            sz = _size_from_mid(settings.trade_notional_usd, entry_px)
            if sz is None or sz <= 0:
                continue
            sl_px, tp_px = _tpsl_levels(entry=entry_px, is_long=is_buy, atr_val=atr, settings=settings)
            if sl_px is None or tp_px is None:
                continue

            entry_fee = _fee_usd(size=sz, px=entry_px, fee_rate=fee_rate)
            state.cash -= entry_fee
            state.legs[coin] = _Leg(
                coin=coin,
                side=side,
                entry_px=entry_px,
                sz=sz,
                sl=sl_px,
                tp=tp_px,
                entry_t=bar_close,
                entry_fee=entry_fee,
            )
            state.last_entry_ts[coin] = bar_close / 1000.0

    # flatten at end
    end_t = end_ms
    for coin in list(state.legs.keys()):
        leg = state.legs[coin]
        series = raw[coin][sig_tf]
        last_c = float(series[-1]["c"]) if series else leg.entry_px
        is_buy = leg.side == "SHORT"
        exit_px = last_c * (1.0 + slippage) if is_buy else last_c * (1.0 - slippage)
        _close_leg(state, coin, exit_px, "eod_flat", end_t, fee_rate)

    wins = sum(1 for t in state.trades if t["pnl_net"] > 0)
    losses = sum(1 for t in state.trades if t["pnl_net"] < 0)
    total_pnl = sum(t["pnl_net"] for t in state.trades)
    return {
        "meta": {
            "start_ms": start_ms,
            "end_ms": end_ms,
            "signal_tf": sig_tf,
            "initial_equity": initial_equity,
            "final_cash": round(state.cash, 2),
            "taker_fee_bps": taker_fee_bps,
            "slippage": slippage,
            "trades_count": len(state.trades),
            "wins": wins,
            "losses": losses,
            "total_pnl_net": round(total_pnl, 2),
            "return_pct": round((state.cash - initial_equity) / initial_equity * 100.0, 4) if initial_equity else None,
            "entry_stride": stride,
        },
        "trades": state.trades,
        "equity_samples": state.equity_samples,
    }


def main() -> None:
    import argparse

    p = argparse.ArgumentParser(description="Walk-forward backtest (ATR TP/SL, fees, slippage)")
    p.add_argument("--days", type=float, default=14.0, help="Length of simulation window ending now")
    p.add_argument("--initial-equity", type=float, default=10_000.0)
    p.add_argument("--taker-fee-bps", type=float, default=4.5, help="Round-trip uses 2x one-side taker")
    p.add_argument("--slippage", type=float, default=0.0003, help="Fractional slippage each side")
    p.add_argument(
        "--entry-stride",
        type=int,
        default=5,
        help="Only evaluate new entries every N bars (exits still every bar). Default 5 for 1m speed.",
    )
    p.add_argument("--out", type=str, default="", help="Write JSON results to this path")
    args = p.parse_args()

    settings = get_settings()
    end_ms = int(time.time() * 1000)
    start_ms = end_ms - int(args.days * 24 * 60 * 60 * 1000)

    t0 = time.time()
    result = run_backtest(
        settings,
        start_ms=start_ms,
        end_ms=end_ms,
        initial_equity=args.initial_equity,
        taker_fee_bps=args.taker_fee_bps,
        slippage=args.slippage,
        entry_stride=args.entry_stride,
    )
    result["meta"]["wall_sec"] = round(time.time() - t0, 2)

    text = json.dumps(result, indent=2)
    print(text)
    if args.out:
        with open(args.out, "w", encoding="utf-8") as f:
            f.write(text)


if __name__ == "__main__":
    main()
