import { useEffect, useState } from 'react'
import { Badge, statusToVariant } from '../components/ui/Badge'
import { ActionMenu } from '../components/ui/ActionMenu'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, type OrdersResponse } from '../lib/api'

function orderStatusVariant(status: string) {
  if (status === 'In transit') return 'order-transit' as const
  if (status === 'Completed') return 'order-completed' as const
  if (status === 'Cancelled') return 'order-cancelled' as const
  return statusToVariant(status)
}

export function OrdersPage() {
  const [data, setData] = useState<OrdersResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    setLoading(true)
    api
      .orders(search || undefined)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  const { stats } = data

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Orders</h1>
      <div className="mb-4 grid gap-4 sm:grid-cols-2">
        <div className="rounded-2xl border border-sendra-border bg-white p-6 shadow-sm">
          <p className="text-sm text-sendra-muted">Total Orders</p>
          <p className="mt-2 text-3xl font-bold">{stats.total.toLocaleString()}</p>
        </div>
        <div className="rounded-2xl border border-sendra-border bg-white p-6 shadow-sm">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-sm text-sendra-muted">Completed</p>
              <p className="mt-2 text-3xl font-bold">{stats.completed.toLocaleString()}</p>
            </div>
            <Badge variant="order-completed">Completed</Badge>
          </div>
        </div>
      </div>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-2xl border border-sendra-border bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <p className="text-sm text-sendra-muted">In transit</p>
            <Badge variant="order-transit">In transit</Badge>
          </div>
          <p className="mt-2 text-2xl font-bold">{stats.inTransit}</p>
        </div>
        <div className="rounded-2xl border border-sendra-border bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <p className="text-sm text-sendra-muted">Pending</p>
            <Badge variant="order-pending">Pending</Badge>
          </div>
          <p className="mt-2 text-2xl font-bold">{stats.pending}</p>
        </div>
        <div className="rounded-2xl border border-sendra-border bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <p className="text-sm text-sendra-muted">Cancelled</p>
            <Badge variant="order-cancelled">Cancelled</Badge>
          </div>
          <p className="mt-2 text-2xl font-bold">{stats.cancelled}</p>
        </div>
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar title="Search Orders" searchPlaceholder="Search Users" onSearch={setSearch} />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[800px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Order id</th>
                <th className="px-6 py-3 font-medium">Customer</th>
                <th className="px-6 py-3 font-medium">Status</th>
                <th className="px-6 py-3 font-medium">Pickup</th>
                <th className="px-6 py-3 font-medium">Drop-off</th>
                <th className="px-6 py-3 font-medium">Amount</th>
                <th className="px-6 py-3 font-medium">Created at</th>
                <th className="px-6 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.orders.map((row, i) => (
                <tr
                  key={row.orderId}
                  className={`border-b border-sendra-border last:border-0 ${
                    i % 2 === 1 ? 'bg-gray-50/60' : ''
                  }`}
                >
                  <td className="px-6 py-4 font-medium text-gray-900">{row.id}</td>
                  <td className="px-6 py-4 text-gray-600">{row.customer}</td>
                  <td className="px-6 py-4">
                    <Badge variant={orderStatusVariant(row.status)}>{row.status}</Badge>
                  </td>
                  <td className="max-w-[140px] truncate px-6 py-4 text-gray-600">{row.pickup}</td>
                  <td className="max-w-[140px] truncate px-6 py-4 text-gray-600">{row.dropoff}</td>
                  <td className="px-6 py-4 font-medium text-gray-900">{row.amount}</td>
                  <td className="px-6 py-4 text-gray-600">{row.createdAt}</td>
                  <td className="px-6 py-4">
                    <ActionMenu items={[{ label: 'View order' }]} />
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
