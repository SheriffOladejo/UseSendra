import { useCallback, useEffect, useMemo, useState } from "react";

const defaultBase =
  import.meta.env.VITE_OPS_API_URL?.replace(/\/$/, "") ??
  // In production we serve the dashboard on the same origin as the ops API via nginx `/api`.
  `${window.location.origin}/api`;

type DashboardSummary = {
  status?: {
    last_poll_unix?: number;
    last_error?: string | null;
    mids_count?: number;
    progress?: { phase?: string; pct?: number; eta_sec?: number | null };
  };
  smart_money?: {
    source?: string;
    constraints?: {
      max_traders?: number;
    };
    summary?: {
      traders_count?: number;
      avg_leverage?: number;
      avg_trade_duration_min?: number;
      avg_win_rate?: number;
      total_pnl?: number;
    };
    traders?: Array<{
      rank: number;
      address: string;
      pnl: number;
      win_rate: number;
      trade_count: number;
      avg_trade_duration_min?: number | null;
    }>;
  };
  indicators?: Record<string, { end_time_ms?: number; values?: Record<string, number | null> }>;
  signals?: Record<string, Record<string, { long_score?: number; short_score?: number }>>;
  // backend includes richer breakdown; keep it loosely typed for now
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  // @ts-ignore
  candles?: Record<string, Record<string, { end_time_ms?: number; closes?: number[] }>>;
  recent_trades?: Array<Record<string, unknown>>;
  patterns?: {
    traders_analyzed?: number;
    top_assets?: Array<{ asset?: string; traders?: number }>;
    duration_buckets?: Record<string, number>;
    directional_bias?: Record<string, number>;
    margin_mix?: Record<string, number>;
    market_overlap_1h?: {
      eth_traders?: number;
      sol_traders?: number;
      eth_signal_bias?: string | null;
      sol_signal_bias?: string | null;
    };
  };
  correlations?: {
    by_interval?: Record<
      string,
      {
        closes_pearson?: number | null;
        returns_pearson?: number | null;
        sample_bars?: number;
        returns_sample?: number;
        closes_label?: string;
        returns_label?: string;
      }
    >;
    bias_by_interval?: Array<{
      interval?: string;
      eth_net_score?: number;
      sol_net_score?: number;
      bias_aligned?: boolean | null;
    }>;
    bias_summary?: { aligned_timeframes?: number; divergent_timeframes?: number; total?: number };
    perp?: {
      eth_funding?: number | null;
      sol_funding?: number | null;
      funding_spread?: number | null;
      eth_mark_oracle_spread_pct?: number | null;
      sol_mark_oracle_spread_pct?: number | null;
      mark_oracle_spread_diff_pct?: number | null;
    };
  };
  execution?: {
    status?: Record<string, unknown>;
    recent?: Array<{
      ts?: number;
      coin?: string;
      side?: string;
      mode?: string;
      reason?: string;
      detail?: string;
      sz?: number | null;
    }>;
  };
};

async function getJson<T>(path: string): Promise<T> {
  const res = await fetch(`${defaultBase}${path}`);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return (await res.json()) as T;
}

function fmtPct(x: number | undefined) {
  if (x == null) return "—";
  return `${Math.round(x)}%`;
}

function shortAddr(a: string) {
  if (!a) return "—";
  return `${a.slice(0, 6)}…${a.slice(-4)}`;
}

function traderUrl(network: string | undefined, address: string) {
  // Hyperliquid web app doesn't reliably deep-link to other wallets when logged in.
  // Use a public tracker so each address always resolves correctly.
  void network;
  return `https://trckr.xyz/dashboard?wallet=${encodeURIComponent(address)}`;
}

function formatTimeAgo(unixSec?: number) {
  if (!unixSec) return "—";
  const s = Math.max(0, Math.floor(Date.now() / 1000 - unixSec));
  if (s < 60) return `${s}s ago`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  return `${h}h ago`;
}

