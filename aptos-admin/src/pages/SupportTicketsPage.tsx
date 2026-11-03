import { useEffect, useState } from 'react'
import { ErrorState, LoadingState } from '../components/ui/LoadingState'
import { StatCard } from '../components/ui/StatCard'
import { TableToolbar } from '../components/ui/TableToolbar'
import { api, type SupportTicketsResponse } from '../lib/api'

export function SupportTicketsPage() {
  const [data, setData] = useState<SupportTicketsResponse | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api
      .supportTickets()
      .then(setData)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingState />
  if (error || !data) return <ErrorState message={error || 'Failed to load'} />

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Support Ticket</h1>
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard label="Open" value={String(data.stats.open)} />
        <StatCard label="Resolved" value={String(data.stats.resolved)} />
        <StatCard label="Total" value={String(data.stats.total)} />
      </div>
      <section className="overflow-hidden rounded-2xl border border-sendra-border bg-white shadow-sm">
        <TableToolbar title="All tickets" searchPlaceholder="Search tickets" />
        <div className="px-6 py-12 text-center text-sm text-sendra-muted">
          Support tickets will appear here once the ticketing system is connected.
        </div>
      </section>
    </div>
  )
}
