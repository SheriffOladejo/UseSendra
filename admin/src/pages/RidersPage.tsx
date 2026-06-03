import { Award, Bike, Clock, Coins, Package } from 'lucide-react'
import { Star } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, statusToVariant } from '../components/ui/Badge'
import { ActionMenu } from '../components/ui/ActionMenu'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { Stars } from '../components/ui/Stars'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, formatNgn, type RidersResponse } from '../lib/api'

export function RidersPage() {
  const [data, setData] = useState<RidersResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    setLoading(true)
    api
      .riders(search || undefined)
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [search])

  if (loading && !data) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return null

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Riders</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Riders"
          value={data.stats.totalRiders.toLocaleString()}
          icon={Bike}
        />
        <StatCard
          label="Pending verification"
          value={data.stats.pendingVerification.toLocaleString()}
          icon={Clock}
        />
        <StatCard
          label="Total Deliveries"
          value={data.stats.totalDeliveries.toLocaleString()}
          icon={Package}
        />
      </div>
      <div className="mb-6 grid gap-4 sm:grid-cols-2">
        <StatCard
          label="Total Riders earnings"
          value={formatNgn(data.stats.totalEarningsNgn)}
          icon={Coins}
        />
        <StatCard label="Average rating" value={String(data.stats.averageRating)} icon={Award}>
          <div className="mt-2">
            <Stars rating={data.stats.averageRating} />
          </div>
        </StatCard>
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar title="All riders" searchPlaceholder="Search riders" onSearch={setSearch} />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead>
              <tr className="border-b border-sendra-border text-sendra-muted">
                <th className="px-6 py-3 font-medium">Id</th>
                <th className="px-6 py-3 font-medium">Name</th>
                <th className="px-6 py-3 font-medium">Number</th>
                <th className="px-6 py-3 font-medium">Verification</th>
                <th className="px-6 py-3 font-medium">Vehicle Details</th>
                <th className="px-6 py-3 font-medium">Location</th>
                <th className="px-6 py-3 font-medium">Trips</th>
                <th className="px-6 py-3 font-medium">Avg Rating</th>
                <th className="px-6 py-3 font-medium">Action</th>
              </tr>
            </thead>
            <tbody>
              {data.riders.map((row) => (
                <tr key={row.id} className="border-b border-sendra-border last:border-0">
                  <td className="px-6 py-4 text-gray-600">{row.id}</td>
                  <td className="px-6 py-4 font-medium text-gray-900">{row.name}</td>
                  <td className="px-6 py-4 text-gray-600">{row.number}</td>
                  <td className="px-6 py-4">
                    <Badge variant={statusToVariant(row.verification)}>{row.verification}</Badge>
                  </td>
                  <td className="px-6 py-4">
                    <p className="font-medium text-gray-900">{row.vehicle}</p>
                    <p className="text-xs text-sendra-muted">{row.vehicleDetail}</p>
                  </td>
                  <td className="px-6 py-4 text-gray-600">{row.location}</td>
                  <td className="px-6 py-4 text-gray-600">{row.trips}</td>
                  <td className="px-6 py-4">
                    {row.rating > 0 ? (
                      <span className="inline-flex items-center gap-1">
                        <Star className="h-4 w-4 fill-amber-400 text-amber-400" />
                        {row.rating}
                      </span>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-6 py-4">
                    <ActionMenu
                      items={
                        row.verification === 'Pending'
                          ? [
                              { label: 'Approve rider', onClick: () => api.verifyRider(row.id, 'approve') },
                              { label: 'Reject rider', onClick: () => api.verifyRider(row.id, 'reject') },
                            ]
                          : [{ label: 'View Profile Details' }]
                      }
                    />
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
