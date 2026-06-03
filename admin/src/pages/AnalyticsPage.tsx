import { ExternalLink, Package } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, statusToVariant } from '../components/ui/Badge'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { Pagination } from '../components/ui/Pagination'
import { StatCard } from '../components/ui/StatCard'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, formatNgn, type AnalyticsResponse } from '../lib/api'

export function AnalyticsPage() {
  const [data, setData] = useState<AnalyticsResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const pageSize = 50

  useEffect(() => {
    setLoading(true)
    api
      .analytics(search || undefined, (page - 1) * pageSize)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search, page])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Revenue</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total inflow"
          value={formatNgn(data.stats.totalInflowNgn)}
          labelClassName="text-emerald-600"
          icon={Package}
        />
        <StatCard
          label="Total outflow"
          value={formatNgn(data.stats.totalOutflowNgn)}
          labelClassName="text-red-400"
          icon={Package}
        />
        <StatCard label="Balance" value={formatNgn(data.stats.balanceNgn)} icon={Package} />
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar
          title="History"
          searchPlaceholder="Search Transactions"
          onSearch={(q) => {
            setPage(1)
            setSearch(q)
          }}
        />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Id</th>
                <th className="px-6 py-3 font-medium">Amount</th>
                <th className="px-6 py-3 font-medium">Time</th>
                <th className="px-6 py-3 font-medium">Type</th>
                <th className="px-6 py-3 font-medium">Tx</th>
              </tr>
            </thead>
            <tbody>
              {data.transactions.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-sendra-muted">
                    No transactions yet.
                  </td>
                </tr>
              ) : (
                data.transactions.map((row) => (
                  <tr key={row.id} className="border-b border-sendra-border last:border-0">
                    <td className="px-6 py-4 font-mono text-gray-600">{row.id}</td>
                    <td className="px-6 py-4 font-medium text-gray-900">{row.amount}</td>
                    <td className="px-6 py-4 text-gray-600">{row.time}</td>
                    <td className="px-6 py-4">
                      <Badge variant={statusToVariant(row.type)}>{row.type}</Badge>
                    </td>
                    <td className="px-6 py-4">
                      {row.tx ? (
                        <a
                          href={row.tx}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex max-w-[200px] items-center gap-1 truncate text-sendra-primary hover:underline"
                        >
                          {row.tx}
                          <ExternalLink className="h-3.5 w-3.5 shrink-0" />
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
        <Pagination
          page={data.pagination.page}
          total={data.pagination.totalPages}
          onPageChange={setPage}
        />
      </section>
    </div>
  )
}
