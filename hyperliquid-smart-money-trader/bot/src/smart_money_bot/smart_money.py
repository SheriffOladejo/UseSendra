from __future__ import annotations

import json
import os
import time
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Any

import httpx

if TYPE_CHECKING:
    from smart_money_bot.config import Settings


def _default_cache_path() -> Path:
    env = os.getenv("SMART_MONEY_CACHE_PATH", "").strip()
    if env:
        return Path(env).expanduser()
    return Path(__file__).resolve().parents[3] / "smart_money.json"


@dataclass(frozen=True)
class SmartMoneyConstraints:
    pnl_min: float = 10_000.0
    pnl_max: float = 500_000.0
    win_rate_min: float = 0.52
    min_trades: int = 50
    max_traders: int = 100
    cache_ttl_sec: float = 60 * 30  # 30 minutes


LEADERBOARD_URL_MAINNET = "https://stats-data.hyperliquid.xyz/Mainnet/leaderboard"


def load_cached_profile(
    *,
    cache_path: Path | None = None,
    ttl_sec: float,
) -> dict[str, Any] | None:
    path = cache_path or _default_cache_path()
    try:
        st = path.stat()
    except FileNotFoundError:
        return None
    age = time.time() - st.st_mtime
    if age > ttl_sec:
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None


def save_profile(profile: dict[str, Any], *, cache_path: Path | None = None) -> None:
    path = cache_path or _default_cache_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = dict(profile)
    payload.setdefault("cached_at_unix", time.time())
    path.write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")


def _pseudo_address(i: int) -> str:
    # Stable-ish synthetic address for UI while we wire true leaderboard ingestion.
    return f"0x{(i + 1):040x}"


def _clamp(x: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, x))


def _round2(x: float) -> float:
    return float(f"{x:.2f}")


def build_profile_fallback(constraints: SmartMoneyConstraints) -> dict[str, Any]:
    traders: list[dict[str, Any]] = []
    for i in range(constraints.max_traders):
        # Deterministic pseudo-metrics to avoid random flicker in the dashboard.
        pnl = constraints.pnl_min + (constraints.pnl_max - constraints.pnl_min) * ((i * 37) % 100) / 100.0
        win_rate = _clamp(constraints.win_rate_min + (((i * 13) % 30) / 100.0), 0.0, 0.98)
        trades = constraints.min_trades + ((i * 11) % 350)
        avg_lev = 2.0 + ((i * 7) % 11)  # 2..12
        margin_type = "isolated" if (i % 3) else "cross"
        avg_dur_min = 5 + ((i * 17) % 240)
        assets = ["ETH", "SOL", "BTC", "HYPE", "ARB"]
        most_traded = [assets[(i + j) % len(assets)] for j in range(2)]
        long_wr = _clamp(win_rate + (0.02 if i % 2 == 0 else -0.01), 0.0, 0.98)
        short_wr = _clamp(win_rate - (0.02 if i % 2 == 0 else -0.01), 0.0, 0.98)
        traders.append(
            {
                "rank": i + 1,
                "address": _pseudo_address(i),
                "pnl": _round2(pnl),
                "win_rate": _round2(win_rate),
                "trade_count": int(trades),
                "avg_leverage": _round2(avg_lev),
                "margin_type": margin_type,
                "avg_trade_duration_min": int(avg_dur_min),
                "most_traded_assets": most_traded,
                "win_rate_long": _round2(long_wr),
                "win_rate_short": _round2(short_wr),
            }
        )

    avg_leverage = sum(t["avg_leverage"] for t in traders) / max(1, len(traders))
    isolated_ratio = sum(1 for t in traders if t["margin_type"] == "isolated") / max(1, len(traders))
    avg_trade_duration_min = int(sum(t["avg_trade_duration_min"] for t in traders) / max(1, len(traders)))
    avg_win_rate = sum(t["win_rate"] for t in traders) / max(1, len(traders))
    total_pnl = sum(t["pnl"] for t in traders)

    return {
        "source": "fallback",
        "constraints": {
            "pnl_min": constraints.pnl_min,
            "pnl_max": constraints.pnl_max,
            "win_rate_min": constraints.win_rate_min,
            "min_trades": constraints.min_trades,
            "max_traders": constraints.max_traders,
        },
        "summary": {
            "traders_count": len(traders),
            "avg_leverage": _round2(avg_leverage),
            "isolated_ratio": _round2(isolated_ratio),
            "avg_trade_duration_min": avg_trade_duration_min,
            "avg_win_rate": _round2(avg_win_rate),
            "total_pnl": _round2(total_pnl),
        },
        "traders": traders,
    }


