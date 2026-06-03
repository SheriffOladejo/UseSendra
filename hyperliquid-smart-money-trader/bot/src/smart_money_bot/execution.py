from __future__ import annotations

import logging
import time
from typing import Any

from smart_money_bot.config import Settings
from smart_money_bot.state import runtime_state

logger = logging.getLogger(__name__)

_VALID_TF = frozenset({"1m", "1h", "4h", "1d"})
_MAX_LOG = 80


def _append_execution(entry: dict[str, Any]) -> None:
    with runtime_state.lock:
        runtime_state.execution_log.append(entry)
        if len(runtime_state.execution_log) > _MAX_LOG:
            runtime_state.execution_log[:] = runtime_state.execution_log[-_MAX_LOG:]


def _execution_status(settings: Settings, *, has_secret: bool, extra: dict[str, Any] | None = None) -> dict[str, Any]:
    armed_live = (
        settings.trading_enabled
        and not settings.trading_dry_run
        and not settings.trading_kill_switch
        and has_secret
    )
    out: dict[str, Any] = {
        "trading_enabled": settings.trading_enabled,
        "trading_dry_run": settings.trading_dry_run,
        "trading_kill_switch": settings.trading_kill_switch,
        "has_secret_key": has_secret,
        "armed_live": armed_live,
        "signal_trade_tf": settings.signal_trade_tf,
        "long_threshold": settings.signal_long_threshold,
        "short_threshold": settings.signal_short_threshold,
        "min_edge": settings.signal_min_edge,
        "notional_usd": settings.trade_notional_usd,
        "cooldown_sec": settings.trade_cooldown_sec,
        "max_open_positions_total": settings.trade_max_open_positions_total,
        "allow_flip": settings.trade_allow_flip,
        "error_backoff_sec": settings.trade_error_backoff_sec,
        "attach_tpsl": settings.trade_attach_tpsl,
        "sl_atr_mult": settings.trade_sl_atr_mult,
        "tp_atr_mult": settings.trade_tp_atr_mult,
        "trade_coins": list(settings.trade_coins_list),
    }
    if extra:
        out.update(extra)
    return out


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


def _as_float(x: Any) -> float | None:
    try:
        return float(x)
    except Exception:
        return None


def _positions_by_coin(user_state: Any) -> dict[str, float]:
    """Best-effort extract open perp positions from Hyperliquid `user_state`."""
    out: dict[str, float] = {}
    if not isinstance(user_state, dict):
        return out
    poss = user_state.get("assetPositions") or []
    if not isinstance(poss, list):
        return out
    for ap in poss:
        if not isinstance(ap, dict):
            continue
        pos = ap.get("position")
        if not isinstance(pos, dict):
            continue
        coin = str(pos.get("coin") or "").upper().strip()
        szi = _as_float(pos.get("szi"))
        if not coin or szi is None:
            continue
        if szi == 0.0:
            continue
        out[coin] = float(szi)
    return out


