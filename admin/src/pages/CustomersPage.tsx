import { Award, Package, Users } from 'lucide-react'
import { Star } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, statusToVariant } from '../components/ui/Badge'
import { ActionMenu } from '../components/ui/ActionMenu'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { Stars } from '../components/ui/Stars'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, type CustomersResponse } from '../lib/api'

export function CustomersPage() {
  const [data, setData] = useState<CustomersResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    setLoading(true)
    api
      .customers(search || undefined)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Customers</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Customers"
          value={data.stats.totalCustomers.toLocaleString()}
          icon={Users}
        />
        <StatCard
          label="Total Orders"
          value={data.stats.totalOrders.toLocaleString()}
          icon={Package}
        />
        <StatCard label="Average rating" value={String(data.stats.averageRating)} icon={Award}>
          <div className="mt-2">
            <Stars rating={data.stats.averageRating} />
          </div>
        </StatCard>
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar
          title="All Customers"
          searchPlaceholder="Search Users"
          onSearch={setSearch}
        />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Id</th>
                <th className="px-6 py-3 font-medium">Name</th>
                <th className="px-6 py-3 font-medium">Number</th>
                <th className="px-6 py-3 font-medium">Status</th>
                <th className="px-6 py-3 font-medium">Orders</th>
                <th className="px-6 py-3 font-medium">Avg Rating</th>
                <th className="px-6 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.customers.map((row) => (
                <tr key={row.id} className="border-b border-sendra-border last:border-0">
                  <td className="px-6 py-4 text-gray-600">{row.id}</td>
                  <td className="px-6 py-4 font-medium text-gray-900">{row.name}</td>
                  <td className="px-6 py-4 text-gray-600">{row.number}</td>
                  <td className="px-6 py-4">
                    <Badge variant={statusToVariant(row.status)}>{row.status}</Badge>
                  </td>
                  <td className="px-6 py-4 text-gray-600">{row.orders}</td>
                  <td className="px-6 py-4">
                    {row.avgRating > 0 ? (
                      <span className="inline-flex items-center gap-1">
                        <Star className="h-4 w-4 fill-amber-400 text-amber-400" />
                        {row.avgRating}
                      </span>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-6 py-4">
                    <ActionMenu />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
