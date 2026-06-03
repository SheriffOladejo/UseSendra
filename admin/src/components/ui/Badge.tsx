type Variant =
  | 'verified'
  | 'pending'
  | 'rejected'
  | 'active'
  | 'inactive'
  | 'order-pending'
  | 'order-transit'
  | 'order-completed'
  | 'order-cancelled'
  | 'in'
  | 'out'

const styles: Record<Variant, string> = {
  verified: 'bg-emerald-50 text-emerald-700',
  pending: 'bg-amber-50 text-amber-700',
  rejected: 'bg-red-50 text-red-600',
  active: 'bg-emerald-50 text-emerald-700',
  inactive: 'bg-gray-100 text-gray-600',
  'order-pending': 'bg-amber-50 text-amber-700',
  'order-transit': 'bg-violet-50 text-violet-700',
  'order-completed': 'bg-emerald-50 text-emerald-700',
  'order-cancelled': 'bg-red-50 text-red-600',
  in: 'bg-emerald-50 text-emerald-700',
  out: 'bg-red-50 text-red-600',
}

export function statusToVariant(status: string): Variant {
  const map: Record<string, Variant> = {
    Verified: 'verified',
    Pending: 'pending',
    Rejected: 'rejected',
    Active: 'active',
    Inactive: 'inactive',
    'In transit': 'order-transit',
    Completed: 'order-completed',
    Cancelled: 'order-cancelled',
    In: 'in',
    Out: 'out',
  }
  return map[status] ?? 'pending'
}

export function Badge({ children, variant }: { children: React.ReactNode; variant: Variant }) {
  return (
    <span className={`inline-flex rounded-md px-2.5 py-0.5 text-xs font-medium ${styles[variant]}`}>
      {children}
    </span>
  )
}