def _as_float(x: Any) -> float | None:
    try:
        return float(x)
    except Exception:
        return None


def _extract_window_perf(row: dict[str, Any], window: str) -> dict[str, Any] | None:
    for item in row.get("windowPerformances", []) or []:
        if isinstance(item, list) and len(item) == 2 and item[0] == window and isinstance(item[1], dict):
            return item[1]
    return None


def fetch_leaderboard_mainnet(*, timeout_sec: float = 20.0) -> list[dict[str, Any]]:
    with httpx.Client(timeout=timeout_sec, follow_redirects=True) as client:
        res = client.get(LEADERBOARD_URL_MAINNET)
        res.raise_for_status()
        payload = res.json()
    rows = payload.get("leaderboardRows", [])
    return rows if isinstance(rows, list) else []


def fetch_user_fills(
    *,
    settings: "Settings",
    address: str,
    client: httpx.Client | None = None,
    timeout_sec: float = 6.0,
) -> list[dict[str, Any]]:
    if not address or not address.startswith("0x"):
        return []
    if client is None:
        with httpx.Client(timeout=timeout_sec) as c:
            res = c.post(
                f"{settings.hyperliquid_api_url}/info",
                json={"type": "userFills", "user": address, "aggregateByTime": True},
            )
            res.raise_for_status()
            data = res.json()
    else:
        res = client.post(
            f"{settings.hyperliquid_api_url}/info",
            json={"type": "userFills", "user": address, "aggregateByTime": True},
        )
        res.raise_for_status()
        data = res.json()
    return data if isinstance(data, list) else []


