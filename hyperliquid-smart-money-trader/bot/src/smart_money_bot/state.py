from __future__ import annotations

import logging
import threading
import time
from dataclasses import dataclass, field
from typing import TYPE_CHECKING, Any

logger = logging.getLogger(__name__)

if TYPE_CHECKING:
    from smart_money_bot.config import Settings


@dataclass
class BotRuntimeState:
    lock: threading.Lock = field(default_factory=threading.Lock)
    last_poll_unix: float = 0.0
    last_error: str | None = None
    mids: dict[str, str] = field(default_factory=dict)
    meta_ctx: Any | None = None
    smart_money: dict[str, Any] | None = None
    patterns: dict[str, Any] | None = None
    correlations: dict[str, Any] | None = None
    indicators: dict[str, Any] = field(default_factory=dict)  # coin -> interval -> snapshot
    signals: dict[str, Any] = field(default_factory=dict)  # coin -> interval -> scores
    candles: dict[str, Any] = field(default_factory=dict)  # coin -> interval -> {end_time_ms, closes}
    recent_trades: list[dict[str, Any]] = field(default_factory=list)
    execution_log: list[dict[str, Any]] = field(default_factory=list)
    execution_last_ts: dict[str, float] = field(default_factory=dict)
    execution_block_until: dict[str, float] = field(default_factory=dict)
    execution_status: dict[str, Any] = field(default_factory=dict)
    execution_kill_log_ts: float = 0.0
    progress: dict[str, Any] = field(default_factory=lambda: {"phase": "idle", "pct": 0, "eta_sec": None})

    def snapshot(self) -> dict[str, Any]:
        with self.lock:
            return {
                "last_poll_unix": self.last_poll_unix,
                "last_error": self.last_error,
                "mids_sample": dict(list(self.mids.items())[:8]),
                "mids_count": len(self.mids),
                "has_meta_ctx": self.meta_ctx is not None,
                "has_smart_money": self.smart_money is not None,
                "has_patterns": self.patterns is not None,
                "has_correlations": self.correlations is not None,
                "indicators_coins": sorted(self.indicators.keys()),
                "signals_coins": sorted(self.signals.keys()),
                "candles_coins": sorted(self.candles.keys()),
                "recent_trades_count": len(self.recent_trades),
                "execution_log_tail": list(self.execution_log[-5:]),
                "execution_status": dict(self.execution_status),
                "progress": self.progress,
            }


runtime_state = BotRuntimeState()
stop_event = threading.Event()


def request_stop() -> None:
    stop_event.set()


