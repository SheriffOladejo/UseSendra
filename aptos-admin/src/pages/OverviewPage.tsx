import {
  ArrowDownLeft,
  ArrowLeftRight,
  ArrowUpRight,
  Check,
  Coins,
  HandCoins,
  Headphones,
  Package,
  Ticket,
  UserRound,
  Wallet,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { StatCard } from '../components/ui/StatCard'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { api, formatUsd, type OverviewResponse } from '../lib/api'

const activityIcons: Record<string, typeof Check> = {
  onramp: Check,
  offramp: Package,
  send: HandCoins,
  receive: UserRound,
  swap: ArrowLeftRight,
  ticket: Headphones,
}

function SuccessRate({ rate }: { rate: number }) {
  return (
    <p className="mt-2 text-right text-xs font-medium text-sendra-muted">
      success rate {rate}%
    </p>
  )
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
          label="Total Volume Processed"
          value={formatUsd(stats.totalVolumeUsd)}
          icon={Wallet}
        />
        <StatCard label="Inflow" value={formatUsd(stats.inflowUsd)} icon={ArrowDownLeft}>
          <SuccessRate rate={stats.inflowSuccessRate} />
        </StatCard>
        <StatCard label="Outflow" value={formatUsd(stats.outflowUsd)} icon={ArrowUpRight}>
          <SuccessRate rate={stats.outflowSuccessRate} />
        </StatCard>
        <StatCard label="Revenue" value={formatUsd(stats.revenueUsd)} icon={Coins} />
        <StatCard
          label="Total transactions"
          value={stats.totalTransactions.toLocaleString()}
          icon={ArrowLeftRight}
        />
        <StatCard
          label="Vouchers"
          value={`${stats.vouchersClaimed}/${stats.vouchersTotal}`}
          icon={Ticket}
        >
          <SuccessRate rate={stats.voucherSuccessRate} />
        </StatCard>
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
