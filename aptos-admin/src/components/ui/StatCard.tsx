import { useEffect, useId, useRef, useState } from 'react'
import type { LucideIcon } from 'lucide-react'

export function StatCard({
  label,
  value,
  trend,
  icon: Icon,
  hint,
  className = '',
  labelClassName = '',
  children,
}: {
  label: string
  value: string
  trend?: string
  icon?: LucideIcon
  hint?: string
  className?: string
  labelClassName?: string
  children?: React.ReactNode
}) {
  const [open, setOpen] = useState(false)
  const popRef = useRef<HTMLDivElement>(null)
  const hintId = useId()

  useEffect(() => {
    if (!open) return
    const onPointer = (event: MouseEvent) => {
      if (!popRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div
      className={`rounded-2xl border border-sendra-border bg-white p-5 shadow-sm ${className}`}
    >
      <div className="flex items-start justify-between gap-3">
        <p className={`text-sm font-medium text-sendra-muted ${labelClassName}`}>{label}</p>
        {Icon && (
          <div className="relative" ref={popRef}>
            {hint ? (
              <button
                type="button"
                aria-expanded={open}
                aria-controls={hintId}
                aria-label={`About ${label}`}
                onClick={() => setOpen((current) => !current)}
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sendra-mint text-sendra-primary"
              >
                <Icon className="h-4 w-4" strokeWidth={2} />
              </button>
            ) : (
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sendra-mint text-sendra-primary">
                <Icon className="h-4 w-4" strokeWidth={2} />
              </span>
            )}
            {open && hint && (
              <div
                id={hintId}
                role="note"
                className="absolute right-0 top-11 z-20 w-64 rounded-xl border border-sendra-border bg-white p-3 text-sm leading-5 text-gray-700 shadow-lg"
              >
                {hint}
              </div>
            )}
          </div>
        )}
      </div>
      <p className="mt-3 text-2xl font-bold tracking-tight text-gray-900">{value}</p>
      {children}
      {trend && <p className="mt-2 text-xs font-medium text-trend">{trend}</p>}
    </div>
  )
}
