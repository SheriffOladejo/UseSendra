import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight, ExternalLink } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, TxTypeBadge, txStatusToVariant } from '../components/ui/Badge'
import { CopyButton } from '../components/ui/CopyButton'
import { TxFilterMenu, type TxFilter } from '../components/ui/FilterMenu'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, formatTrend, type TransactionsResponse } from '../lib/api'

function SuccessRate({ rate }: { rate: number }) {
  return (
    <p className="text-right text-xs font-medium text-sendra-muted">success rate {rate}%</p>
  )
}

function typeIcon(typeKey: string) {
  if (['on_ramp', 'deposit'].includes(typeKey)) return ArrowDownLeft
  return ArrowUpRight
}

export function TransactionsPage() {
  const [data, setData] = useState<TransactionsResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState<TxFilter>('all')
  const [page, setPage] = useState(1)
  const pageSize = 50

  useEffect(() => {
    setLoading(true)
    api
      .transactions(search || undefined, (page - 1) * pageSize, typeFilter)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search, page, typeFilter])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  const { stats } = data

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Transactions</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Transactions"
          value={stats.totalTransactions.toLocaleString()}
          trend={`${formatTrend(26)} 24hrs`}
          icon={ArrowLeftRight}
        />
        <StatCard label="In" value={stats.inCount.toLocaleString()} icon={ArrowDownLeft}>
          <SuccessRate rate={stats.inflowSuccessRate} />
        </StatCard>
        <StatCard label="Outflow" value={stats.outCount.toLocaleString()} icon={ArrowUpRight}>
          <SuccessRate rate={stats.outflowSuccessRate} />
        </StatCard>
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar
          title="History"
          searchPlaceholder="Search Transactions"
          onSearch={(q) => {
            setPage(1)
            setSearch(q)
          }}
          filterMenu={(onClose) => (
            <TxFilterMenu
              selected={typeFilter}
              onSelect={(v) => {
                setPage(1)
                setTypeFilter(v)
              }}
              onClose={onClose}
            />
          )}
        />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1000px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Timestamp</th>
                <th className="px-6 py-3 font-medium">Id</th>
                <th className="px-6 py-3 font-medium">Number</th>
                <th className="px-6 py-3 font-medium">Type</th>
                <th className="px-6 py-3 font-medium">Amount</th>
                <th className="px-6 py-3 font-medium">Currency</th>
                <th className="px-6 py-3 font-medium">Status</th>
                <th className="px-6 py-3 font-medium">From / To</th>
                <th className="px-6 py-3 font-medium">Tx</th>
              </tr>
            </thead>
            <tbody>
              {data.transactions.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-6 py-8 text-center text-sendra-muted">
                    No transactions yet.
                  </td>
                </tr>
              ) : (
                data.transactions.map((row, i) => (
                  <tr
                    key={`${row.id}-${i}`}
                    className={`border-b border-sendra-border last:border-0 ${
                      i % 2 === 1 ? 'bg-gray-50/60' : ''
                    }`}
                  >
                    <td className="px-6 py-4 text-gray-600">{row.timestamp}</td>
                    <td className="px-6 py-4 font-mono text-gray-600">{row.id}</td>
                    <td className="px-6 py-4 text-gray-600">{row.number}</td>
                    <td className="px-6 py-4">
                      <TxTypeBadge
                        label={row.type}
                        typeKey={row.typeKey}
                        icon={typeIcon(row.typeKey)}
                      />
                    </td>
                    <td className="px-6 py-4 font-medium text-gray-900">{row.amount}</td>
                    <td className="px-6 py-4 text-gray-600">{row.currency}</td>
                    <td className="px-6 py-4">
                      <Badge variant={txStatusToVariant(row.status)}>{row.status}</Badge>
                    </td>
                    <td className="px-6 py-4 font-mono text-xs text-gray-600">
                      <span className="inline-flex items-center">
                        {row.from}
                        <CopyButton value={row.from} />
                      </span>
                      <span className="mx-1 text-gray-400">/</span>
                      <span className="inline-flex items-center">
                        {row.to}
                        <CopyButton value={row.to} />
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      {row.tx ? (
                        <a
                          href={row.tx}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center justify-center rounded-lg border border-sendra-border p-1.5 text-sendra-primary hover:bg-sendra-mint"
                          aria-label="View transaction"
                        >
                          <ExternalLink className="h-4 w-4" />
                        </a>
                      ) : (
                        '—'
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {data.pagination.totalPages > 1 && (
          <div className="flex items-center justify-between border-t border-sendra-border px-6 py-4">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
              className="rounded-lg border border-sendra-border px-3 py-1.5 text-sm disabled:opacity-40"
            >
              Previous
            </button>
            <span className="text-sm text-sendra-muted">
              Page {data.pagination.page} of {data.pagination.totalPages}
            </span>
            <button
              type="button"
              disabled={page >= data.pagination.totalPages}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-lg border border-sendra-border px-3 py-1.5 text-sm disabled:opacity-40"
            >
              Next
            </button>
          </div>
        )}
      </section>
    </div>
  )
}