def compute_fill_stats(fills: list[dict[str, Any]]) -> dict[str, Any]:
    # We derive win-rate + trade duration from realized PnL events.
    # NOTE: Hyperliquid returns at most 2000 recent fills, so these are recent-history estimates.
    wins = 0
    losses = 0
    long_wins = 0
    long_losses = 0
    short_wins = 0
    short_losses = 0
    closes = 0
    coin_counts: dict[str, int] = {}
    open_time_by_coin: dict[str, int] = {}
    durations_min: list[int] = []

    # Hyperliquid returns most recent fills first; sort so open->close duration works.
    fills_sorted = sorted(
        fills,
        key=lambda f: int(_as_float((f or {}).get("time")) or 0),
    )

    for f in fills_sorted:
        coin = str(f.get("coin") or "").upper()
        if coin:
            coin_counts[coin] = coin_counts.get(coin, 0) + 1

        pnl = _as_float(f.get("closedPnl"))
        dir_s = str(f.get("dir") or f.get("side") or "")
        dir_u = dir_s.upper()
        t_ms = int(_as_float(f.get("time")) or 0)

        # Track "open" moment per coin to estimate holding time until next realized close.
        if coin and (("OPEN" in dir_u) or ("ADD" in dir_u)):
            open_time_by_coin.setdefault(coin, t_ms)

        if pnl is None or pnl == 0.0:
            continue

        # A realized PnL indicates a close/reduce event.
        closes += 1
        is_win = pnl > 0
        is_long_close = ("LONG" in dir_u) and (("CLOSE" in dir_u) or ("REDUCE" in dir_u))
        is_short_close = ("SHORT" in dir_u) and (("CLOSE" in dir_u) or ("REDUCE" in dir_u))

        if is_win:
            wins += 1
            if is_long_close:
                long_wins += 1
            elif is_short_close:
                short_wins += 1
        else:
            losses += 1
            if is_long_close:
                long_losses += 1
            elif is_short_close:
                short_losses += 1

        if coin and coin in open_time_by_coin and t_ms:
            dt = max(0, t_ms - open_time_by_coin[coin])
            durations_min.append(int(dt // 60000))
            open_time_by_coin.pop(coin, None)

    denom = wins + losses
    win_rate = (wins / denom) if denom else 0.0
    long_denom = long_wins + long_losses
    short_denom = short_wins + short_losses
    win_rate_long = (long_wins / long_denom) if long_denom else 0.0
    win_rate_short = (short_wins / short_denom) if short_denom else 0.0

    most_traded = [c for c, _ in sorted(coin_counts.items(), key=lambda kv: kv[1], reverse=True)[:3]]
    avg_duration_min = int(sum(durations_min) / len(durations_min)) if durations_min else None
    return {
        # Keep both: raw fills and "closed trades" count for filtering.
        "fills_count": len(fills),
        "trade_count": closes,
        "wins": wins,
        "losses": losses,
        "win_rate": win_rate,
        "win_rate_long": win_rate_long,
        "win_rate_short": win_rate_short,
        "avg_trade_duration_min": avg_duration_min,
        "most_traded_assets": most_traded[:2],
    }


def fetch_clearinghouse_state(
    *,
    settings: "Settings",
    address: str,
    client: httpx.Client,
) -> dict[str, Any] | None:
    if not address or not address.startswith("0x"):
        return None
    res = client.post(
        f"{settings.hyperliquid_api_url}/info",
        json={"type": "clearinghouseState", "user": address},
    )
    res.raise_for_status()
    data = res.json()
    return data if isinstance(data, dict) else None


def compute_position_stats(state: dict[str, Any] | None) -> dict[str, Any]:
    if not state:
        return {"avg_leverage": None, "margin_type": None}
    poss = state.get("assetPositions") or []
    lev_vals: list[float] = []
    type_counts: dict[str, int] = {}
    for ap in poss:
        pos = (ap or {}).get("position") if isinstance(ap, dict) else None
        if not isinstance(pos, dict):
            continue
        try:
            if float(pos.get("szi") or 0) == 0.0:
                continue
        except Exception:
            continue
        lev = pos.get("leverage") if isinstance(pos.get("leverage"), dict) else {}
        ltype = str((lev or {}).get("type") or "")
        lval = _as_float((lev or {}).get("value"))
        if ltype:
            type_counts[ltype] = type_counts.get(ltype, 0) + 1
        if lval is not None:
            lev_vals.append(lval)
    avg_lev = (sum(lev_vals) / len(lev_vals)) if lev_vals else None
    pref = None
    if type_counts:
        pref = max(type_counts.items(), key=lambda kv: kv[1])[0]
    return {"avg_leverage": _round2(avg_lev) if avg_lev is not None else None, "margin_type": pref}


def build_profile_from_mainnet_leaderboard(
    *,
    settings: "Settings",
    constraints: SmartMoneyConstraints,
    time_budget_sec: float = 20.0,
    previous: dict[str, Any] | None = None,
) -> dict[str, Any]:
    rows = fetch_leaderboard_mainnet()
    candidates: list[dict[str, Any]] = []
    for r in rows:
        addr = str(r.get("ethAddress") or "")
        perf = _extract_window_perf(r, "allTime") or {}
        pnl = _as_float(perf.get("pnl"))
        if pnl is None:
            continue
        if not (constraints.pnl_min <= pnl <= constraints.pnl_max):
            continue
        candidates.append({"address": addr, "pnl": pnl, "row": r, "perf": perf})

    # Sort by all-time PnL descending
    candidates.sort(key=lambda x: x["pnl"], reverse=True)

    # Resume from previous scan if present.
    prev_traders = (previous or {}).get("traders") if isinstance((previous or {}).get("traders"), list) else []
    traders: list[dict[str, Any]] = [t for t in prev_traders if isinstance(t, dict)]
    selected = {str(t.get("address") or "").lower() for t in traders}
    resume_idx = int(((previous or {}).get("summary") or {}).get("resume_idx") or 0) if isinstance((previous or {}).get("summary"), dict) else 0
    total_considered = int(((previous or {}).get("summary") or {}).get("considered") or 0) if isinstance((previous or {}).get("summary"), dict) else 0

    # Limit work to avoid excessive API load; cache will keep it fast.
    max_to_consider = max(200, constraints.max_traders * 4)

    limits = httpx.Limits(max_connections=20, max_keepalive_connections=10)
    timeout = httpx.Timeout(4.0, connect=2.0)
    t0 = time.time()
    with httpx.Client(timeout=timeout, limits=limits) as client:
        for i, cand in enumerate(candidates[:max_to_consider]):
            if (time.time() - t0) > time_budget_sec:
                break
            total_considered += 1
            addr = cand["address"]
            if not addr.startswith("0x"):
                continue
            if i < resume_idx:
                continue
            if addr.lower() in selected:
                continue

            try:
                fills = fetch_user_fills(settings=settings, address=addr, client=client)
            except Exception:
                continue
            stats = compute_fill_stats(fills)
            if stats["trade_count"] < constraints.min_trades:
                continue
            if stats["win_rate"] < constraints.win_rate_min:
                continue

            pos_stats: dict[str, Any]
            try:
                st = fetch_clearinghouse_state(settings=settings, address=addr, client=client)
                pos_stats = compute_position_stats(st)
            except Exception:
                pos_stats = {"avg_leverage": None, "margin_type": None}

            perf = cand["perf"]
            traders.append(
                {
                    "rank": len(traders) + 1,
                    "address": addr,
                    "pnl": _round2(cand["pnl"]),
                    "win_rate": _round2(stats["win_rate"]),
                    "trade_count": int(stats["trade_count"]),
                    "avg_leverage": pos_stats.get("avg_leverage"),
                    "margin_type": pos_stats.get("margin_type"),
                    "avg_trade_duration_min": stats.get("avg_trade_duration_min"),
                    "most_traded_assets": stats["most_traded_assets"],
                    "win_rate_long": _round2(stats["win_rate_long"]),
                    "win_rate_short": _round2(stats["win_rate_short"]),
                    "account_value": _as_float(cand["row"].get("accountValue")),
                    "roi_all_time": _as_float(perf.get("roi")),
                    "volume_all_time": _as_float(perf.get("vlm")),
                    "display_name": cand["row"].get("displayName"),
                }
            )
            selected.add(addr.lower())
            if len(traders) >= constraints.max_traders:
                break
            resume_idx = i + 1

    avg_win_rate = sum(t["win_rate"] for t in traders) / max(1, len(traders))
    total_pnl = sum(t["pnl"] for t in traders)
    dur_vals = [t.get("avg_trade_duration_min") for t in traders if isinstance(t.get("avg_trade_duration_min"), int)]
    avg_dur = (sum(dur_vals) / len(dur_vals)) if dur_vals else None
    lev_vals = [t.get("avg_leverage") for t in traders if isinstance(t.get("avg_leverage"), (int, float))]
    avg_lev = (sum(float(x) for x in lev_vals) / len(lev_vals)) if lev_vals else None
    return {
        "source": "mainnet-leaderboard+fills",
        "network": "mainnet",
        "constraints": {
            "pnl_min": constraints.pnl_min,
            "pnl_max": constraints.pnl_max,
            "win_rate_min": constraints.win_rate_min,
            "min_trades": constraints.min_trades,
            "max_traders": constraints.max_traders,
        },
        "summary": {
            "traders_count": len(traders),
            "avg_trade_duration_min": int(avg_dur) if avg_dur is not None else None,
            "avg_leverage": _round2(avg_lev) if avg_lev is not None else None,
            "avg_win_rate": _round2(avg_win_rate),
            "total_pnl": _round2(total_pnl),
            "considered": total_considered,
            "candidates_in_range": len(candidates),
            "time_budget_sec": time_budget_sec,
            "incomplete": len(traders) < constraints.max_traders,
            "resume_idx": resume_idx,
        },
        "traders": traders,
    }


def get_smart_money_profile(
    *,
    settings: "Settings | None" = None,
    constraints: SmartMoneyConstraints | None = None,
    force_refresh: bool = False,
) -> dict[str, Any]:
    c = constraints or SmartMoneyConstraints()
    cached_for_resume: dict[str, Any] | None = None
    if not force_refresh:
        cached = load_cached_profile(ttl_sec=c.cache_ttl_sec)
        if cached is not None:
            # Avoid serving stale cache across network switches (testnet vs mainnet)
            if settings is not None:
                want_net = settings.hl_network.lower()
                cached_net = str(cached.get("network") or "").lower()
                if cached_net and cached_net != want_net:
                    cached = None
                elif want_net == "mainnet" and str(cached.get("source") or "") == "fallback":
                    # If we are on mainnet, prefer a real scan over an old fallback cache.
                    cached = None
                elif want_net == "mainnet":
                    # If we previously cached an incomplete scan, resume scanning instead of returning it.
                    summ = cached.get("summary") if isinstance(cached.get("summary"), dict) else {}
                    if bool(summ.get("incomplete")) or int(summ.get("traders_count") or 0) == 0:
                        cached_for_resume = cached
                        cached = None
            if cached is not None:
                cached.setdefault("source", "cache")
                return cached
    if settings is not None and settings.hl_network.lower() == "mainnet":
        try:
            profile = build_profile_from_mainnet_leaderboard(settings=settings, constraints=c, previous=cached_for_resume)
        except Exception:
            profile = build_profile_fallback(c)
    else:
        profile = build_profile_fallback(c)
        if settings is not None:
            profile["network"] = settings.hl_network.lower()
    save_profile(profile)
    return profile

