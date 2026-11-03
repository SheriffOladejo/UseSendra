import { useState, useEffect } from 'react'
import {
  Search,
  CheckCircle2,
  Clock,
  AlertTriangle,
  ExternalLink,
  ChevronDown,
  ChevronUp,
  User,
  Building2,
  ArrowUpRight,
  Layers,
  History,
  RefreshCw,
  Copy,
  Check,
  MousePointerClick,
  Activity,
  ShieldCheck,
  ShieldAlert,
  Minus,
  Wifi,
} from 'lucide-react'
import { api, type InspectorResponse, type SystemStatusResponse, type SystemStatusService } from '../lib/api'
import inspectorHoodie from '../assets/inspector-hoodie.jpg'

// Helper component for values that can be clicked to copy directly
function CopyableText({
  value,
  displayValue,
  className = '',
  mono = true,
}: {
  value?: string | number | null
  displayValue?: React.ReactNode
  className?: string
  mono?: boolean
}) {
  const [copied, setCopied] = useState(false)

  const stringVal = value !== undefined && value !== null ? String(value) : ''

  if (!stringVal || stringVal === '—') {
    return <span className="text-gray-400">—</span>
  }

  const handleCopy = async (e: React.MouseEvent) => {
    e.stopPropagation()
    try {
      await navigator.clipboard.writeText(stringVal)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch (err) {
      console.error('Failed to copy text', err)
    }
  }

  return (
    <span
      onClick={handleCopy}
      title="Click value to copy to clipboard"
      className={`inline-flex items-center gap-1.5 cursor-pointer rounded px-1.5 py-0.5 -mx-1.5 hover:bg-emerald-50 hover:text-emerald-700 transition-all group ${
        mono ? 'font-mono' : ''
      } ${className}`}
    >
      <span>{displayValue || stringVal}</span>
      <span className="text-gray-400 group-hover:text-emerald-600 transition-colors">
        {copied ? (
          <span className="inline-flex items-center gap-0.5 text-[10px] font-sans font-bold text-emerald-600 bg-emerald-100 px-1 rounded">
            <Check className="h-3 w-3" /> Copied!
          </span>
        ) : (
          <Copy className="h-3 w-3 opacity-40 group-hover:opacity-100" />
        )}
      </span>
    </span>
  )
}

export function TxInspectorPage() {
  const [activeTab, setActiveTab] = useState<'lookup' | 'system-status'>('lookup')
  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState<InspectorResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [showRawJson, setShowRawJson] = useState(false)

  const [selectedTxHash, setSelectedTxHash] = useState<string | null>(null)
  const [inspectingTx, setInspectingTx] = useState(false)
  const [chainFilter, setChainFilter] = useState<'all' | 'aptos' | 'stellar' | 'arc'>('all')

  // System Status state
  const [statusData, setStatusData] = useState<SystemStatusResponse | null>(null)
  const [statusLoading, setStatusLoading] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [statusLastRefreshed, setStatusLastRefreshed] = useState<Date | null>(null)

  const executeLookup = async (qTarget: string) => {
    const q = qTarget.trim()
    if (!q) return

    setLoading(true)
    setError(null)
    setSelectedTxHash(null)

    try {
      const res = await api.inspect(q)
      setData(res)
      setChainFilter('all')
      if (res.history && res.history.length > 0) {
        setSelectedTxHash(res.history[0].txHash || res.history[0].id || null)
      }
    } catch (err: any) {
      setError(err.message || 'Failed to inspect transaction status')
      setData(null)
    } finally {
      setLoading(false)
    }
  }

  const handleSearch = (e?: React.FormEvent) => {
    if (e) e.preventDefault()
    executeLookup(query)
  }

  // Row click ONLY updates Centiiv, 1Click, and DB Outcome cards without replacing search input or clearing history
  const handleRowClick = async (item: { txHash?: string; id?: string }) => {
    const target = item.txHash || item.id
    if (!target) return

    setSelectedTxHash(target)
    setInspectingTx(true)

    try {
      const res = await api.inspect(target)
      setData((prev) => {
        if (!prev) return res
        return {
          ...prev,
          oneClick: res.oneClick,
          centiiv: res.centiiv,
          offrampOutcome: res.offrampOutcome,
        }
      })
    } catch (err) {
      console.error('Failed to inspect selected transaction', err)
    } finally {
      setInspectingTx(false)
    }
  }

  // Pre-fill query from URL if provided e.g. /inspector?q=2348164727987
  useEffect(() => {
    const urlParams = new URLSearchParams(window.location.search)
    const initialQ = urlParams.get('q')
    if (initialQ) {
      setQuery(initialQ)
      executeLookup(initialQ)
    }
  }, [])

  const fetchSystemStatus = async () => {
    setStatusLoading(true)
    setStatusError(null)
    try {
      const res = await api.systemStatus()
      setStatusData(res)
      setStatusLastRefreshed(new Date())
    } catch (err: any) {
      setStatusError(err.message || 'Failed to load system status')
    } finally {
      setStatusLoading(false)
    }
  }

  // Load status when the tab becomes active
  useEffect(() => {
    if (activeTab === 'system-status' && statusData === null) {
      fetchSystemStatus()
    }
  }, [activeTab])

  const getBadgeColor = (status?: string) => {
    if (!status) return 'bg-gray-100 text-gray-700 border-gray-200'
    const s = status.toUpperCase()
    if (s === 'SUCCESS' || s === 'FULFILLED' || s === 'CONFIRMED' || s === 'COMPLETED') {
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    }
    if (s === 'PENDING' || s === 'PROCESSING') {
      return 'bg-amber-50 text-amber-700 border-amber-200'
    }
    return 'bg-rose-50 text-rose-700 border-rose-200'
  }

  const formatIsoDate = (isoStr?: string) => {
    if (!isoStr) return '—'
    try {
      const dt = new Date(isoStr)
      if (isNaN(dt.getTime())) return isoStr
      return dt.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      })
    } catch {
      return isoStr
    }
  }

  const get1ClickDestInfo = (oneClick: any) => {
    const destAsset = String(
      oneClick?.quoteResponse?.quoteRequest?.destinationAsset ||
        oneClick?.destinationAsset ||
        ''
    ).toLowerCase()
    const recipient = String(
      oneClick?.quoteResponse?.quoteRequest?.recipient || oneClick?.destinationAddress || ''
    )
    if (destAsset.includes('sol-') || destAsset.includes('solana') || (!recipient.startsWith('G') && recipient.length >= 32 && !recipient.startsWith('0x'))) {
      return { chain: 'Solana', decimals: 6, label: 'Solana USDC' }
    }
    if (destAsset.includes('stellar') || destAsset.includes(':1100_') || recipient.startsWith('G') || recipient.startsWith('C')) {
      return { chain: 'Stellar', decimals: 7, label: 'Stellar USDC' }
    }
    return { chain: 'Destination', decimals: 6, label: 'USDC' }
  }

  const chainBadgeClass = (chain?: string) => {
    const c = (chain || '').toLowerCase()
    if (c === 'stellar') return 'bg-indigo-50 text-indigo-700 border-indigo-200'
    if (c === 'arc') return 'bg-orange-50 text-orange-700 border-orange-200'
    if (c === 'solana') return 'bg-violet-50 text-violet-700 border-violet-200'
    return 'bg-sky-50 text-sky-700 border-sky-200'
  }

  const chainLabel = (chain?: string, fallback?: string) => {
    if (fallback) return fallback
    const c = (chain || '').toLowerCase()
    if (c === 'stellar') return 'Stellar'
    if (c === 'arc') return 'Arc'
    if (c === 'solana') return 'Solana'
    if (c === 'polygon') return 'Polygon'
    if (c === 'aptos') return 'Aptos'
    return chain || 'Unknown'
  }

  const filteredHistory =
    data?.history?.filter((tx) => (chainFilter === 'all' ? true : (tx.chain || 'aptos') === chainFilter)) ??
    []

  // Calculate 1Click conversion rate and fee
  const get1ClickRateAndFee = (oneClick: any) => {
    if (!oneClick?.swapDetails) return { rate: '—', fee: '—' }
    const dest = get1ClickDestInfo(oneClick)
    const dep = Number(
      oneClick.swapDetails.depositedAmountFormatted || Number(oneClick.swapDetails.amountIn || 0) / 1e6
    )
    const out = Number(
      oneClick.swapDetails.amountOutFormatted ||
        Number(oneClick.swapDetails.amountOut || 0) / 10 ** dest.decimals
    )

    let rateStr = '—'
    if (dep > 0 && out > 0) {
      rateStr = `1 origin = ${(out / dep).toFixed(4)} USDC`
    }

    const withdrawFeeRaw = oneClick.swapDetails.withdrawFee || 0
    const withdrawFeeUsdc = (Number(withdrawFeeRaw) / 1e6).toFixed(4)
    const slippage = oneClick.swapDetails.slippage ?? 0
    const feeStr = `${withdrawFeeUsdc} USDC (${slippage}% max slippage)`

    return { rate: rateStr, fee: feeStr }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-3">
          <span>Status Inspector</span>
          <img
            src={inspectorHoodie}
            alt="Detective"
            style={{ imageRendering: 'auto', objectPosition: 'top center' }}
            className="h-10 w-8 rounded-lg border border-gray-200 shadow-sm object-cover bg-gray-100"
          />
        </h1>
        <p className="mt-1 text-sm text-gray-500">
          Live probe for 1Click swap details, Centiiv NGN disbursals, user profile, and transaction history.
        </p>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 rounded-2xl bg-gray-100 p-1 w-fit">
        <button
          type="button"
          onClick={() => setActiveTab('lookup')}
          className={`flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-semibold transition-all ${
            activeTab === 'lookup'
              ? 'bg-white text-gray-900 shadow-sm'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          <Search className="h-4 w-4" />
          Transaction Lookup
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('system-status')}
          className={`flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-semibold transition-all ${
            activeTab === 'system-status'
              ? 'bg-white text-gray-900 shadow-sm'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          <Activity className="h-4 w-4" />
          System Status
          {statusData && (
            <span
              className={`ml-1 h-2 w-2 rounded-full ${
                statusData.ok ? 'bg-emerald-500' : 'bg-red-500'
              }`}
            />
          )}
        </button>
      </div>

      {/* ─── SYSTEM STATUS TAB ─── */}
      {activeTab === 'system-status' && (
        <div className="space-y-6">
          {/* Toolbar */}
          <div className="flex items-center justify-between">
            <div>
              {statusData && (
                <p className="text-xs text-gray-400">
                  Last checked by cron:{' '}
                  <span className="font-mono font-medium text-gray-600">
                    {statusData.checked_at
                      ? new Date(statusData.checked_at).toLocaleString('en-US', {
                          month: 'short', day: 'numeric', year: 'numeric',
                          hour: '2-digit', minute: '2-digit', second: '2-digit',
                        })
                      : '—'}
                  </span>
                  {statusData.age_seconds !== null && (
                    <span className={`ml-2 font-semibold ${
                      statusData.stale ? 'text-amber-600' : 'text-emerald-600'
                    }`}>
                      ({statusData.stale ? 'stale' : `${statusData.age_seconds}s ago`})
                    </span>
                  )}
                  {statusLastRefreshed && (
                    <span className="ml-3 text-gray-400">
                      · Admin refreshed {statusLastRefreshed.toLocaleTimeString()}
                    </span>
                  )}
                </p>
              )}
            </div>
            <button
              type="button"
              onClick={fetchSystemStatus}
              disabled={statusLoading}
              className="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 transition-all"
            >
              <RefreshCw className={`h-4 w-4 ${statusLoading ? 'animate-spin' : ''}`} />
              {statusLoading ? 'Refreshing...' : 'Refresh'}
            </button>
          </div>

          {/* Error */}
          {statusError && (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
              <div className="flex items-center gap-2 font-semibold">
                <AlertTriangle className="h-4 w-4" /> Error loading status
              </div>
              <p className="mt-1">{statusError}</p>
            </div>
          )}

          {statusData && (
            <>
              {/* Overall Banner */}
              <div
                className={`rounded-2xl border p-6 shadow-sm ${
                  !statusData.ok
                    ? 'bg-red-50 border-red-200'
                    : statusData.stale
                    ? 'bg-amber-50 border-amber-200'
                    : 'bg-emerald-50 border-emerald-200'
                }`}
              >
                <div className="flex items-center gap-4">
                  <div
                    className={`rounded-2xl p-3 ${
                      !statusData.ok
                        ? 'bg-red-100 text-red-600'
                        : statusData.stale
                        ? 'bg-amber-100 text-amber-600'
                        : 'bg-emerald-100 text-emerald-600'
                    }`}
                  >
                    {!statusData.ok ? (
                      <ShieldAlert className="h-7 w-7" />
                    ) : (
                      <ShieldCheck className="h-7 w-7" />
                    )}
                  </div>
                  <div>
                    <h2
                      className={`text-lg font-bold ${
                        !statusData.ok
                          ? 'text-red-800'
                          : statusData.stale
                          ? 'text-amber-800'
                          : 'text-emerald-800'
                      }`}
                    >
                      {!statusData.ok
                        ? '⚠️ Degraded — Cash-out is blocked'
                        : statusData.stale
                        ? '⚠️ All systems OK (cache is stale)'
                        : '✅ All Systems Operational'}
                    </h2>
                    <p
                      className={`text-sm mt-0.5 ${
                        !statusData.ok
                          ? 'text-red-700'
                          : statusData.stale
                          ? 'text-amber-700'
                          : 'text-emerald-700'
                      }`}
                    >
                      {statusData.summary || 'No summary available.'}
                    </p>
                    <div className="mt-2 flex items-center gap-3">
                      <span
                        className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${
                          statusData.gate_enabled
                            ? 'bg-blue-50 text-blue-700 border-blue-200'
                            : 'bg-gray-100 text-gray-600 border-gray-200'
                        }`}
                      >
                        <Wifi className="h-3 w-3" />
                        Health Gate {statusData.gate_enabled ? 'Enabled' : 'Disabled'}
                      </span>
                      {statusData.stale && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                          <Clock className="h-3 w-3" /> Cache Stale
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              </div>

              {/* Service Cards */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {statusData.services.map((svc: SystemStatusService) => {
                  const isOk = svc.ok === true
                  const isDown = svc.ok === false && !svc.skipped
                  const isSkipped = svc.skipped
                  const isUnknown = svc.ok === null && !svc.skipped

                  let borderCls = 'border-gray-200'
                  let badgeCls = 'bg-gray-100 text-gray-600 border-gray-200'
                  let iconCls = 'text-gray-400'
                  let label = 'Unknown'
                  let IconComp = Minus

                  if (isOk) {
                    borderCls = 'border-emerald-200'
                    badgeCls = 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    iconCls = 'text-emerald-500'
                    label = svc.informational ? 'Reachable' : 'Operational'
                    IconComp = CheckCircle2
                  } else if (isDown) {
                    borderCls = 'border-red-200'
                    badgeCls = 'bg-red-50 text-red-700 border-red-200'
                    iconCls = 'text-red-500'
                    label = 'Down'
                    IconComp = AlertTriangle
                  } else if (isSkipped) {
                    borderCls = 'border-gray-200'
                    badgeCls = 'bg-gray-50 text-gray-500 border-gray-200'
                    iconCls = 'text-gray-400'
                    label = 'Skipped'
                    IconComp = Minus
                  }

                  return (
                    <div
                      key={svc.key}
                      className={`rounded-2xl border bg-white p-5 shadow-sm ${borderCls}`}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                          <div className={`rounded-xl p-2 ${isDown ? 'bg-red-50' : isOk ? 'bg-emerald-50' : 'bg-gray-50'}`}>
                            <IconComp className={`h-5 w-5 ${iconCls}`} />
                          </div>
                          <div>
                            <h3 className="font-bold text-gray-900 text-sm">{svc.label}</h3>
                            <code className="text-[10px] text-gray-400 font-mono">{svc.key}</code>
                          </div>
                        </div>
                        <span
                          className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold shrink-0 ${badgeCls}`}
                        >
                          {isUnknown ? <Minus className="h-3 w-3" /> : <IconComp className="h-3 w-3" />}
                          {label}
                        </span>
                      </div>

                      <div className="mt-4 space-y-2 text-xs">
                        {svc.ms !== null && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Response Time</span>
                            <span
                              className={`font-mono font-semibold ${
                                svc.ms < 500 ? 'text-emerald-600' : svc.ms < 1500 ? 'text-amber-600' : 'text-red-600'
                              }`}
                            >
                              {svc.ms.toFixed(0)} ms
                            </span>
                          </div>
                        )}
                        {svc.http !== null && svc.http !== 0 && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">HTTP Status</span>
                            <span
                              className={`font-mono font-semibold ${
                                svc.http >= 200 && svc.http < 300
                                  ? 'text-emerald-600'
                                  : 'text-red-600'
                              }`}
                            >
                              {svc.http}
                            </span>
                          </div>
                        )}
                        {svc.http === 0 && svc.ok === false && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">HTTP Status</span>
                            <span className="font-mono font-semibold text-red-600">Connection Failed</span>
                          </div>
                        )}
                        {svc.error && (
                          <div className="mt-2 rounded-lg bg-red-50 border border-red-100 px-3 py-2">
                            <p className="text-red-700 font-mono text-[10px] break-all leading-relaxed">{svc.error}</p>
                          </div>
                        )}
                        {svc.note && (
                          <div className="mt-2 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p className="text-gray-500 italic text-[10px] break-all leading-relaxed">{svc.note}</p>
                          </div>
                        )}
                        {svc.informational && (
                          <p className="text-[10px] text-gray-400 italic">ⓘ Informational only — not used to block sells</p>
                        )}
                        {svc.correlationId && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Correlation ID</span>
                            <span className="font-mono text-gray-600">{svc.correlationId.slice(0, 16)}…</span>
                          </div>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            </>
          )}
        </div>
      )}

      {/* ─── TRANSACTION LOOKUP TAB ─── */}
      {activeTab === 'lookup' && (
        <div className="space-y-6">
          {/* Search Bar */}
          <form onSubmit={handleSearch} className="flex gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Phone, Centiiv Order ID, 1Click address, or Aptos / Arc / Stellar tx hash..."
                className="w-full rounded-2xl border border-gray-200 bg-white py-3.5 pl-12 pr-4 text-sm font-medium text-gray-900 placeholder:text-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 shadow-sm"
              />
            </div>
            <button
              type="submit"
              disabled={loading || !query.trim()}
              className="flex items-center gap-2 rounded-2xl bg-sendra-primary px-6 py-3.5 text-sm font-semibold text-white transition-all hover:opacity-95 disabled:opacity-50 shadow-sm"
            >
              {loading ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
              Lookup Status
            </button>
          </form>

          {/* Error state */}
          {error && (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm">
              <div className="flex items-center gap-2 font-semibold">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                Lookup Error
              </div>
              <p className="mt-1">{error}</p>
            </div>
          )}

          {/* Results view */}
          {data && (
        <div className="space-y-6">
          {/* Top Status Banner */}
          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-400">
                  Target User / Search Query
                </span>
                <div className="mt-1 flex items-center gap-2 text-lg font-bold text-gray-900 font-mono">
                  <CopyableText value={data.query} className="text-lg font-bold text-gray-900" />
                </div>
              </div>
              <div className="flex gap-4 items-center">
                {inspectingTx && (
                  <span className="flex items-center gap-1.5 text-xs text-emerald-600 font-medium bg-emerald-50 px-3 py-1 rounded-full border border-emerald-200">
                    <RefreshCw className="h-3 w-3 animate-spin" /> Updating Cards...
                  </span>
                )}
                {(data.resolvedChain || data.offrampOutcome?.offramp_path) && (
                  <div className="text-right">
                    <div className="text-xs font-medium text-gray-400">Resolved Chain</div>
                    <span
                      className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ${chainBadgeClass(
                        data.resolvedChain || undefined
                      )}`}
                    >
                      {chainLabel(
                        data.resolvedChain || undefined,
                        data.offrampOutcome?.offramp_path
                          ? data.offrampOutcome.offramp_path.replace(/_/g, ' ')
                          : undefined
                      )}
                    </span>
                  </div>
                )}
                {data.oneClick && (
                  <div className="text-right">
                    <div className="text-xs font-medium text-gray-400">1Click Swap</div>
                    <span
                      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold ${getBadgeColor(
                        data.oneClick.status
                      )}`}
                    >
                      {data.oneClick.status === 'SUCCESS' ? (
                        <CheckCircle2 className="h-3.5 w-3.5" />
                      ) : (
                        <Clock className="h-3.5 w-3.5" />
                      )}
                      {data.oneClick.status || 'UNKNOWN'}
                    </span>
                  </div>
                )}
                {data.centiiv && (
                  <div className="text-right">
                    <div className="text-xs font-medium text-gray-400">Centiiv Disbursal</div>
                    <span
                      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold ${getBadgeColor(
                        data.centiiv.status
                      )}`}
                    >
                      {data.centiiv.status === 'FULFILLED' ? (
                        <CheckCircle2 className="h-3.5 w-3.5" />
                      ) : (
                        <Clock className="h-3.5 w-3.5" />
                      )}
                      {data.centiiv.status || 'UNKNOWN'}
                    </span>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Cards Grid */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* User Account Card */}
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3 border-b border-gray-100 pb-4">
                <div className="rounded-xl bg-emerald-50 p-2.5 text-emerald-600">
                  <User className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-bold text-gray-900">User Account</h3>
                  <p className="text-xs text-gray-500">Sendra Wallet profile</p>
                </div>
              </div>
              {data.user ? (
                <div className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Name</span>
                    <CopyableText value={data.user.name} mono={false} className="font-semibold text-gray-900" />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Phone Number</span>
                    <CopyableText value={data.user.phone} className="font-medium text-gray-900" />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Aptos Address</span>
                    <CopyableText
                      value={data.user.wallets?.aptos || data.user.wallet}
                      displayValue={data.user.wallets?.aptosShort || data.user.walletShort}
                      className="text-xs font-medium text-gray-900"
                    />
                  </div>
                  {(data.user.wallets?.stellar || data.user.wallets?.arc) && (
                    <>
                      <div className="flex justify-between border-b border-gray-50 pb-2">
                        <span className="text-gray-500">Stellar Address</span>
                        <CopyableText
                          value={data.user.wallets?.stellar}
                          displayValue={data.user.wallets?.stellarShort || '—'}
                          className="text-xs font-medium text-gray-900"
                        />
                      </div>
                      <div className="flex justify-between border-b border-gray-50 pb-2">
                        <span className="text-gray-500">Arc Address</span>
                        <CopyableText
                          value={data.user.wallets?.arc}
                          displayValue={data.user.wallets?.arcShort || '—'}
                          className="text-xs font-medium text-gray-900"
                        />
                      </div>
                    </>
                  )}
                  <div className="flex justify-between">
                    <span className="text-gray-500">KYC Status</span>
                    <CopyableText
                      value={data.user.kycTier}
                      displayValue={
                        <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 uppercase">
                          {data.user.kycTier}
                        </span>
                      }
                    />
                  </div>
                </div>
              ) : (
                <div className="mt-4 text-center text-sm text-gray-400 py-4">
                  No matching user account found for this query.
                </div>
              )}
            </div>

            {/* Centiiv Order Disbursal Card */}
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3 border-b border-gray-100 pb-4">
                <div className="rounded-xl bg-blue-50 p-2.5 text-blue-600">
                  <Building2 className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-bold text-gray-900">Centiiv Disbursal Order</h3>
                  <p className="text-xs text-gray-500">Bank cashout details</p>
                </div>
              </div>
              {data.centiiv ? (
                <div className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Order ID</span>
                    <CopyableText value={data.centiiv.id} className="text-xs font-semibold text-gray-900" />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Status</span>
                    <span
                      className={`font-semibold px-2 py-0.5 rounded text-xs ${getBadgeColor(
                        data.centiiv.status
                      )}`}
                    >
                      {data.centiiv.status}
                    </span>
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Account Name</span>
                    <CopyableText
                      value={data.centiiv.beneficiary?.details?.accountName}
                      mono={false}
                      className="font-semibold text-gray-900"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Beneficiary Bank</span>
                    <CopyableText
                      value={
                        data.centiiv.beneficiary?.details?.bankName ||
                        data.centiiv.beneficiary?.details?.bankCode
                      }
                      mono={false}
                      displayValue={
                        <span className="font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded text-xs">
                          {data.centiiv.beneficiary?.details?.bankName ||
                            data.centiiv.beneficiary?.details?.bankCode ||
                            '—'}
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Account Number</span>
                    <CopyableText
                      value={data.centiiv.beneficiary?.details?.accountNumber}
                      className="text-gray-900 font-medium"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Centiiv Rate (Tx Time)</span>
                    <CopyableText
                      value={
                        data.centiiv.metadata?.rate
                          ? `1 USDT = ₦${data.centiiv.metadata.rate}`
                          : data.offrampOutcome?.centiiv_rate || '—'
                      }
                      className="font-semibold text-blue-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Fees Charged (0.5%)</span>
                    <CopyableText
                      value={data.offrampOutcome?.fees_charged || '0.50%'}
                      className="text-xs font-semibold text-blue-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Fulfillment NGN</span>
                    <CopyableText
                      value={data.centiiv.fulfillments?.[0]?.amount || data.centiiv.amount}
                      displayValue={
                        <span className="font-bold text-emerald-600">
                          ₦{data.centiiv.fulfillments?.[0]?.amount?.toLocaleString() || data.centiiv.amount || '—'}
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">Disbursal Ref</span>
                    <CopyableText
                      value={data.centiiv.fulfillments?.[0]?.disbursalRef}
                      className="text-xs text-gray-600"
                    />
                  </div>
                </div>
              ) : (
                <div className="mt-4 text-center text-sm text-gray-400 py-4">
                  No live Centiiv order record retrieved.
                </div>
              )}
            </div>

            {/* 1Click Swap Details Card */}
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3 border-b border-gray-100 pb-4">
                <div className="rounded-xl bg-purple-50 p-2.5 text-purple-600">
                  <Layers className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-bold text-gray-900">1Click Cross-Chain Swap</h3>
                  <p className="text-xs text-gray-500">
                    {data.oneClick
                      ? `Aptos USDT/USDC → ${get1ClickDestInfo(data.oneClick).label} bridge`
                      : 'Aptos USDT/USDC → Solana/Stellar USDC bridge'}
                  </p>
                </div>
              </div>
              {data.oneClick ? (
                <div className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Swap Status</span>
                    <span
                      className={`font-semibold px-2 py-0.5 rounded text-xs ${getBadgeColor(
                        data.oneClick.status
                      )}`}
                    >
                      {data.oneClick.status}
                    </span>
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Correlation / Swap ID</span>
                    <CopyableText
                      value={data.oneClick.correlationId || data.oneClick.swapId}
                      className="text-xs font-semibold text-gray-900"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Swap Timestamp</span>
                    <CopyableText
                      value={data.oneClick.updatedAt || data.oneClick.createdAt}
                      displayValue={formatIsoDate(data.oneClick.updatedAt || data.oneClick.createdAt)}
                      className="text-xs text-gray-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Conversion Rate</span>
                    <CopyableText
                      value={get1ClickRateAndFee(data.oneClick).rate}
                      className="font-semibold text-purple-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Fees Charged / Slippage</span>
                    <CopyableText
                      value={get1ClickRateAndFee(data.oneClick).fee}
                      className="text-xs font-semibold text-amber-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">1Click Deposit Address (Aptos)</span>
                    <CopyableText
                      value={
                        data.oneClick.quoteResponse?.quote?.depositAddress || data.oneClickAddress
                      }
                      displayValue={
                        data.oneClick.quoteResponse?.quote?.depositAddress
                          ? `${data.oneClick.quoteResponse.quote.depositAddress.slice(0, 8)}...${data.oneClick.quoteResponse.quote.depositAddress.slice(-6)}`
                          : data.oneClickAddress
                          ? `${data.oneClickAddress.slice(0, 8)}...${data.oneClickAddress.slice(-6)}`
                          : '—'
                      }
                      className="text-xs text-gray-900 font-semibold"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">
                      1Click Recipient Address ({get1ClickDestInfo(data.oneClick).chain})
                    </span>
                    <CopyableText
                      value={
                        data.oneClick.quoteResponse?.quoteRequest?.recipient || data.oneClick.destinationAddress
                      }
                      displayValue={
                        data.oneClick.quoteResponse?.quoteRequest?.recipient
                          ? `${data.oneClick.quoteResponse.quoteRequest.recipient.slice(0, 8)}...${data.oneClick.quoteResponse.quoteRequest.recipient.slice(-6)}`
                          : data.oneClick.destinationAddress
                          ? `${data.oneClick.destinationAddress.slice(0, 8)}...${data.oneClick.destinationAddress.slice(-6)}`
                          : '—'
                      }
                      className="text-xs text-gray-900 font-semibold"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Amount Deposited (Aptos)</span>
                    <CopyableText
                      value={
                        data.oneClick.swapDetails?.depositedAmountFormatted ||
                        (Number(data.oneClick.swapDetails?.amountIn || 0) / 1e6).toFixed(2)
                      }
                      displayValue={
                        <span className="font-bold text-gray-900">
                          {data.oneClick.swapDetails?.depositedAmountFormatted ||
                            (Number(data.oneClick.swapDetails?.amountIn || 0) / 1e6).toFixed(2)}{' '}
                          USDT/USDC
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">
                      Amount Swapped Out ({get1ClickDestInfo(data.oneClick).chain})
                    </span>
                    <CopyableText
                      value={
                        data.oneClick.swapDetails?.amountOutFormatted ||
                        (
                          Number(data.oneClick.swapDetails?.amountOut || 0) /
                          10 ** get1ClickDestInfo(data.oneClick).decimals
                        ).toFixed(4)
                      }
                      displayValue={
                        <span className="font-bold text-emerald-600">
                          {data.oneClick.swapDetails?.amountOutFormatted ||
                            (
                              Number(data.oneClick.swapDetails?.amountOut || 0) /
                              10 ** get1ClickDestInfo(data.oneClick).decimals
                            ).toFixed(4)}{' '}
                          USDC
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Aptos Origin Tx Hash</span>
                    <div className="flex items-center gap-1">
                      <CopyableText
                        value={data.oneClick.swapDetails?.originChainTxHashes?.[0]?.hash}
                        displayValue={
                          data.oneClick.swapDetails?.originChainTxHashes?.[0]?.hash
                            ? `${data.oneClick.swapDetails.originChainTxHashes[0].hash.slice(0, 8)}...`
                            : '—'
                        }
                        className="text-xs text-gray-700"
                      />
                      {data.oneClick.swapDetails?.originChainTxHashes?.[0]?.hash && (
                        <a
                          href={`https://explorer.aptoslabs.com/txn/${data.oneClick.swapDetails.originChainTxHashes[0].hash}`}
                          target="_blank"
                          rel="noreferrer"
                          className="text-emerald-600 hover:text-emerald-700"
                          title="Open in Aptos Explorer"
                        >
                          <ExternalLink className="h-3.5 w-3.5" />
                        </a>
                      )}
                    </div>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">
                      {get1ClickDestInfo(data.oneClick).chain} Dest Tx Hash
                    </span>
                    <CopyableText
                      value={data.oneClick.swapDetails?.destinationChainTxHashes?.[0]?.hash}
                      displayValue={
                        data.oneClick.swapDetails?.destinationChainTxHashes?.[0]?.hash
                          ? `${data.oneClick.swapDetails.destinationChainTxHashes[0].hash.slice(0, 8)}...`
                          : '—'
                      }
                      className="text-xs text-gray-700"
                    />
                  </div>
                </div>
              ) : (
                <div className="mt-4 text-center text-sm text-gray-400 py-4">
                  No live 1Click swap details retrieved for this lookup.
                </div>
              )}
            </div>

            {/* Settlement Audit Record Card */}
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3 border-b border-gray-100 pb-4">
                <div className="rounded-xl bg-amber-50 p-2.5 text-amber-600">
                  <ArrowUpRight className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="font-bold text-gray-900">Settlement Audit Record</h3>
                  <p className="text-xs text-gray-500">System offramp settlement metrics</p>
                </div>
              </div>
              {data.offrampOutcome ? (
                <div className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Offramp Path</span>
                    <CopyableText
                      value={data.offrampOutcome.offramp_path}
                      className="text-xs font-semibold text-gray-900 uppercase"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Centiiv Rate (Tx Time)</span>
                    <CopyableText
                      value={data.offrampOutcome.centiiv_rate || '—'}
                      className="font-semibold text-blue-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Effective Rate (Payout/Sold)</span>
                    <CopyableText
                      value={data.offrampOutcome.effective_rate || '—'}
                      className="font-semibold text-emerald-700"
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">USDT Debited</span>
                    <CopyableText
                      value={data.offrampOutcome.usdt_debit}
                      displayValue={
                        <span className="font-semibold text-gray-900">
                          {data.offrampOutcome.usdt_debit} USDT
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Quoted NGN</span>
                    <CopyableText
                      value={data.offrampOutcome.target_ngn}
                      displayValue={
                        <span className="font-semibold text-gray-900">
                          ₦{Number(data.offrampOutcome.target_ngn).toLocaleString()}
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Fulfillment NGN</span>
                    <CopyableText
                      value={data.offrampOutcome.fulfillment_ngn}
                      displayValue={
                        <span className="font-bold text-emerald-600">
                          ₦{Number(data.offrampOutcome.fulfillment_ngn).toLocaleString()}
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between border-b border-gray-50 pb-2">
                    <span className="text-gray-500">Fees Charged (0.5%)</span>
                    <CopyableText
                      value={data.offrampOutcome.fees_charged || '0.50%'}
                      displayValue={
                        <span className="font-semibold text-blue-700">
                          {data.offrampOutcome.fees_charged || '0.50%'}
                        </span>
                      }
                    />
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">Shortfall</span>
                    <CopyableText
                      value={data.offrampOutcome.shortfall || `₦${data.offrampOutcome.ngn_shortfall || '0.00'}`}
                      displayValue={
                        <span className="font-semibold text-amber-600">
                          {data.offrampOutcome.shortfall || `₦${data.offrampOutcome.ngn_shortfall || '0.00'}`}
                        </span>
                      }
                    />
                  </div>
                </div>
              ) : (
                <div className="mt-4 text-center text-sm text-gray-400 py-4">
                  No database offramp outcome row recorded yet.
                </div>
              )}
            </div>
          </div>

          {/* User Recent Transactions History Table */}
          {data.history && data.history.length > 0 && (
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
              <div className="flex flex-col gap-4 border-b border-gray-100 pb-4 mb-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                  <div className="rounded-xl bg-emerald-50 p-2.5 text-emerald-600">
                    <History className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-gray-900">User Transaction History</h3>
                    <p className="text-xs text-gray-500">
                      {filteredHistory.length} of {data.history.length} transactions
                      {chainFilter !== 'all' ? ` on ${chainLabel(chainFilter)}` : ''} · latest first. Click any row to
                      inspect cards.
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {(['all', 'aptos', 'stellar', 'arc'] as const).map((c) => (
                    <button
                      key={c}
                      type="button"
                      onClick={() => setChainFilter(c)}
                      className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
                        chainFilter === c
                          ? 'bg-emerald-600 text-white border-emerald-600'
                          : 'bg-white text-gray-600 border-gray-200 hover:border-emerald-300 hover:text-emerald-700'
                      }`}
                    >
                      {c === 'all' ? 'All chains' : chainLabel(c)}
                    </button>
                  ))}
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-xs font-semibold uppercase tracking-wider text-gray-400">
                      <th className="py-3 px-4">Transaction ID / Type</th>
                      <th className="py-3 px-4">Chain</th>
                      <th className="py-3 px-4">Amount</th>
                      <th className="py-3 px-4">Status</th>
                      <th className="py-3 px-4">Tx Hash</th>
                      <th className="py-3 px-4">Date & Time</th>
                      <th className="py-3 px-4 text-right">Card Inspection</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {filteredHistory.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="py-8 text-center text-sm text-gray-400">
                          No {chainLabel(chainFilter)} transactions in this history window.
                        </td>
                      </tr>
                    ) : (
                      filteredHistory.map((tx, idx) => {
                      const txKey = tx.txHash || tx.id
                      const isSelected = selectedTxHash && txKey && selectedTxHash === txKey
                      return (
                        <tr
                          key={tx.id || idx}
                          onClick={() => handleRowClick(tx)}
                          className={`group cursor-pointer transition-all ${
                            isSelected
                              ? 'bg-emerald-50/80 border-l-4 border-emerald-500 font-semibold shadow-inner'
                              : 'hover:bg-gray-50/80'
                          }`}
                          title="Click row to update Centiiv & chain cards for this transaction"
                        >
                          <td className="py-3.5 px-4">
                            <CopyableText
                              value={tx.id}
                              displayValue={tx.id ? (tx.id.length > 20 ? tx.id.slice(0, 16) + '...' : tx.id) : '—'}
                              className={isSelected ? 'text-emerald-900 font-bold' : 'text-gray-900'}
                            />
                            {tx.type && (
                              <div className="text-xs text-gray-500 uppercase">{tx.type}</div>
                            )}
                          </td>
                          <td className="py-3.5 px-4">
                            <span
                              className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${chainBadgeClass(
                                tx.chain
                              )}`}
                            >
                              {chainLabel(tx.chain, tx.chainLabel)}
                            </span>
                          </td>
                          <td className="py-3.5 px-4">
                            <CopyableText
                              value={tx.amount}
                              displayValue={`${tx.amount} ${tx.currency || 'USDT'}`}
                              className="font-semibold text-gray-900"
                            />
                          </td>
                          <td className="py-3.5 px-4">
                            <span
                              className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-semibold ${getBadgeColor(
                                tx.status
                              )}`}
                            >
                              {tx.status}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 font-mono text-xs text-gray-600">
                            <div className="flex items-center gap-1">
                              <CopyableText
                                value={tx.txHash}
                                displayValue={
                                  tx.txHash ? `${tx.txHash.slice(0, 8)}...${tx.txHash.slice(-6)}` : '—'
                                }
                              />
                              {tx.explorerUrl && (
                                <a
                                  href={tx.explorerUrl}
                                  target="_blank"
                                  rel="noreferrer"
                                  onClick={(e) => e.stopPropagation()}
                                  className="text-emerald-600 hover:text-emerald-700"
                                  title={`Open on ${chainLabel(tx.chain, tx.chainLabel)} explorer`}
                                >
                                  <ExternalLink className="h-3.5 w-3.5" />
                                </a>
                              )}
                            </div>
                          </td>
                          <td className="py-3.5 px-4 text-xs text-gray-500">
                            {formatIsoDate(tx.createdAt)}
                          </td>
                          <td className="py-3.5 px-4 text-right">
                            <span
                              className={`inline-flex items-center gap-1 rounded-xl px-3 py-1 text-xs font-semibold transition-colors ${
                                isSelected
                                  ? 'bg-emerald-600 text-white shadow-sm'
                                  : 'bg-gray-100 text-gray-700 group-hover:bg-emerald-100 group-hover:text-emerald-700'
                              }`}
                            >
                              <MousePointerClick className="h-3 w-3" />
                              {isSelected ? 'Selected' : 'Inspect'}
                            </span>
                          </td>
                        </tr>
                      )
                    })
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Raw JSON Debugging Accordion */}
          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <button
              type="button"
              onClick={() => setShowRawJson(!showRawJson)}
              className="flex w-full items-center justify-between text-left"
            >
              <div className="flex items-center gap-2">
                <span className="font-bold text-gray-900">Raw JSON Payload</span>
                <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                  Full API Debug View
                </span>
              </div>
              {showRawJson ? <ChevronUp className="h-5 w-5" /> : <ChevronDown className="h-5 w-5" />}
            </button>
            {showRawJson && (
              <pre className="mt-4 max-h-96 overflow-auto rounded-xl bg-gray-900 p-4 font-mono text-xs text-emerald-400">
                {JSON.stringify(data, null, 2)}
              </pre>
            )}
          </div>
        </div>
      )}
        </div>
      )}
    </div>
  )
}
