import { ShieldAlert } from 'lucide-react'
import { Fragment, useEffect, useState } from 'react'
import { Badge } from '../components/ui/Badge'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { api, type MonitorItem, type MonitorParty, type MonitoringResponse } from '../lib/api'

const bands = [
  { id: 'attention', label: 'Score 6+' },
  { id: 'scuml', label: 'SCUML' },
  { id: 'unreported', label: 'Not filed' },
  { id: 'all', label: 'All scored' },
] as const

function money(value: number | null | undefined, currency?: string | null) {
  if (value == null) return '—'
  const formatted = value.toLocaleString('en-US', { maximumFractionDigits: 4 })
  return currency ? `${formatted} ${currency}` : formatted
}

function PartyBlock({ title, party }: { title: string; party: MonitorParty }) {
  return (
    <div>
      <h3 className="mb-2 text-sm font-semibold text-gray-900">{title}</h3>
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-sendra-muted">Account name</dt>
          <dd>{party.account_name || '—'}</dd>
        </div>
        <div>
          <dt className="text-sendra-muted">Account number</dt>
          <dd className="font-mono">{party.account_number || '—'}</dd>
        </div>
        <div>
          <dt className="text-sendra-muted">Bank</dt>
          <dd>{party.bank_name || '—'}</dd>
        </div>
        <div>
          <dt className="text-sendra-muted">Fiat amount</dt>
          <dd>{money(party.fiat_amount, party.fiat_currency)}</dd>
        </div>
        <div>
          <dt className="text-sendra-muted">Crypto amount</dt>
          <dd>{party.receive_display || money(party.crypto_amount, party.crypto_currency)}</dd>
        </div>
        <div>
          <dt className="text-sendra-muted">Conversion rate</dt>
          <dd>
            {party.conversion_rate != null
              ? `${party.conversion_rate} ${party.conversion_rate_label ?? ''}`
              : '—'}
          </dd>
        </div>
      </dl>
    </div>
  )
}

