import {
  Bike,
  Check,
  Clock,
  FileText,
  Package,
  Shield,
  Trophy,
  Truck,
  User,
  Users,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { StatCard } from '../components/ui/StatCard'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { api, formatNgn, type OverviewResponse } from '../lib/api'

const activityIcons: Record<string, typeof Check> = {
  delivery_completed: Check,
  new_delivery_request: Package,
  delivery_picked_up: Truck,
  new_user_signup: User,
  driver_verification: Bike,
  delivery_request_accepted: FileText,
}

export function OverviewPage() {
  const [data, setData] = useState<OverviewResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api
      .overview()
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingState />
  if (error || !data) return <ErrorState message={error || 'Failed to load'} />

  const { stats, recentActivities } = data

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Overview</h1>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard
          label="Total Users"
          value={stats.totalUsers.toLocaleString()}
          icon={Users}
        />
        <StatCard
          label="Active riders"
          value={`${stats.activeRiders}/${stats.totalRiders}`}
          icon={Bike}
        />
        <StatCard
          label="Pending verification"
          value={stats.pendingVerification.toLocaleString()}
          icon={Clock}
        />
        <StatCard
          label="Revenue"
          value={formatNgn(stats.revenueNgn)}
          icon={Shield}
        />
        <StatCard
          label="Total orders"
          value={stats.totalOrders.toLocaleString()}
          icon={Package}
        />
        <StatCard
          label="success rate"
          value={`${stats.successRate}%`}
          icon={Trophy}
        />
      </div>
      <section className="mt-6 rounded-2xl border border-sendra-border bg-white shadow-sm">
        <h2 className="border-b border-sendra-border px-6 py-5 text-lg font-semibold">
          Recent Activities
        </h2>
        {recentActivities.length === 0 ? (
          <p className="px-6 py-8 text-sm text-sendra-muted">No recent activity yet.</p>
        ) : (
          <ul className="divide-y divide-sendra-border">
            {recentActivities.map((activity, i) => {
              const Icon = activityIcons[activity.kind] ?? Package
              return (
                <li key={i} className="flex items-start gap-4 px-6 py-4 sm:items-center">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sendra-mint text-sendra-primary">
                    <Icon className="h-5 w-5" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold text-gray-900">{activity.title}</p>
                    <p className="text-sm text-sendra-muted">{activity.meta}</p>
                  </div>
                  <time className="shrink-0 text-sm text-sendra-muted">{activity.time}</time>
                </li>
              )
            })}
          </ul>
        )}
      </section>
    </div>
  )
}