def background_poll_loop(settings: "Settings", interval_sec: float = 15.0) -> None:
    """Lightweight loop powering the dashboard.

    It pulls public market data, computes indicators/signals, and runs an optional
    execution tick (dry-run by default; live orders require explicit env flags).
    """
    from smart_money_bot import hl_client
    from smart_money_bot.indicators import IndicatorsSnapshot, compute_indicators
    from smart_money_bot.signals import parse_perp_ctx, score_signal
    from smart_money_bot.correlations import build_correlations
    from smart_money_bot.execution import run_execution_tick
    from smart_money_bot.patterns import aggregate_smart_money_patterns
    from smart_money_bot.smart_money import SmartMoneyConstraints, get_smart_money_profile

    # In reload scenarios, the module can stay resident; ensure we start "running".
    stop_event.clear()
    info = hl_client.make_info(settings)
    while not stop_event.is_set():
        started = time.time()
        try:
            with runtime_state.lock:
                runtime_state.progress = {"phase": "fetching", "pct": 10, "eta_sec": None}

            mids = info.all_mids()
            meta_ctx = info.meta_and_asset_ctxs()
            listed = hl_client.perp_coin_names_from_meta(meta_ctx)
            coins = [c for c in settings.trade_coins_list if c in listed]
            skipped = [c for c in settings.trade_coins_list if c not in listed]
            if skipped:
                logger.warning("trade coins not on %s perp universe (skipped): %s", settings.hl_network, skipped)

            with runtime_state.lock:
                runtime_state.progress = {"phase": "indicators", "pct": 55, "eta_sec": None}

            def start_for(interval: str, end_ms: int) -> int:
                # Rough windows to cover enough candles for long MAs on each timeframe.
                if interval == "1m":
                    return end_ms - int(6 * 60 * 60 * 1000)  # 6h
                if interval == "1h":
                    return end_ms - int(21 * 24 * 60 * 60 * 1000)  # 21d
                if interval == "4h":
                    return end_ms - int(120 * 24 * 60 * 60 * 1000)  # 120d
                if interval == "1d":
                    return end_ms - int(420 * 24 * 60 * 60 * 1000)  # 420d
                return end_ms - int(6 * 60 * 60 * 1000)

            # Candles + indicators for UI timeframes.
            end_ms = int(time.time() * 1000)
            intervals = ["1m", "1h", "4h", "1d"]
            higher_tf_for: dict[str, list[str]] = {
                "1m": ["1h", "4h", "1d"],
                "1h": ["4h", "1d"],
                "4h": ["1d"],
                "1d": [],
            }
            indicators: dict[str, Any] = {}
            signals: dict[str, Any] = {}
            candles: dict[str, Any] = {}
            for coin in coins:
                indicators[coin] = {}
                signals[coin] = {}
                candles[coin] = {}
                by_interval: dict[str, IndicatorsSnapshot] = {}
                for interval in intervals:
                    cds = hl_client.fetch_candles(info, coin, interval, start_for(interval, end_ms), end_ms)
                    ind = compute_indicators(coin=coin, interval=interval, candles=cds)
                    by_interval[interval] = ind
                    indicators[coin][interval] = {"end_time_ms": ind.end_time_ms, "values": ind.values}
                    closes = []
                    for cd in cds[-240:]:
                        try:
                            closes.append(float(cd.get("c")))
                        except Exception:
                            pass
                    candles[coin][interval] = {"end_time_ms": ind.end_time_ms, "closes": closes}

                perp_ctx = parse_perp_ctx(meta_ctx, coin)
                for interval in intervals:
                    ind = by_interval[interval]
                    higher = {tf: by_interval[tf] for tf in higher_tf_for.get(interval, [])}
                    sig = score_signal(ind, higher_tf=higher, perp=perp_ctx)
                    signals[coin][interval] = {
                        "interval": sig.interval,
                        "long_score": sig.long_score,
                        "short_score": sig.short_score,
                        "breakdown": sig.breakdown,
                    }

            correlations = build_correlations(candles=candles, signals=signals, meta_ctx=meta_ctx)
            run_execution_tick(settings, signals, mids)

            # Commit everything we have so far so the dashboard isn't blocked by later calls.
            with runtime_state.lock:
                runtime_state.mids = mids
                runtime_state.meta_ctx = meta_ctx
                runtime_state.indicators = indicators
                runtime_state.signals = signals
                runtime_state.candles = candles
                runtime_state.correlations = correlations
                runtime_state.last_poll_unix = time.time()
                runtime_state.last_error = None

            # Smart money scan can be slow on mainnet; run it after market/indicators are committed.
            with runtime_state.lock:
                runtime_state.progress = {"phase": "smart-money", "pct": 70, "eta_sec": None}
            try:
                smart_money = get_smart_money_profile(settings=settings, constraints=SmartMoneyConstraints())
                patterns = aggregate_smart_money_patterns(
                    smart_money,
                    eth_signal=signals.get("ETH", {}).get("1h"),
                    sol_signal=signals.get("SOL", {}).get("1h"),
                )
                with runtime_state.lock:
                    runtime_state.smart_money = smart_money
                    runtime_state.patterns = patterns
            except Exception:
                pass

            with runtime_state.lock:
                runtime_state.progress = {"phase": "recent-trades", "pct": 80, "eta_sec": None}

            # Recent trades: if a user address exists, show its fills; otherwise synthesize from signals
            recent_trades: list[dict[str, Any]]
            if settings.hl_account_address and settings.hl_account_address.startswith("0x"):
                try:
                    fills = info.user_fills(settings.hl_account_address)
                    recent_trades = [
                        {
                            "coin": f.get("coin"),
                            "side": f.get("side") or f.get("dir"),
                            "px": f.get("px"),
                            "sz": f.get("sz"),
                            "closedPnl": f.get("closedPnl"),
                            "time": f.get("time"),
                        }
                        for f in (fills or [])[:25]
                    ]
                except Exception:
                    recent_trades = []
            else:
                now = int(time.time())
                recent_trades = []
                for i, coin in enumerate(coins):
                    s = signals.get(coin, {}).get("1m", {})
                    bias = "LONG" if (s.get("long_score", 0) >= s.get("short_score", 0)) else "SHORT"
                    pnl = round(((s.get("long_score", 0) - s.get("short_score", 0)) / 100.0) * 12.0, 2)
                    recent_trades.append(
                        {
                            "coin": coin,
                            "side": bias,
                            "entry": mids.get(coin, "—"),
                            "exit": mids.get(coin, "—"),
                            "pnl": pnl,
                            "time": now - i * 180,
                        }
                    )

            with runtime_state.lock:
                runtime_state.recent_trades = recent_trades
                elapsed = time.time() - started
                runtime_state.progress = {"phase": "idle", "pct": 100, "eta_sec": int(max(0.0, interval_sec - elapsed))}
        except Exception as exc:  # noqa: BLE001 — top-level poller
            with runtime_state.lock:
                runtime_state.last_error = str(exc)
                runtime_state.last_poll_unix = time.time()
                runtime_state.progress = {"phase": "error", "pct": 0, "eta_sec": None}
        stop_event.wait(interval_sec)