function formatDuration(mins?: number | null) {
  if (mins == null || !Number.isFinite(mins)) return "—";
  if (mins < 60) return `${Math.max(0, Math.round(mins))}m`;
  const h = Math.round(mins / 60);
  if (h < 48) return `${h}h`;
  const d = Math.round(h / 24);
  return `${d}d`;
}

function fmtPrice(x: number) {
  if (!Number.isFinite(x)) return "—";
  if (x >= 1000) return x.toFixed(0);
  if (x >= 100) return x.toFixed(1);
  if (x >= 1) return x.toFixed(3);
  return x.toPrecision(3);
}

function bucketBarPct(values: Record<string, number> | undefined, key: string, total: number) {
  const v = values?.[key] ?? 0;
  if (total <= 0) return 0;
  return Math.round((v / total) * 100);
}

function fmtCorr(r: number | null | undefined) {
  if (r == null || !Number.isFinite(r)) return "—";
  return r.toFixed(2);
}

function corrTone(r: number | null | undefined): string {
  if (r == null || !Number.isFinite(r)) return "";
  const a = Math.abs(r);
  if (a >= 0.65) return "corrHi";
  if (a >= 0.35) return "corrMid";
  return "corrLo";
}

function fmtFundingSci(x: number | null | undefined) {
  if (x == null || !Number.isFinite(x)) return "—";
  const ax = Math.abs(x);
  if (ax >= 0.0001) return x.toFixed(5);
  return x.toExponential(2);
}

function formatExecTime(ts?: number) {
  if (ts == null || !Number.isFinite(ts)) return "—";
  const d = new Date(ts * 1000);
  return d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit", second: "2-digit" });
}

function MiniLine({ points, tf }: { points: number[]; tf: string }) {
  const w = 560;
  const h = 220;
  const padL = 54;
  const padR = 10;
  const padT = 12;
  const padB = 26;
  const innerW = w - padL - padR;
  const innerH = h - padT - padB;

  const { d, min, max } = useMemo(() => {
    if (points.length < 2) return { d: "", min: 0, max: 0 };
    const minV = Math.min(...points);
    const maxV = Math.max(...points);
    const range = maxV - minV || 1;
    const path = points
      .map((p, i) => {
        const x = padL + (i / (points.length - 1)) * innerW;
        const y = padT + (1 - (p - minV) / range) * innerH;
        return `${i === 0 ? "M" : "L"} ${x.toFixed(2)} ${y.toFixed(2)}`;
      })
      .join(" ");
    return { d: path, min: minV, max: maxV };
  }, [points, innerW, innerH, padL, padT]);

  const mid = (min + max) / 2;
  const yTicks = [
    { y: padT, v: max },
    { y: padT + innerH / 2, v: mid },
    { y: padT + innerH, v: min },
  ];

  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="chartSvg" aria-label="Price chart with axes">
      {/* grid */}
      {yTicks.map((t) => (
        <line key={t.y} x1={padL} x2={w - padR} y1={t.y} y2={t.y} className="chartGrid" />
      ))}
      <line x1={padL} x2={padL} y1={padT} y2={h - padB} className="chartAxis" />
      <line x1={padL} x2={w - padR} y1={h - padB} y2={h - padB} className="chartAxis" />

      {/* labels */}
      {yTicks.map((t) => (
        <text key={t.y} x={padL - 8} y={t.y + 4} textAnchor="end" className="chartText">
          {fmtPrice(t.v)}
        </text>
      ))}
      <text x={padL} y={h - 8} textAnchor="start" className="chartText">
        past ({tf.toUpperCase()})
      </text>
      <text x={w - padR} y={h - 8} textAnchor="end" className="chartText">
        now
      </text>

      {/* series */}
      <path d={d} className="chartLine" />
    </svg>
  );
}

