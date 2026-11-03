import { ArrowDown, ArrowLeftRight, ArrowUp } from 'lucide-react'
import { useEffect, useState } from 'react'
import { ActivityBarChart, UsageTrendsChart } from '../components/ui/Charts'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, formatTrend, type AnalyticsResponse } from '../lib/api'

export function AnalyticsPage() {
  const [data, setData] = useState<AnalyticsResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api
      .analytics()
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingState />
  if (error || !data) return <ErrorState message={error || 'Failed to load'} />

  const { stats, usageTrends, activityDistribution } = data

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Analytics</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard
          label="New users (today)"
          value={stats.newUsersToday.toLocaleString()}
          trend={`${formatTrend(stats.newUsersTrendPct)} this week`}
          icon={ArrowDown}
        />
        <StatCard
          label="Activation rate"
          value={`${stats.activationRate}%`}
          trend={`${formatTrend(stats.activationTrendPct)} this week`}
          icon={ArrowUp}
        />
        <StatCard
          label="Daily active users"
          value={stats.dailyActiveUsers.toLocaleString()}
          trend={`${formatTrend(stats.dauTrendPct)} 24hrs`}
          icon={ArrowLeftRight}
        />
      </div>

      <section className="mb-6 overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar title="Usage trends" searchPlaceholder="Search Transactions" />
        <div className="px-4 pb-6 pt-2">
          <UsageTrendsChart data={usageTrends} />
        </div>
      </section>

      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar title="Activity distribution" searchPlaceholder="Search Transactions" />
        <ActivityBarChart data={activityDistribution} />
      </section>
    </div>
  )
}