function Detail({ item, onReported }: { item: MonitorItem; onReported: (id: number) => void }) {
  const chain = item.details.onchain
  const [saving, setSaving] = useState(false)

  return (
    <div className="space-y-5 border-t border-sendra-border bg-gray-50 px-6 py-5">
      {item.reasons.length > 0 && (
        <ul className="list-disc space-y-1 pl-5 text-sm text-gray-700">
          {item.reasons.map((reason) => (
            <li key={reason}>{reason}</li>
          ))}
        </ul>
      )}
      {item.details.offramp && <PartyBlock title="Off-ramp" party={item.details.offramp} />}
      {item.details.onramp && <PartyBlock title="On-ramp" party={item.details.onramp} />}
      {chain && (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-gray-900">On-chain</h3>
          <dl className="grid gap-2 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-sendra-muted">Network</dt>
              <dd className="capitalize">{chain.network || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">Amount</dt>
              <dd>{money(chain.amount, chain.currency)}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-sendra-muted">Source</dt>
              <dd className="break-all font-mono text-xs">{chain.source_address || '—'}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-sendra-muted">Destination</dt>
              <dd className="break-all font-mono text-xs">{chain.destination_address || '—'}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-sendra-muted">Transaction</dt>
              <dd className="break-all font-mono text-xs">
                {chain.explorer_url ? (
                  <a href={chain.explorer_url} target="_blank" rel="noreferrer" className="text-sendra-primary">
                    {chain.tx_hash}
                  </a>
                ) : (
                  chain.tx_hash || '—'
                )}
              </dd>
            </div>
          </dl>
        </div>
      )}
      {item.kyc && (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-gray-900">Verified identity</h3>
          <dl className="grid gap-2 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-sendra-muted">Name</dt>
              <dd>{item.kyc.ninName || item.kyc.fullName || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">BVN name</dt>
              <dd>{item.kyc.bvnName || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">NIN phone</dt>
              <dd>{item.kyc.ninPhone || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">BVN phone</dt>
              <dd>{item.kyc.bvnPhone || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">NIN</dt>
              <dd className="font-mono">{item.kyc.ninMask || '—'}</dd>
            </div>
            <div>
              <dt className="text-sendra-muted">BVN</dt>
              <dd className="font-mono">{item.kyc.bvnMask || '—'}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-sendra-muted">Address</dt>
              <dd>{item.kyc.address || '—'}</dd>
            </div>
          </dl>
        </div>
      )}
      {item.scumlReportable && !item.scumlReportedAt && (
        <button
          type="button"
          disabled={saving}
          onClick={() => {
            setSaving(true)
            api
              .markScumlReported(item.id)
              .then(() => onReported(item.id))
              .finally(() => setSaving(false))
          }}
          className="rounded-lg bg-sendra-primary px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
        >
          {saving ? 'Saving…' : 'Mark filed on SCUML'}
        </button>
      )}
      {item.scumlReportedAt && (
        <p className="text-sm text-emerald-700">Filed on SCUML {item.scumlReportedAt}</p>
      )}
    </div>
  )
}

export function MonitoringPage() {
  const [data, setData] = useState<MonitoringResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [band, setBand] = useState<(typeof bands)[number]['id']>('attention')
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)

  useEffect(() => {
    setLoading(true)
    api
      .monitoring(band, search || undefined)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [band, search])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Monitoring</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Score 6 or higher"
          value={data.stats.attention.toLocaleString()}
          icon={ShieldAlert}
          hint="Transactions scored 6 or above. Scores 6 to 8 are for management review. Scores 9 and 10 are for management and an NFIU report. The Score 6+ tab lists these rows."
        />
        <StatCard
          label="SCUML reportable"
          value={data.stats.scuml.toLocaleString()}
          icon={ShieldAlert}
          hint="Transactions to file on the SCUML dashboard: above 10,000 USD or 10,000 USDC, above ₦5,000,000 for an individual, or above ₦10,000,000 for a business. The SCUML tab lists these rows."
        />
        <StatCard
          label="Not filed on SCUML"
          value={data.stats.scumlOpen.toLocaleString()}
          icon={ShieldAlert}
          hint="SCUML-reportable transactions that have not been marked as filed. The Not filed tab lists these rows."
        />
      </div>
      {(data.holds ?? []).length > 0 && (
        <section className="mb-6 overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm">
          <div className="border-b border-amber-100 px-6 py-4">
            <h2 className="text-sm font-semibold text-gray-900">Paused for review</h2>
            <p className="text-sm text-sendra-muted">These accounts resume on their own when the hold ends.</p>
          </div>
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Phone</th>
                <th className="px-6 py-3 font-medium">Why</th>
                <th className="px-6 py-3 font-medium">Resumes</th>
              </tr>
            </thead>
            <tbody>
              {(data.holds ?? []).map((hold) => (
                <tr key={`${hold.phone}-${hold.code}`} className="border-b border-sendra-border">
                  <td className="px-6 py-3">{hold.phone}</td>
                  <td className="px-6 py-3">{hold.reason}</td>
                  <td className="px-6 py-3">{hold.endsAt}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
      {data.recipients.length === 0 && (
        <p className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          No alert emails are set. Add them under Settings, or set SENDRA_RISK_ALERT_EMAILS.
        </p>
      )}
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-sendra-border px-6 py-4">
          <div className="flex gap-2">
            {bands.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setBand(item.id)}
                className={`rounded-lg px-3 py-1.5 text-sm ${
                  band === item.id ? 'bg-sendra-mint text-sendra-primary' : 'text-gray-600 hover:bg-gray-100'
                }`}
              >
                {item.label}
              </button>
            ))}
          </div>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search phone or account"
            className="rounded-lg border border-sendra-border px-3 py-1.5 text-sm"
          />
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">When</th>
                <th className="px-6 py-3 font-medium">Phone</th>
                <th className="px-6 py-3 font-medium">Type</th>
                <th className="px-6 py-3 font-medium">Amount</th>
                <th className="px-6 py-3 font-medium">USD</th>
                <th className="px-6 py-3 font-medium">Score</th>
                <th className="px-6 py-3 font-medium">Filing</th>
              </tr>
            </thead>
            <tbody>
              {data.items.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-8 text-center text-sendra-muted">
                    Nothing in this view.
                  </td>
                </tr>
              ) : (
                data.items.map((row) => (
                  <Fragment key={row.id}>
                    <tr
                      className="cursor-pointer border-b border-sendra-border hover:bg-gray-50"
                      onClick={() => setOpenId(openId === row.id ? null : row.id)}
                    >
                      <td className="px-6 py-4 text-gray-600">{row.occurredAt}</td>
                      <td className="px-6 py-4">{row.phone}</td>
                      <td className="px-6 py-4 capitalize">{row.activityType.replaceAll('_', ' ')}</td>
                      <td className="px-6 py-4">
                        {row.amount} {row.currency}
                      </td>
                      <td className="px-6 py-4">{row.usdEquivalent.toLocaleString()}</td>
                      <td className="px-6 py-4 font-semibold">{row.riskScore}</td>
                      <td className="px-6 py-4">
                        {row.scumlReportable ? (
                          <Badge variant={row.scumlReportedAt ? 'success' : 'failed'}>
                            {row.scumlReportedAt ? 'Filed' : 'Report to SCUML'}
                          </Badge>
                        ) : (
                          '—'
                        )}
                      </td>
                    </tr>
                    {openId === row.id && (
                      <tr>
                        <td colSpan={7} className="p-0">
                          <Detail
                            item={row}
                            onReported={(id) =>
                              setData((current) =>
                                current
                                  ? {
                                      ...current,
                                      items: current.items.map((item) =>
                                        item.id === id
                                          ? { ...item, scumlReportedAt: new Date().toISOString() }
                                          : item,
                                      ),
                                    }
                                  : current,
                              )
                            }
                          />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
