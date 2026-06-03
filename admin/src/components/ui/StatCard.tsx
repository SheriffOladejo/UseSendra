import type { LucideIcon } from 'lucide-react'

export function StatCard({
  label,
  value,
  trend,
  icon: Icon,
  className = '',
  labelClassName = '',
  children,
}: {
  label: string
  value: string
  trend?: string
  icon?: LucideIcon
  className?: string
  labelClassName?: string
  children?: React.ReactNode
}) {
  return (
    <div
      className={`rounded-2xl border border-sendra-border bg-white p-5 shadow-sm ${className}`}
    >
      <div className="flex items-start justify-between gap-3">
        <p className={`text-sm font-medium text-sendra-muted ${labelClassName}`}>{label}</p>
        {Icon && (
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sendra-mint text-sendra-primary">
            <Icon className="h-4 w-4" strokeWidth={2} />
          </span>
        )}
      </div>
      <p className="mt-3 text-2xl font-bold tracking-tight text-gray-900">{value}</p>
      {children}
      {trend && <p className="mt-2 text-xs font-medium text-trend">{trend}</p>}
    </div>
  )
}