export default function App() {
  const [data, setData] = useState<DashboardSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [tab, setTab] = useState<"chart" | "patterns" | "strategy" | "correlations">("chart");
  const [coin, setCoin] = useState<"ETH" | "SOL">("ETH");
  const [tf, setTf] = useState<"1m" | "1h" | "4h" | "1d">("1m");

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const d = await getJson<DashboardSummary>("/dashboard/summary");
      setData(d);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
    const t = window.setInterval(() => void refresh(), 5000);
    return () => window.clearInterval(t);
  }, [refresh]);

  const progress = data?.status?.progress;
  const pct = Math.max(0, Math.min(100, progress?.pct ?? 0));
  const sm = data?.smart_money;
  const smSummary = sm?.summary;
  const traders = sm?.traders ?? [];
  const tradersTarget = sm?.constraints?.max_traders ?? 100;
  const network = (sm as any)?.network as string | undefined;
  const patterns = data?.patterns;
  const correlations = data?.correlations;
  const execution = data?.execution;
  const exStatus = execution?.status as
    | {
        trading_enabled?: boolean;
        trading_dry_run?: boolean;
        trading_kill_switch?: boolean;
        armed_live?: boolean;
        has_secret_key?: boolean;
        signal_trade_tf?: string;
        long_threshold?: number;
        short_threshold?: number;
        min_edge?: number;
        notional_usd?: number;
      }
    | undefined;
  const signals = data?.signals?.[coin]?.[tf];
  const signalAny = (data?.signals as any)?.[coin]?.[tf];
  const checks: Array<any> = signalAny?.breakdown?.checks ?? [];

  const bias = useMemo(() => {
    const l = signals?.long_score ?? 0;
    const s = signals?.short_score ?? 0;
    if (l === s) return { side: "NEUTRAL", pct: 50 };
    const side = l > s ? "LONG" : "SHORT";
    const pct = Math.min(99, Math.max(1, Math.round((Math.max(l, s) / 100) * 100)));
    return { side, pct };
  }, [signals?.long_score, signals?.short_score]);

  const chartPoints = useMemo(() => {
    const closes = data?.candles?.[coin]?.[tf]?.closes ?? [];
    if (closes.length >= 2) return closes;
    const ind = (data?.indicators as any)?.[coin]?.[tf]?.values ?? {};
    const ema9 = ind["ema_9"];
    const seed = typeof ema9 === "number" ? ema9 : 100;
    return Array.from({ length: 60 }, (_, i) => seed * (1 + Math.sin(i / 7) * 0.006 + Math.cos(i / 11) * 0.004));
  }, [coin, tf, data]);

  return (
    <div className="app">
      <header className="topbar">
        <div className="brand">
          <div className="logo" aria-hidden="true" />
          <div>
            <div className="brandTitle">Hyperliquid Analyzer</div>
            <div className="brandSub">Trade Pattern Discovery Engine</div>
          </div>
        </div>

        <div className="topbarRight">
          <span className={`pill ${error ? "pillErr" : "pillOk"}`}>
            {error ? "Disconnected" : "Connected"}
          </span>
          <button className="ghost" type="button" onClick={() => void refresh()} disabled={loading}>
            {loading ? "Fetching…" : "Refresh"}
          </button>
        </div>
      </header>

      <section className="progressWrap">
        <div className="progressMeta">
          <div className="muted2">Processing address {traders.length}/{tradersTarget}</div>
          <div className="muted2">{fmtPct(pct)}</div>
        </div>
        <div className="progressTrack" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
          <div className="progressBar" style={{ width: `${pct}%` }} />
        </div>
        <div className="progressMeta">
          <div className="muted2">{progress?.phase ? `Phase: ${progress.phase}` : "—"}</div>
          <div className="muted2">{progress?.eta_sec != null ? `ETA: ${progress.eta_sec}s` : `Last poll: ${formatTimeAgo(data?.status?.last_poll_unix)}`}</div>
        </div>
      </section>

      <section className="execStrip" aria-label="Execution status">
        <div className="execStripHead">
          <div className="execStripTitle">Execution</div>
          <div className="execPills">
            <span className={`execPill ${exStatus?.trading_enabled ? "on" : "off"}`}>
              Trading {exStatus?.trading_enabled ? "ON" : "OFF"}
            </span>
            <span className={`execPill ${exStatus?.trading_dry_run !== false ? "dry" : "livep"}`}>
              {exStatus?.trading_dry_run !== false ? "Dry-run" : "Live orders"}
            </span>
            <span className={`execPill ${exStatus?.trading_kill_switch ? "kill" : ""}`}>
              Kill {exStatus?.trading_kill_switch ? "ON" : "off"}
            </span>
            <span className={`execPill ${exStatus?.armed_live ? "armed" : ""}`}>
              Live-ready {exStatus?.armed_live ? "yes" : "no"}
            </span>
          </div>
        </div>
        <div className="execStripMeta muted2">
          Signal TF <strong>{String(exStatus?.signal_trade_tf ?? "1m").toUpperCase()}</strong> · thresholds L≥{exStatus?.long_threshold ?? 45} / S≥
          {exStatus?.short_threshold ?? 45} · edge {exStatus?.min_edge ?? 5} · ~${exStatus?.notional_usd ?? 25} notional
        </div>
        <div className="execLog">
          {(execution?.recent ?? []).length === 0 ? (
            <div className="muted2">No execution events yet. Set TRADING_ENABLED=true to evaluate intents (still dry-run unless you disable TRADING_DRY_RUN and set HL_SECRET_KEY).</div>
          ) : (
            (execution?.recent ?? [])
              .slice()
              .reverse()
              .slice(0, 5)
              .map((e, idx) => (
                <div key={idx} className="execLogLine">
                  <span className="execLogT">{formatExecTime(e.ts)}</span>
                  <span className="execLogC">{e.coin}</span>
                  <span
                    className={`execLogS ${
                      String(e.side ?? "").includes("LONG") ? "long" : String(e.side ?? "").includes("SHORT") ? "short" : ""
                    }`}
                  >
                    {e.side}
                  </span>
                  <span className="muted2">{e.mode}</span>
                  <span className="muted2">{e.reason}</span>
                  {e.sz != null ? <span className="mono execLogSz">{Number(e.sz).toFixed(5)}</span> : null}
                </div>
              ))
          )}
        </div>
      </section>

      {error ? <div className="bannerErr">Could not reach the ops API at <code>{defaultBase}</code>. {error}</div> : null}

      <section className="stats">
        <div className="statCard">
          <div className="statVal">{smSummary?.traders_count ?? "—"}</div>
          <div className="statLbl">Addresses</div>
        </div>
        <div className="statCard">
          <div className="statVal">{data?.status?.mids_count ?? "—"}</div>
          <div className="statLbl">Coins tracked</div>
        </div>
        <div className="statCard">
          <div className="statVal">
            {smSummary?.avg_trade_duration_min != null ? formatDuration(smSummary.avg_trade_duration_min) : "—"}
          </div>
          <div className="statLbl">Avg duration</div>
        </div>
        <div className="statCard">
          <div className="statVal">{smSummary?.avg_win_rate != null ? `${Math.round(smSummary.avg_win_rate * 100)}%` : "—"}</div>
          <div className="statLbl">Avg win rate</div>
        </div>
        <div className="statCard">
          <div className="statVal">{smSummary?.total_pnl != null ? `$${Math.round(smSummary.total_pnl).toLocaleString()}` : "—"}</div>
          <div className="statLbl">Total PnL</div>
        </div>
        <div className="statCard">
          <div className="statVal">{smSummary?.avg_leverage != null ? `${smSummary.avg_leverage.toFixed(1)}x` : "—"}</div>
          <div className="statLbl">Avg leverage</div>
        </div>
      </section>

      <section className="mainGrid">
        <section className="panel">
          <div className="panelHead">
            <div className="panelTitle">Tracked addresses</div>
            <div className="panelMeta muted2">{traders.length}/{tradersTarget}</div>
          </div>
          <div className="list listScroll">
            {traders.map((t) => (
              <button
                key={t.address}
                type="button"
                className="row rowBtn"
                onClick={() => window.open(traderUrl(network, t.address), "_blank", "noopener,noreferrer")}
                title="Open trader on Hyperliquid"
              >
                <div className="rowLeft">
                  <div className="rowRank">{t.rank}</div>
                  <div>
                    <div className="rowAddr">{shortAddr(t.address)}</div>
                    <div className="rowSub muted2">
                      {t.trade_count} trades · {Math.round(t.win_rate * 100)}% WR · {formatDuration(t.avg_trade_duration_min)}
                    </div>
                  </div>
                </div>
                <div className={`rowPnl ${t.pnl >= 0 ? "pos" : "neg"}`}>
                  {t.pnl >= 0 ? "+" : ""}
                  {t.pnl.toFixed(2)}
                </div>
              </button>
            ))}
          </div>
        </section>

        <section className="panel panelWide">
          <div className="tabs">
            {(["chart", "patterns", "strategy", "correlations"] as const).map((k) => (
              <button key={k} type="button" className={`tab ${tab === k ? "tabOn" : ""}`} onClick={() => setTab(k)}>
                {k === "chart" ? "Chart" : k[0].toUpperCase() + k.slice(1)}
              </button>
            ))}
          </div>

          <div className="panelHead2">
            <div className="seg">
              <button type="button" className={`segBtn ${coin === "ETH" ? "segOn" : ""}`} onClick={() => setCoin("ETH")}>
                ETH
              </button>
              <button type="button" className={`segBtn ${coin === "SOL" ? "segOn" : ""}`} onClick={() => setCoin("SOL")}>
                SOL
              </button>
            </div>
            <div className="seg">
              {(["1m", "1h", "4h", "1d"] as const).map((k) => (
                <button key={k} type="button" className={`segBtn ${tf === k ? "segOn" : ""}`} onClick={() => setTf(k)}>
                  {k.toUpperCase()}
                </button>
              ))}
            </div>
            <div className="muted2">
              Signal: <strong className="sig">{signals?.long_score ?? 0}</strong> /{" "}
              <strong className="sig2">{signals?.short_score ?? 0}</strong>
            </div>
          </div>

          <div className="chartWrap">
            {tab === "chart" ? <MiniLine points={chartPoints} tf={tf} /> : null}

            {tab === "strategy" ? (
              <div className="strategy">
                <div className="strategyHead">
                  <div className="strategyBias">
                    Bias: <strong className={bias.side === "LONG" ? "sig" : bias.side === "SHORT" ? "sig2" : ""}>{bias.side}</strong>
                    <span className="muted2"> · confidence {bias.pct}%</span>
                  </div>
                  <div className="muted2">LONG {signals?.long_score ?? 0} / SHORT {signals?.short_score ?? 0}</div>
                </div>

                <div className="muted2" style={{ marginBottom: 10 }}>
                  Reasons (selected): checks that contributed points on this timeframe.
                </div>

                <div className="strategyList">
                  {checks
                    .filter((c) => (bias.side === "LONG" ? (c?.long_pts ?? 0) > 0 : bias.side === "SHORT" ? (c?.short_pts ?? 0) > 0 : false))
                    .sort((a, b) => (bias.side === "LONG" ? (b.long_pts ?? 0) - (a.long_pts ?? 0) : (b.short_pts ?? 0) - (a.short_pts ?? 0)))
                    .slice(0, 6)
                    .map((c, idx) => (
                      <div key={idx} className="strategyRow">
                        <div className="strategyName">{c?.name ?? "—"}</div>
                        <div className="strategyPts">
                          +{bias.side === "LONG" ? c?.long_pts ?? 0 : c?.short_pts ?? 0}
                        </div>
                      </div>
                    ))}
                  {!checks.length ? <div className="muted2">No breakdown available yet.</div> : null}
                </div>
              </div>
            ) : null}

            {tab === "patterns" ? (
              <div className="patterns">
                <div className="patternsHead">
                  <div className="patternsTitle">Cross-trader rollups</div>
                  <div className="muted2">
                    {patterns?.traders_analyzed != null && patterns.traders_analyzed > 0
                      ? `${patterns.traders_analyzed} traders in last scan`
                      : "Waiting for smart-money scan…"}
                  </div>
                </div>

                {!patterns || !patterns.traders_analyzed ? (
                  <div className="muted2" style={{ marginTop: 8 }}>
                    Patterns populate after the leaderboard pass completes. Check progress above.
                  </div>
                ) : (
                  <>
                    <div className="patternSection">
                      <div className="patternSectionTitle">Most traded assets (overlap)</div>
                      <div className="patternAssetList">
                        {(patterns.top_assets ?? []).map((row, idx) => (
                          <div key={idx} className="patternAssetRow">
                            <div className="patternAssetName">{row.asset ?? "—"}</div>
                            <div className="patternAssetMeta muted2">{row.traders ?? 0} traders</div>
                          </div>
                        ))}
                        {!patterns.top_assets?.length ? <div className="muted2">No asset overlap yet.</div> : null}
                      </div>
                    </div>

                    <div className="patternGrid2">
                      <div className="patternSection">
                        <div className="patternSectionTitle">Hold duration (avg per trader)</div>
                        {(() => {
                          const d = patterns.duration_buckets ?? {};
                          const total = Object.values(d).reduce((a, b) => a + (typeof b === "number" ? b : 0), 0);
                          const rows = [
                            { key: "under_1h", label: "< 1h" },
                            { key: "1h_to_24h", label: "1h – 24h" },
                            { key: "over_24h", label: "> 24h" },
                            { key: "unknown", label: "Unknown" },
                          ] as const;
                          return (
                            <div className="patternBars">
                              {rows.map((r) => (
                                <div key={r.key} className="patternBarRow">
                                  <div className="patternBarLbl">{r.label}</div>
                                  <div className="patternBarTrack">
                                    <div className="patternBarFill" style={{ width: `${bucketBarPct(d, r.key, total)}%` }} />
                                  </div>
                                  <div className="patternBarVal">{d[r.key] ?? 0}</div>
                                </div>
                              ))}
                            </div>
                          );
                        })()}
                      </div>

                      <div className="patternSection">
                        <div className="patternSectionTitle">Win-rate directional tilt</div>
                        {(() => {
                          const b = patterns.directional_bias ?? {};
                          const total = (b.long_favored ?? 0) + (b.short_favored ?? 0) + (b.balanced ?? 0);
                          return (
                            <div className="patternBars">
                              {[
                                { key: "long_favored", label: "Long-favored", cls: "long" },
                                { key: "short_favored", label: "Short-favored", cls: "short" },
                                { key: "balanced", label: "Balanced", cls: "neu" },
                              ].map((r) => (
                                <div key={r.key} className="patternBarRow">
                                  <div className="patternBarLbl">{r.label}</div>
                                  <div className="patternBarTrack">
                                    <div className={`patternBarFill ${r.cls}`} style={{ width: `${bucketBarPct(b, r.key, total)}%` }} />
                                  </div>
                                  <div className="patternBarVal">{b[r.key as keyof typeof b] ?? 0}</div>
                                </div>
                              ))}
                            </div>
                          );
                        })()}
                      </div>
                    </div>

                    <div className="patternGrid2">
                      <div className="patternSection">
                        <div className="patternSectionTitle">Margin mode mix</div>
                        {(() => {
                          const m = patterns.margin_mix ?? {};
                          const total = (m.isolated ?? 0) + (m.cross ?? 0) + (m.unknown ?? 0);
                          return (
                            <div className="patternBars">
                              {[
                                { key: "isolated", label: "Isolated" },
                                { key: "cross", label: "Cross" },
                                { key: "unknown", label: "Unknown" },
                              ].map((r) => (
                                <div key={r.key} className="patternBarRow">
                                  <div className="patternBarLbl">{r.label}</div>
                                  <div className="patternBarTrack">
                                    <div className="patternBarFill neu" style={{ width: `${bucketBarPct(m, r.key, total)}%` }} />
                                  </div>
                                  <div className="patternBarVal">{m[r.key as keyof typeof m] ?? 0}</div>
                                </div>
                              ))}
                            </div>
                          );
                        })()}
                      </div>

                      <div className="patternSection">
                        <div className="patternSectionTitle">ETH / SOL overlap vs 1H signal</div>
                        <div className="patternOverlap">
                          <div className="patternOverlapRow">
                            <span className="patternOverlapCoin">ETH</span>
                            <span className="muted2">{patterns.market_overlap_1h?.eth_traders ?? 0} traders trade it</span>
                            <span className={`patternBias ${patterns.market_overlap_1h?.eth_signal_bias === "long" ? "long" : patterns.market_overlap_1h?.eth_signal_bias === "short" ? "short" : "neu"}`}>
                              1H: {patterns.market_overlap_1h?.eth_signal_bias ?? "—"}
                            </span>
                          </div>
                          <div className="patternOverlapRow">
                            <span className="patternOverlapCoin">SOL</span>
                            <span className="muted2">{patterns.market_overlap_1h?.sol_traders ?? 0} traders trade it</span>
                            <span className={`patternBias ${patterns.market_overlap_1h?.sol_signal_bias === "long" ? "long" : patterns.market_overlap_1h?.sol_signal_bias === "short" ? "short" : "neu"}`}>
                              1H: {patterns.market_overlap_1h?.sol_signal_bias ?? "—"}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </>
                )}
              </div>
            ) : null}

            {tab === "correlations" ? (
              <div className="correlations">
                <div className="corrHead">
                  <div className="corrTitle">ETH ↔ SOL</div>
                  <div className="muted2">
                    Price linkage, return co-movement, signal bias match, and live perp skew (same poll).
                  </div>
                </div>

                {!correlations?.by_interval ? (
                  <div className="muted2" style={{ marginTop: 10 }}>Waiting for market data…</div>
                ) : (
                  <>
                    <div className="corrSection">
                      <div className="corrSectionTitle">{"Pearson · closes & log-returns"}</div>
                      <div className="corrHint muted2">
                        Selected timeframe <strong>{tf.toUpperCase()}</strong> highlighted. Returns need ≥10 overlapping bars.
                      </div>
                      <div className="corrTableWrap">
                        <table className="corrTable">
                          <thead>
                            <tr>
                              <th>TF</th>
                              <th>Closes ρ</th>
                              <th>Returns ρ</th>
                              <th>Bars</th>
                              <th>Read</th>
                            </tr>
                          </thead>
                          <tbody>
                            {(["1m", "1h", "4h", "1d"] as const).map((iv) => {
                              const row = correlations.by_interval?.[iv];
                              const cr = row?.closes_pearson;
                              const rr = row?.returns_pearson;
                              const active = iv === tf;
                              return (
                                <tr key={iv} className={active ? "corrRowActive" : ""}>
                                  <td className="corrTf">{iv.toUpperCase()}</td>
                                  <td>
                                    <span className={`corrPill ${corrTone(cr)}`}>{fmtCorr(cr)}</span>
                                  </td>
                                  <td>
                                    <span className={`corrPill ${corrTone(rr)}`}>{fmtCorr(rr)}</span>
                                  </td>
                                  <td className="corrNum muted2">{row?.sample_bars ?? "—"}</td>
                                  <td className="corrRead muted2">{row?.returns_label ?? row?.closes_label ?? "—"}</td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>

                    <div className="corrGrid2">
                      <div className="corrSection">
                        <div className="corrSectionTitle">Signal net (LONG − SHORT)</div>
                        <div className="corrBiasList">
                          {(correlations.bias_by_interval ?? []).map((row) => {
                            const iv = String(row.interval ?? "");
                            const al = row.bias_aligned;
                            return (
                              <div key={iv} className={`corrBiasRow ${iv === tf ? "corrRowActive2" : ""}`}>
                                <span className="corrTf">{iv.toUpperCase()}</span>
                                <span className="corrNet">
                                  ETH <strong>{row.eth_net_score ?? 0}</strong>
                                  <span className="muted2"> · </span>
                                  SOL <strong>{row.sol_net_score ?? 0}</strong>
                                </span>
                                <span
                                  className={
                                    al === true ? "corrAlign yes" : al === false ? "corrAlign no" : "corrAlign neu muted2"
                                  }
                                >
                                  {al === true ? "Aligned" : al === false ? "Divergent" : "—"}
                                </span>
                              </div>
                            );
                          })}
                        </div>
                        <div className="muted2 corrFoot">
                          Bias match: {correlations.bias_summary?.aligned_timeframes ?? 0} / {correlations.bias_summary?.total ?? 4}{" "}
                          aligned · {correlations.bias_summary?.divergent_timeframes ?? 0} divergent
                        </div>
                      </div>

                      <div className="corrSection">
                        <div className="corrSectionTitle">Perp snapshot</div>
                        <div className="corrPerp">
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">ETH funding</span>
                            <span className="mono">{fmtFundingSci(correlations.perp?.eth_funding ?? undefined)}</span>
                          </div>
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">SOL funding</span>
                            <span className="mono">{fmtFundingSci(correlations.perp?.sol_funding ?? undefined)}</span>
                          </div>
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">Funding (ETH − SOL)</span>
                            <span className="mono">{fmtFundingSci(correlations.perp?.funding_spread ?? undefined)}</span>
                          </div>
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">Mark − oracle · ETH</span>
                            <span className="mono">
                              {correlations.perp?.eth_mark_oracle_spread_pct != null
                                ? `${(correlations.perp.eth_mark_oracle_spread_pct as number).toFixed(3)}%`
                                : "—"}
                            </span>
                          </div>
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">Mark − oracle · SOL</span>
                            <span className="mono">
                              {correlations.perp?.sol_mark_oracle_spread_pct != null
                                ? `${(correlations.perp.sol_mark_oracle_spread_pct as number).toFixed(3)}%`
                                : "—"}
                            </span>
                          </div>
                          <div className="corrPerpRow">
                            <span className="corrPerpLbl">Premium spread (ETH − SOL)</span>
                            <span className="mono">
                              {correlations.perp?.mark_oracle_spread_diff_pct != null
                                ? `${(correlations.perp.mark_oracle_spread_diff_pct as number).toFixed(3)} pp`
                                : "—"}
                            </span>
                          </div>
                        </div>
                        <div className="muted2 corrFoot">
                          Positive funding spread → ETH more long-crowded vs SOL (contrarian read with care).
                        </div>
                      </div>
                    </div>
                  </>
                )}
              </div>
            ) : null}
          </div>
        </section>

        <section className="panel">
          <div className="panelHead">
            <div className="panelTitle">Recent trades</div>
            <div className="panelMeta muted2">Live feed</div>
          </div>
          <div className="list">
            {(data?.recent_trades ?? []).slice(0, 12).map((t, idx) => {
              const coin = String(t["coin"] ?? "—");
              const side = String(t["side"] ?? "—");
              const pnl = Number(t["pnl"] ?? t["closedPnl"] ?? 0);
              return (
                <div key={idx} className="tradeRow">
                  <div className="tradeLeft">
                    <div className="tradeCoin">{coin}</div>
                    <div className={`tradeSide ${side.toUpperCase().includes("LONG") ? "long" : "short"}`}>{side}</div>
                  </div>
                  <div className={`tradePnl ${pnl >= 0 ? "pos" : "neg"}`}>
                    {pnl >= 0 ? "+" : ""}
                    {pnl.toFixed(2)}
                  </div>
                </div>
              );
            })}
          </div>
        </section>
      </section>
    </div>
  );
}
