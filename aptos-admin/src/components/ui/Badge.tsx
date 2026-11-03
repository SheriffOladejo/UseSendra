import type { LucideIcon } from 'lucide-react'

type Variant =
  | 'tier0'
  | 'tier1'
  | 'tier2'
  | 'tier3'
  | 'inactive'
  | 'success'
  | 'pending'
  | 'failed'
  | 'inflow'
  | 'outflow'

const styles: Record<Variant, string> = {
  tier0: 'bg-gray-50 text-gray-700',
  tier1: 'bg-sky-50 text-sky-700',
  tier2: 'bg-pink-50 text-pink-700',
  tier3: 'bg-amber-50 text-amber-800',
  inactive: 'bg-gray-100 text-gray-600',
  success: 'bg-emerald-50 text-emerald-700',
  pending: 'bg-orange-50 text-orange-700',
  failed: 'bg-red-50 text-red-600',
  inflow: 'bg-emerald-50 text-emerald-700',
  outflow: 'bg-red-50 text-red-600',
}

export function kycToVariant(status: string): Variant {
  const map: Record<string, Variant> = {
    'Tier 0': 'tier0',
    'Tier 1': 'tier1',
    'Tier 2': 'tier2',
    'Tier 3': 'tier3',
    Inactive: 'inactive',
  }
  return map[status] ?? 'inactive'
}

export function txStatusToVariant(status: string): Variant {
  const map: Record<string, Variant> = {
    Success: 'success',
    Pending: 'pending',
    Failed: 'failed',
  }
  return map[status] ?? 'pending'
}

export function txTypeToVariant(typeKey: string): Variant {
  if (['on_ramp', 'deposit'].includes(typeKey)) return 'inflow'
  return 'outflow'
}

export function Badge({ children, variant }: { children: React.ReactNode; variant: Variant }) {
  return (
    <span className={`inline-flex rounded-md px-2.5 py-0.5 text-xs font-medium ${styles[variant]}`}>
      {children}
    </span>
  )
}

export function TxTypeBadge({
  label,
  typeKey,
  icon: Icon,
}: {
  label: string
  typeKey: string
  icon: LucideIcon
}) {
  const variant = txTypeToVariant(typeKey)
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-medium ${styles[variant]}`}
    >
      <Icon className="h-3.5 w-3.5" />
      {label}
    </span>
  )
}