def run_execution_tick(settings: Settings, signals: dict[str, Any], mids: dict[str, str]) -> None:
    """Evaluate signal thresholds; dry-run log by default, optional live `market_open`."""
    tf = settings.signal_trade_tf if settings.signal_trade_tf in _VALID_TF else "1m"
    now = time.time()
    key_ok = bool(
        settings.hl_secret_key
        and settings.hl_secret_key.startswith("0x")
        and len(settings.hl_secret_key.strip()) >= 66
    )

    addr = (settings.hl_account_address or "").strip()
    addr_ok = bool(addr and addr.startswith("0x"))

    with runtime_state.lock:
        runtime_state.execution_status = _execution_status(
            settings,
            has_secret=key_ok,
            extra={"evaluated_at": now, "has_account_address": addr_ok},
        )

    if not settings.trading_enabled:
        return

    if settings.trading_kill_switch:
        with runtime_state.lock:
            last_kill = runtime_state.execution_kill_log_ts
            if now - last_kill >= 900.0:
                runtime_state.execution_kill_log_ts = now
                do_kill_log = True
            else:
                do_kill_log = False
        if do_kill_log:
            _append_execution(
                {
                    "ts": now,
                    "coin": "—",
                    "side": "—",
                    "mode": "blocked",
                    "reason": "kill_switch",
                    "long_score": None,
                    "short_score": None,
                    "sz": None,
                    "mid": None,
                    "detail": "TRADING_KILL_SWITCH is on",
                }
            )
        return

    # Snapshot open positions once per tick (best-effort; failure -> treat as unknown).
    positions: dict[str, float] = {}
    if addr_ok:
        try:
            from smart_money_bot import hl_client

            info = hl_client.make_info(settings)
            st = hl_client.fetch_user_state(info, addr)
            positions = _positions_by_coin(st)
        except Exception:
            positions = {}

    open_positions_total = sum(1 for v in positions.values() if v != 0.0)
    max_total = max(0, int(settings.trade_max_open_positions_total))

    for coin in settings.trade_coins_list:
        # Per-coin temporary block after an error (prevents hammering the API).
        with runtime_state.lock:
            block_until = float(runtime_state.execution_block_until.get(coin, 0.0) or 0.0)
        if block_until and now < block_until:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": "—",
                    "mode": "blocked",
                    "reason": "error_backoff",
                    "long_score": None,
                    "short_score": None,
                    "sz": None,
                    "mid": mids.get(coin),
                    "detail": f"blocked until {int(block_until)} (error backoff)",
                }
            )
            continue

        row = signals.get(coin, {}).get(tf) or {}
        lo = int(row.get("long_score") or 0)
        sh = int(row.get("short_score") or 0)
        side = _pick_side(settings, lo, sh)
        if side is None:
            continue

        # ATR for risk sizing (pulled from signal breakdown values).
        atr = None
        try:
            atr = _as_float(((row.get("breakdown") or {}).get("values") or {}).get("atr_14"))
        except Exception:
            atr = None

        # Guard rails: position-awareness and max-open-positions cap.
        pos = float(positions.get(coin, 0.0) or 0.0)
        if pos != 0.0:
            if (pos > 0 and side == "LONG") or (pos < 0 and side == "SHORT"):
                _append_execution(
                    {
                        "ts": now,
                        "coin": coin,
                        "side": side,
                        "mode": "blocked",
                        "reason": "already_in_position",
                        "long_score": lo,
                        "short_score": sh,
                        "sz": None,
                        "mid": mids.get(coin),
                        "detail": f"already in {coin} position (szi={pos})",
                    }
                )
                continue
            # Opposite direction signal would require a flip.
            if not settings.trade_allow_flip:
                _append_execution(
                    {
                        "ts": now,
                        "coin": coin,
                        "side": side,
                        "mode": "blocked",
                        "reason": "flip_blocked",
                        "long_score": lo,
                        "short_score": sh,
                        "sz": None,
                        "mid": mids.get(coin),
                        "detail": f"flip not allowed (have szi={pos})",
                    }
                )
                continue

        if max_total > 0 and open_positions_total >= max_total and pos == 0.0:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "blocked",
                    "reason": "max_open_positions",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": None,
                    "mid": mids.get(coin),
                    "detail": f"open_positions_total={open_positions_total} >= max_total={max_total}",
                }
            )
            continue

        with runtime_state.lock:
            last_ts = runtime_state.execution_last_ts.get(coin, 0.0)
        if settings.trade_cooldown_sec > 0 and (now - last_ts) < settings.trade_cooldown_sec:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "skipped",
                    "reason": "cooldown",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": None,
                    "mid": mids.get(coin),
                    "detail": f"wait {settings.trade_cooldown_sec}s between intents",
                }
            )
            continue

        mid_s = mids.get(coin)
        mid_f = float(mid_s) if mid_s not in (None, "") else None
        if mid_f is None or mid_f <= 0:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "skipped",
                    "reason": "no_mid",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": None,
                    "mid": mid_s,
                    "detail": "missing mid",
                }
            )
            continue

        if settings.trade_notional_usd < settings.trade_min_notional_usd:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "skipped",
                    "reason": "notional_too_small",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": None,
                    "mid": mid_s,
                    "detail": f"trade_notional_usd < trade_min_notional_usd ({settings.trade_notional_usd})",
                }
            )
            continue

        sz = _size_from_mid(settings.trade_notional_usd, mid_f)
        if sz is None or sz <= 0:
            continue

        is_buy = side == "LONG"

        def tpsl_levels(*, entry: float, is_long: bool, atr_val: float | None) -> tuple[float | None, float | None]:
            if not settings.trade_attach_tpsl:
                return (None, None)
            if atr_val is None or atr_val <= 0:
                return (None, None)
            sl_d = float(settings.trade_sl_atr_mult) * atr_val
            tp_d = float(settings.trade_tp_atr_mult) * atr_val
            if is_long:
                return (max(0.0, entry - sl_d), entry + tp_d)
            return (entry + sl_d, max(0.0, entry - tp_d))

        sl_px, tp_px = tpsl_levels(entry=mid_f, is_long=is_buy, atr_val=atr)

        if settings.trading_dry_run:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "dry_run",
                    "reason": "signal",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": (
                        f"would market_open {'buy' if is_buy else 'sell'} {sz} {coin} @~{mid_s} (IOC)"
                        + (
                            f" ; TP/SL (ATR={atr:.4g}): tp={tp_px:.4g} sl={sl_px:.4g}"
                            if (tp_px is not None and sl_px is not None and atr is not None)
                            else ""
                        )
                    ),
                }
            )
            with runtime_state.lock:
                runtime_state.execution_last_ts[coin] = now
            continue

        if not addr_ok:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "skipped",
                    "reason": "no_account_address",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": "live mode needs HL_ACCOUNT_ADDRESS for position checks",
                }
            )
            continue

        if not key_ok:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "skipped",
                    "reason": "no_secret",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": "live mode needs HL_SECRET_KEY",
                }
            )
            continue

        from smart_money_bot import hl_client

        ex = hl_client.make_exchange(settings)
        if ex is None:
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "error",
                    "reason": "exchange_init",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": "could not build Exchange wallet",
                }
            )
            continue

        try:
            # If flipping, close the existing position first.
            if pos != 0.0 and settings.trade_allow_flip:
                _append_execution(
                    {
                        "ts": now,
                        "coin": coin,
                        "side": side,
                        "mode": "live",
                        "reason": "flip_close",
                        "long_score": lo,
                        "short_score": sh,
                        "sz": None,
                        "mid": mid_s,
                        "detail": f"closing existing position before flip (szi={pos})",
                    }
                )
                ex.market_close(coin, slippage=settings.trade_max_slippage)

            resp = ex.market_open(
                coin,
                is_buy,
                sz,
                px=None,
                slippage=settings.trade_max_slippage,
            )
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "live",
                    "reason": "submitted",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": str(resp)[:500],
                }
            )
            logger.warning("live order submitted %s %s sz=%s resp_snip=%s", coin, side, sz, str(resp)[:200])

            # Attach reduce-only TP/SL trigger orders (best-effort).
            if settings.trade_attach_tpsl and tp_px is not None and sl_px is not None:
                try:
                    close_is_buy = not is_buy
                    orders = [
                        {
                            "coin": coin,
                            "is_buy": close_is_buy,
                            "sz": sz,
                            "limit_px": float(sl_px),
                            "order_type": {"trigger": {"triggerPx": float(sl_px), "isMarket": True, "tpsl": "sl"}},
                            "reduce_only": True,
                        },
                        {
                            "coin": coin,
                            "is_buy": close_is_buy,
                            "sz": sz,
                            "limit_px": float(tp_px),
                            "order_type": {"trigger": {"triggerPx": float(tp_px), "isMarket": True, "tpsl": "tp"}},
                            "reduce_only": True,
                        },
                    ]
                    tpsl_resp = ex.bulk_orders(orders, grouping="positionTpsl")
                    _append_execution(
                        {
                            "ts": now,
                            "coin": coin,
                            "side": side,
                            "mode": "live",
                            "reason": "tpsl_attached",
                            "long_score": lo,
                            "short_score": sh,
                            "sz": sz,
                            "mid": mid_s,
                            "detail": f"tp={tp_px:.4g} sl={sl_px:.4g} resp={str(tpsl_resp)[:220]}",
                        }
                    )
                except Exception as exc:  # noqa: BLE001
                    _append_execution(
                        {
                            "ts": now,
                            "coin": coin,
                            "side": side,
                            "mode": "error",
                            "reason": "tpsl_failed",
                            "long_score": lo,
                            "short_score": sh,
                            "sz": sz,
                            "mid": mid_s,
                            "detail": str(exc)[:240],
                        }
                    )
        except Exception as exc:  # noqa: BLE001
            _append_execution(
                {
                    "ts": now,
                    "coin": coin,
                    "side": side,
                    "mode": "error",
                    "reason": "order_failed",
                    "long_score": lo,
                    "short_score": sh,
                    "sz": sz,
                    "mid": mid_s,
                    "detail": str(exc)[:500],
                }
            )
            logger.exception("live order failed for %s", coin)
            if settings.trade_error_backoff_sec > 0:
                with runtime_state.lock:
                    runtime_state.execution_block_until[coin] = now + float(settings.trade_error_backoff_sec)

        with runtime_state.lock:
            runtime_state.execution_last_ts[coin] = now

    with runtime_state.lock:
        runtime_state.execution_status = _execution_status(
            settings,
            has_secret=key_ok,
            extra={"evaluated_at": time.time()},
        )
